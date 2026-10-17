<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * Universal media field for Craft CMS — HLS, YouTube, Vimeo, Spotify, MP4
 * and audio as first-class assets, with Media Chrome compatible player rendering.
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\controllers;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\Mux;
use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Receives Mux and Bunny Stream webhook deliveries and applies video state.
 *
 * Signature-checking shells around the shared state pipelines
 * ({@see \boccdotdev\polymedia\services\MediaItems::applyMuxAssetState()} and
 * {@see \boccdotdev\polymedia\services\MediaItems::applyBunnyVideoState()}) that the
 * CP import/upload flows and console sync commands also use, so webhook
 * configuration is optional and polling or sync remains the fallback.
 *
 * Each endpoint answers only when its provider is enabled and its key is
 * configured. Mux deliveries must carry a valid `Mux-Signature` header; Bunny
 * deliveries a valid `X-BunnyStream-Signature` (HMAC-SHA256 of the raw body).
 *
 * @author boccdotdev
 * @since 2.2.0
 */
class WebhooksController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected array|bool|int $allowAnonymous = ['mux', 'bunny'];

    /**
     * @inheritdoc
     */
    public $enableCsrfValidation = false;

    // Public Methods
    // =========================================================================

    /**
     * Handles a Mux webhook delivery.
     *
     * Applies `video.asset.*` events (ready, errored, updated, deleted) to the
     * matching media item. Static rendition events refresh the asset from the
     * API, since their payload describes a single file. Events for unknown assets and unhandled event types
     * are acknowledged with 200 so Mux does not re-deliver them.
     *
     * @return Response
     * @throws NotFoundHttpException when no webhook secret is configured
     * @throws BadRequestHttpException on missing/invalid signature or body
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function actionMux(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $mux = $plugin->getMux();
        $secret = $mux->getWebhookSecret();

        if ($secret === '') {
            // No secret, no endpoint — never accept unsigned payloads.
            throw new NotFoundHttpException();
        }

        $request = Craft::$app->getRequest();
        $payload = (string)$request->getRawBody();
        $signature = (string)$request->getHeaders()->get('Mux-Signature', '');

        if (!Mux::verifyWebhookSignature($payload, $signature, $secret)) {
            Craft::warning('Rejected Mux webhook delivery: invalid or stale signature.', __METHOD__);
            throw new BadRequestHttpException('Invalid signature.');
        }

        $event = Json::decodeIfJson($payload);

        if (!is_array($event) || !isset($event['type'])) {
            throw new BadRequestHttpException('Invalid payload.');
        }

        $type = (string)$event['type'];
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];

        if (!str_starts_with($type, 'video.asset.')) {
            // Not an asset event (upload/live-stream/…) — acknowledge and skip.
            return $this->asJson(['ok' => true, 'handled' => false]);
        }

        $state = Mux::mapWebhookAssetData($data);

        if (self::isRenditionEvent($type)) {
            // Rendition payloads describe one file, so refresh the whole asset.
            $assetId = self::renditionAssetId($type, $event);

            if ($assetId === '' || !$plugin->getMediaItems()->getByMuxAssetId($assetId)) {
                return $this->asJson(['ok' => true, 'handled' => false]);
            }

            try {
                $state = $mux->getAsset($assetId);
            } catch (\Throwable $e) {
                Craft::error("Mux rendition refresh failed: {$e->getMessage()}", __METHOD__);
                Craft::$app->getResponse()->setStatusCode(503);

                return $this->asJson(['ok' => false, 'handled' => false]);
            }
        }

        if ($state['assetId'] === '') {
            return $this->asJson(['ok' => true, 'handled' => false]);
        }

        if ($type === 'video.asset.deleted') {
            $state['status'] = 'deleted';
        }

        // The library changed (or an asset's state did) — keep browse/search fresh.
        $mux->invalidateLibrarySnapshot();

        try {
            $record = $plugin->getMediaItems()->applyMuxAssetState($state['assetId'], $state);
        } catch (\Throwable $e) {
            Craft::error("Mux webhook could not be applied: {$e->getMessage()}", __METHOD__);
            Craft::$app->getResponse()->setStatusCode(503);

            return $this->asJson(['ok' => false, 'handled' => false]);
        }

        if ($record) {
            Craft::info(
                "Applied Mux webhook {$type} to media item #{$record->id} (status: {$state['status']}).",
                __METHOD__,
            );
        }

        return $this->asJson(['ok' => true, 'handled' => $record !== null]);
    }

    /**
     * Handles a Bunny Stream webhook delivery.
     *
     * The payload only says which video changed; its status code is a
     * notification enum, not video state. A valid delivery for an imported
     * video refreshes it from the Stream API through
     * {@see \boccdotdev\polymedia\services\BunnySync::syncVideo()}. Unknown
     * videos and other libraries are acknowledged and ignored; transient
     * sync failures answer 503 so Bunny retries.
     *
     * @return Response
     * @throws NotFoundHttpException when no verification key is configured
     * @throws BadRequestHttpException when the request isn't a POST
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionBunny(): Response
    {
        $this->requirePostRequest();

        $plugin = Plugin::getInstance();
        $bunny = $plugin->getBunny();
        $key = $bunny->getWebhookKey();

        if ($key === '' || !$plugin->getBunnyEnabled()) {
            // No key, no endpoint: never accept unsigned payloads.
            throw new NotFoundHttpException();
        }

        $request = Craft::$app->getRequest();
        $body = (string)$request->getRawBody();
        $headers = $request->getHeaders();

        if (!Bunny::verifyWebhookSignature(
            $body,
            (string)$headers->get('X-BunnyStream-Signature', ''),
            (string)$headers->get('X-BunnyStream-Signature-Version', ''),
            (string)$headers->get('X-BunnyStream-Signature-Algorithm', ''),
            $key,
        )) {
            Craft::warning('Rejected Bunny Stream webhook delivery: invalid signature.', __METHOD__);

            return $this->_reply(401, ['ok' => false, 'handled' => false]);
        }

        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $libraryId = Bunny::validateLibraryId((string)(is_array($event) ? ($event['VideoLibraryId'] ?? '') : ''));
            $videoId = Bunny::validateVideoId((string)($event['VideoGuid'] ?? ''));
        } catch (\JsonException | \InvalidArgumentException) {
            return $this->_reply(400, ['ok' => false, 'handled' => false]);
        }

        if ($libraryId !== $bunny->getLibraryId()) {
            return $this->_reply(200, ['ok' => true, 'handled' => false]);
        }

        try {
            $record = $plugin->getBunnySync()->syncVideo($libraryId, $videoId);
        } catch (\Throwable $e) {
            Craft::warning("Bunny Stream webhook sync failed: {$e->getMessage()}", __METHOD__);

            return $this->_reply(503, ['ok' => false, 'handled' => false]);
        }

        return $this->_reply(200, ['ok' => true, 'handled' => $record !== null]);
    }

    /**
     * Returns whether a Mux event is about static (MP4) renditions.
     *
     * Covers the per-file `video.asset.static_rendition.*` events and the
     * legacy `video.asset.static_renditions.*` aggregate events.
     *
     * @param string $type the event type
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function isRenditionEvent(string $type): bool
    {
        return str_starts_with($type, 'video.asset.static_rendition.')
            || str_starts_with($type, 'video.asset.static_renditions.');
    }

    /**
     * Returns the Mux asset ID a rendition event belongs to.
     *
     * Per-file events carry a rendition ID in `data.id`, so only legacy
     * aggregate events may fall back to it.
     *
     * @param string $type the event type
     * @param array $event the decoded event
     * @return string an empty string when the event names no asset
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function renditionAssetId(string $type, array $event): string
    {
        $data = is_array($event['data'] ?? null) ? $event['data'] : [];
        $assetId = (string)($data['asset_id'] ?? '');

        if ($assetId === '' && str_starts_with($type, 'video.asset.static_renditions.')) {
            $assetId = (string)($data['id'] ?? '');
        }

        if ($assetId === '' && ($event['object']['type'] ?? null) === 'asset') {
            $assetId = (string)($event['object']['id'] ?? '');
        }

        return $assetId;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a JSON reply with a status code.
     *
     * @param int $status the HTTP status code
     * @param array $data the response data
     * @return Response
     */
    private function _reply(int $status, array $data): Response
    {
        Craft::$app->getResponse()->setStatusCode($status);

        return $this->asJson($data);
    }
}
