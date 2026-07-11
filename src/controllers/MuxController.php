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

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\helpers\UrlHelper;
use craft\models\VolumeFolder;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP JSON actions for Mux library browse, import, and direct upload.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class MuxController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Lists assets from the connected Mux account, enriched with Craft import state.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionLibrary(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        $request = Craft::$app->getRequest();
        $limit = (int)$request->getQueryParam('limit', 25);
        $page = (int)$request->getQueryParam('page', 1);

        try {
            $result = Plugin::getInstance()->getMux()->listAssets($limit, $page);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        $playbackIds = [];

        foreach ($result['items'] as $item) {
            if (!empty($item['playbackId'])) {
                $playbackIds[] = (string)$item['playbackId'];
            }
        }

        $existing = Plugin::getInstance()->getMediaItems()->getByTypeAndProviderIds('mux', $playbackIds);

        foreach ($result['items'] as &$item) {
            $playbackId = (string)($item['playbackId'] ?? '');
            $record = $playbackId !== '' ? ($existing[$playbackId] ?? null) : null;
            $item['alreadyImported'] = $record !== null;
            $item['craftAssetId'] = $record?->assetId;
            $item['isPublic'] = ($item['playbackPolicy'] ?? null) === 'public';
        }
        unset($item);

        return $this->asSuccess(data: $result);
    }

    /**
     * Imports a Mux asset into a Craft `.pmedia` (or reuses an existing one).
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionImport(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        $request = Craft::$app->getRequest();
        $muxAssetId = trim((string)$request->getRequiredBodyParam('muxAssetId'));
        $folderId = (int)$request->getBodyParam('folderId') ?: null;
        $titleOverride = trim((string)$request->getBodyParam('title', ''));

        if ($muxAssetId === '') {
            return $this->asFailure(Craft::t('polymedia', 'Mux asset id is required.'));
        }

        return $this->_respondFromMuxAssetId(
            $muxAssetId,
            $folderId,
            $titleOverride,
            Craft::t('polymedia', 'Mux media imported.'),
        );
    }

    /**
     * Creates a Mux direct-upload URL for the browser to PUT with UpChunk.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionCreateUpload(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $title = trim((string)$request->getBodyParam('title', ''));
        $folderId = (int)$request->getBodyParam('folderId') ?: null;

        $settings = $plugin->getSettings();
        $currentUser = Craft::$app->getUser()->getIdentity();
        $folder = $this->_resolveFolder($folderId, $currentUser, $settings);

        if (!$folder) {
            return $this->asFailure(Craft::t(
                'polymedia',
                'You do not have permission to save assets in this volume.',
            ));
        }

        try {
            $upload = $plugin->getMux()->createDirectUpload($title);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        if (empty($upload['uploadUrl']) || empty($upload['uploadId'])) {
            return $this->asFailure(Craft::t(
                'polymedia',
                'Mux did not return an upload URL.',
            ));
        }

        return $this->asSuccess(data: [
            'uploadId' => $upload['uploadId'],
            'uploadUrl' => $upload['uploadUrl'],
            'status' => $upload['status'],
            'folderId' => (int)$folder->id,
            'title' => $title,
        ]);
    }

    /**
     * Polls a Mux direct upload until an asset id (and preferably playback id) is available.
     *
     * Accepts POST (preferred, body `uploadId`) or GET query `uploadId`.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionUploadStatus(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        $request = Craft::$app->getRequest();
        $uploadId = trim((string)(
            $request->getBodyParam('uploadId')
            ?? $request->getQueryParam('uploadId')
            ?? ''
        ));

        if ($uploadId === '') {
            return $this->asFailure(Craft::t('polymedia', 'Upload id is required.'));
        }

        $plugin = Plugin::getInstance();

        try {
            $upload = $plugin->getMux()->getUpload($uploadId);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        $status = (string)$upload['status'];
        $muxAssetId = $upload['assetId'];
        $playbackId = null;
        $muxStatus = null;
        $ready = false;

        if ($status === 'errored' || $status === 'cancelled' || $status === 'timed_out') {
            return $this->asSuccess(data: [
                'uploadId' => $upload['uploadId'],
                'status' => $status,
                'assetId' => $muxAssetId,
                'playbackId' => null,
                'ready' => false,
                'failed' => true,
                'message' => Craft::t('polymedia', 'Mux upload failed ({status}).', [
                    'status' => $status,
                ]),
            ]);
        }

        if ($muxAssetId) {
            try {
                $muxAsset = $plugin->getMux()->getAsset((string)$muxAssetId);
                $playbackId = $muxAsset['playbackId'] ?? null;
                $muxStatus = $muxAsset['status'] ?? null;
                // Playback ID is enough to create a playable `.pmedia` even while preparing.
                $ready = is_string($playbackId) && $playbackId !== '';
            } catch (\Throwable) {
                // Asset may not be queryable yet — keep polling.
                $ready = false;
            }
        }

        return $this->asSuccess(data: [
            'uploadId' => $upload['uploadId'],
            'status' => $status,
            'assetId' => $muxAssetId,
            'playbackId' => $playbackId,
            'muxStatus' => $muxStatus,
            'ready' => $ready,
            'failed' => false,
        ]);
    }

    /**
     * Completes a Mux upload by creating/reusing a `.pmedia` asset.
     *
     * Accepts either `muxAssetId` or `uploadId` (resolved to an asset id).
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionCompleteUpload(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        $request = Craft::$app->getRequest();
        $muxAssetId = trim((string)$request->getBodyParam('muxAssetId', ''));
        $uploadId = trim((string)$request->getBodyParam('uploadId', ''));
        $folderId = (int)$request->getBodyParam('folderId') ?: null;
        $titleOverride = trim((string)$request->getBodyParam('title', ''));

        if ($muxAssetId === '' && $uploadId !== '') {
            try {
                $upload = Plugin::getInstance()->getMux()->getUpload($uploadId);
            } catch (\Throwable $e) {
                return $this->asFailure($e->getMessage());
            }

            $muxAssetId = (string)($upload['assetId'] ?? '');

            if ($muxAssetId === '') {
                return $this->asFailure(Craft::t(
                    'polymedia',
                    'Mux has not created an asset for this upload yet. Keep polling.',
                ));
            }
        }

        if ($muxAssetId === '') {
            return $this->asFailure(Craft::t(
                'polymedia',
                'Mux asset id or upload id is required.',
            ));
        }

        return $this->_respondFromMuxAssetId(
            $muxAssetId,
            $folderId,
            $titleOverride,
            Craft::t('polymedia', 'Mux upload complete.'),
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Shared import/complete path: resolve Mux asset → reuse or create `.pmedia`.
     *
     * @param string $muxAssetId Mux asset id
     * @param ?int $folderId target folder for new imports
     * @param string $titleOverride optional title
     * @param string $successMessage success flash/JSON message for new creates
     * @return Response
     */
    private function _respondFromMuxAssetId(
        string $muxAssetId,
        ?int $folderId,
        string $titleOverride,
        string $successMessage,
    ): Response {
        $plugin = Plugin::getInstance();

        try {
            $muxAsset = $plugin->getMux()->getAsset($muxAssetId);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        $playbackId = (string)($muxAsset['playbackId'] ?? '');

        if ($playbackId === '') {
            return $this->asFailure(Craft::t(
                'polymedia',
                'This Mux asset has no playback ID yet. Wait until processing finishes, then try again.',
            ));
        }

        if (($muxAsset['playbackPolicy'] ?? null) !== 'public') {
            return $this->asFailure(Craft::t(
                'polymedia',
                'Only public Mux playback IDs can be imported in this version. Signed playback is deferred.',
            ));
        }

        $existing = $plugin->getMediaItems()->getByTypeAndProviderId('mux', $playbackId);

        if ($existing) {
            $asset = Craft::$app->getAssets()->getAssetById((int)$existing->assetId);

            if ($asset) {
                $plugin->getPosterFetcher()->ensureMuxPoster($existing, $playbackId);

                return $this->asSuccess(
                    Craft::t('polymedia', 'Already in Craft — using existing media item.'),
                    [
                        'assetId' => $asset->id,
                        'reused' => true,
                        'redirectUrl' => $asset->getCpEditUrl(),
                    ],
                );
            }
        }

        $settings = $plugin->getSettings();
        $currentUser = Craft::$app->getUser()->getIdentity();
        $folder = $this->_resolveFolder($folderId, $currentUser, $settings);

        if (!$folder) {
            return $this->asFailure(Craft::t(
                'polymedia',
                'You do not have permission to save assets in this volume.',
            ));
        }

        $title = $titleOverride !== ''
            ? $titleOverride
            : ((string)($muxAsset['title'] ?? '') !== '' ? (string)$muxAsset['title'] : "Mux {$playbackId}");

        $detection = new DetectionResult([
            'type' => 'mux',
            'providerId' => $playbackId,
            'url' => "https://stream.mux.com/{$playbackId}.m3u8",
            'element' => 'mux-video',
        ]);

        $thumbnail = $plugin->getMux()->firstFrameThumbnailUrl($playbackId);
        $extraMetadata = array_filter([
            'muxAssetId' => $muxAsset['assetId'] ?? $muxAssetId,
            'muxStatus' => $muxAsset['status'] ?? null,
            'thumbnail' => $thumbnail,
        ], static fn($v) => $v !== null && $v !== '');

        try {
            $asset = $plugin->getManifestWriter()->create(
                volumeId: (int)$folder->volumeId,
                folderId: (int)$folder->id,
                detection: $detection,
                title: $title,
                derivedThumbnail: $thumbnail,
                extraMetadata: $extraMetadata,
            );
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        $record = $plugin->getMediaItems()->getByAssetId((int)$asset->id);

        if ($record) {
            if (isset($muxAsset['duration']) && is_numeric($muxAsset['duration'])) {
                $record->duration = (int)round((float)$muxAsset['duration']);
                $plugin->getMediaItems()->save($record);
            }

            $plugin->getPosterFetcher()->ensureMuxPoster($record, $playbackId);
        }

        return $this->asSuccess(
            $successMessage,
            [
                'assetId' => $asset->id,
                'reused' => false,
                'redirectUrl' => $asset->getCpEditUrl(),
            ],
        );
    }

    /**
     * Returns a failure response when the request is not Pro + Mux-configured,
     * or null when the caller may proceed.
     *
     * @return ?Response
     */
    private function _denyUnlessMuxEnabled(): ?Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getIsPro()) {
            return $this->asFailure(
                Craft::t(
                    'polymedia',
                    'Mux library and upload require Polymedia Pro. Upgrade in the Plugin Store.',
                ),
                data: [
                    'upgradeUrl' => 'https://plugins.craftcms.com/polymedia',
                    'code' => 'pro_required',
                ],
            );
        }

        if (!$plugin->getMux()->isConfigured()) {
            $settingsUrl = UrlHelper::cpUrl('settings/plugins/polymedia');

            return $this->asFailure(
                Craft::t(
                    'polymedia',
                    'Mux is not configured. Add a Token ID and Secret in Polymedia settings.',
                ),
                data: [
                    'settingsUrl' => $settingsUrl,
                    'code' => 'not_configured',
                ],
            );
        }

        return null;
    }

    /**
     * Resolves the target folder for a new `.pmedia` asset (mirrors MediaItemsController).
     *
     * @param int|null $folderId
     * @param User|null $user
     * @param Settings $settings
     * @return ?VolumeFolder
     */
    private function _resolveFolder(?int $folderId, ?User $user, Settings $settings): ?VolumeFolder
    {
        $assets = Craft::$app->getAssets();

        if ($folderId) {
            $folder = $assets->getFolderById($folderId);

            if ($folder && $this->_canSave($folder, $user)) {
                return $folder;
            }
        }

        if ($settings->defaultVolumeUid) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid($settings->defaultVolumeUid);

            if ($volume) {
                $folder = $assets->getRootFolderByVolumeId($volume->id);

                if ($folder && $this->_canSave($folder, $user)) {
                    return $folder;
                }
            }
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($user && $user->can("saveAssets:$volume->uid")) {
                return $assets->getRootFolderByVolumeId($volume->id);
            }
        }

        return null;
    }

    /**
     * @param VolumeFolder $folder
     * @param User|null $user
     * @return bool
     */
    private function _canSave(VolumeFolder $folder, ?User $user): bool
    {
        if (!$user || !$folder->volumeId) {
            return false;
        }

        return $user->can("saveAssets:{$folder->getVolume()->uid}");
    }
}
