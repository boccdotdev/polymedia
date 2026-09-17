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
use boccdotdev\polymedia\services\Mux;
use Craft;
use craft\helpers\Json;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Receives Mux webhook deliveries and applies asset state.
 *
 * A signature-checking shell around
 * {@see \boccdotdev\polymedia\services\MediaItems::applyMuxAssetState()} — the
 * same pipeline the CP import/upload flow and the console sync command use,
 * so webhook configuration is optional and polling remains the fallback.
 *
 * The endpoint answers only when a webhook signing secret is configured, and
 * every delivery must carry a valid `Mux-Signature` header.
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
    protected array|bool|int $allowAnonymous = ['mux'];

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
     * matching media item. Events for unknown assets and unhandled event types
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

        if ($state['assetId'] === '') {
            return $this->asJson(['ok' => true, 'handled' => false]);
        }

        if ($type === 'video.asset.deleted') {
            $state['status'] = 'deleted';
        }

        // The library changed (or an asset's state did) — keep browse/search fresh.
        $mux->invalidateLibrarySnapshot();

        $record = $plugin->getMediaItems()->applyMuxAssetState($state['assetId'], $state);

        if ($record) {
            Craft::info(
                "Applied Mux webhook {$type} to media item #{$record->id} (status: {$state['status']}).",
                __METHOD__,
            );
        }

        return $this->asJson(['ok' => true, 'handled' => $record !== null]);
    }
}
