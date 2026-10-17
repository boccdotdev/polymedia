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

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * CP JSON actions for the Bunny Stream library: browse, import and direct upload.
 *
 * Every action requires Pro, a configured library and Bunny as the selected
 * hosting provider. Remote IDs are always scoped to the configured library.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Lists or searches the library, enriched with Craft import state.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON request
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionLibrary(): Response
    {
        $denied = $this->_guard(false);

        if ($denied !== null) {
            return $denied;
        }

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();

        try {
            $result = $plugin->getBunny()->listAssets(
                (int)$request->getQueryParam('limit', 25),
                (int)$request->getQueryParam('page', 1),
                (string)$request->getQueryParam('search', ''),
            );
        } catch (\Throwable $e) {
            Craft::warning("Bunny library request failed: {$e->getMessage()}", __METHOD__);

            return $this->asFailure($e instanceof \RuntimeException ? $e->getMessage() : Craft::t('polymedia', 'Could not load video library.'));
        }

        $existing = $plugin->getMediaItems()->getByTypeAndProviderIds('bunny', array_column($result['items'], 'playbackId'));

        foreach ($result['items'] as &$item) {
            $record = $existing[$item['playbackId']] ?? null;
            $item['alreadyImported'] = $record !== null;
            $item['craftAssetId'] = $record?->assetId;
        }
        unset($item);

        return $this->asSuccess(data: $result);
    }

    /**
     * Imports a library video into a `.pmedia` (or reuses the existing one).
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionImport(): Response
    {
        $denied = $this->_guard(true);

        if ($denied !== null) {
            return $denied;
        }

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();
        $folder = $plugin->getManifestWriter()->resolveFolder(
            (int)$request->getBodyParam('folderId') ?: null,
            $user,
            $plugin->getSettings(),
        );

        if (!$user || !$folder) {
            return $this->asFailure(Craft::t('polymedia', 'Choose a writable library volume for media. The sidecar volume cannot contain media manifests.'));
        }

        try {
            $result = $plugin->getBunnySync()->importVideo(
                (string)$request->getBodyParam('videoId', ''),
                $folder,
                $user,
                (string)$request->getBodyParam('title', ''),
            );
        } catch (ForbiddenHttpException | \RuntimeException | \InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        $asset = $result['asset'];

        return $this->asSuccess(
            $result['reused']
                ? Craft::t('polymedia', 'Already in Craft, using the existing media item.')
                : Craft::t('polymedia', 'Video imported.'),
            [
                'assetId' => $asset->id,
                'filename' => $asset->getFilename(),
                'reused' => $result['reused'],
                'redirectUrl' => $asset->getCpEditUrl(),
            ],
        );
    }

    /**
     * Creates a Bunny video and a signed tus upload for an authorized context.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionCreateUpload(): Response
    {
        $denied = $this->_guard(true);

        if ($denied !== null) {
            return $denied;
        }

        $plugin = Plugin::getInstance();
        $title = trim((string)Craft::$app->getRequest()->getBodyParam('title', ''));

        try {
            $upload = $plugin->getVideoUploads()->createUpload(
                Settings::PROVIDER_BUNNY,
                // The tus signature expires with the authorization behind it.
                fn(int $expiresIn): array => $plugin->getBunny()->createDirectUpload($title, ['expiresIn' => $expiresIn]),
            );
        } catch (ForbiddenHttpException | BadRequestHttpException | \RuntimeException | \InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(data: $upload + ['title' => $title]);
    }

    /**
     * Returns an authorized upload's processing state.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionUploadStatus(): Response
    {
        $denied = $this->_guard(true);

        if ($denied !== null) {
            return $denied;
        }

        $plugin = Plugin::getInstance();
        $uploadId = (string)Craft::$app->getRequest()->getBodyParam('uploadId', '');

        try {
            $plugin->getVideoUploads()->completeUpload(Settings::PROVIDER_BUNNY, $uploadId);

            return $this->asSuccess(data: $plugin->getBunny()->getUpload($uploadId));
        } catch (ForbiddenHttpException | BadRequestHttpException | \RuntimeException | \InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    /**
     * Imports a finished upload into its authorized folder.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionCompleteUpload(): Response
    {
        $denied = $this->_guard(true);

        if ($denied !== null) {
            return $denied;
        }

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $uploadId = (string)$request->getBodyParam('uploadId', '');
        $user = Craft::$app->getUser()->getIdentity();

        try {
            $context = $plugin->getVideoUploads()->completeUpload(Settings::PROVIDER_BUNNY, $uploadId);
            $folder = Craft::$app->getAssets()->getFolderById((int)$context['folderId']);

            if (!$user || !$folder) {
                throw new \RuntimeException('The upload destination no longer exists. Import the video from the video library instead.');
            }

            $result = $plugin->getBunnySync()->importVideo($uploadId, $folder, $user, (string)$request->getBodyParam('title', ''));
            $plugin->getVideoUploads()->assertSelectable($context, $result['asset']);
        } catch (ForbiddenHttpException | BadRequestHttpException | \RuntimeException | \InvalidArgumentException $e) {
            return $this->asFailure($e->getMessage());
        }

        $asset = $result['asset'];

        return $this->asSuccess(Craft::t('polymedia', 'Video upload complete.'), [
            'assetId' => $asset->id,
            'filename' => $asset->getFilename(),
            'reused' => $result['reused'],
            'redirectUrl' => $asset->getCpEditUrl(),
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Requires a CP JSON request (POST for writes), Pro, a configured library
     * and Bunny as the selected provider.
     *
     * @param bool $write whether the action changes state
     * @return ?Response a failure response, or null when the caller may proceed
     * @throws BadRequestHttpException when the request isn't a CP JSON request
     */
    private function _guard(bool $write): ?Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        if ($write) {
            $this->requirePostRequest();
        }

        $plugin = Plugin::getInstance();

        if (!$plugin->getIsPro()) {
            return $this->asFailure(
                Craft::t('polymedia', 'Bunny Stream library and upload require Polymedia Pro. Upgrade in the Plugin Store.'),
                data: ['upgradeUrl' => 'https://plugins.craftcms.com/polymedia', 'code' => 'pro_required'],
            );
        }

        if (!$plugin->getBunnyEnabled()) {
            return $this->asFailure(
                Craft::t('polymedia', 'Bunny Stream is not configured. Add a library ID, API key and CDN hostname in Polymedia settings.'),
                data: ['code' => 'not_configured'],
            );
        }

        if (!$plugin->isVideoProviderEnabled(Settings::PROVIDER_BUNNY)) {
            return $this->asFailure(
                Craft::t('polymedia', 'Bunny Stream is not the selected video hosting provider for new imports and uploads.'),
                data: ['code' => 'inactive_provider'],
            );
        }

        return null;
    }
}
