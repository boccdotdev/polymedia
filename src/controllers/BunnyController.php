<?php

namespace boccdotdev\polymedia\controllers;

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\Bunny;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/** CP-only authoring actions. Remote IDs are always scoped to one library. */
class BunnyController extends Controller
{
    public function actionLibrary(): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $request = Craft::$app->getRequest();
        try {
            $plugin = Plugin::getInstance();
            $result = $plugin->getBunny()->listAssets(
                (int)$request->getQueryParam('limit', 25),
                (int)$request->getQueryParam('page', 1),
                (string)$request->getQueryParam('search', ''),
            );
            $existing = $plugin->getMediaItems()->getByTypeAndProviderIds('bunny', array_column($result['items'], 'playbackId'));
            foreach ($result['items'] as &$item) {
                $record = $existing[$item['playbackId']] ?? null;
                $item['alreadyImported'] = $record !== null;
                $item['craftAssetId'] = $record?->assetId;
            }
            unset($item);
            return $this->asSuccess(data: $result);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    public function actionCreateUpload(): Response
    {
        if ($denied = $this->guard(true)) {
            return $denied;
        }
        try {
            $plugin = Plugin::getInstance();
            $title = trim((string)Craft::$app->getRequest()->getBodyParam('title', ''));
            return $this->asSuccess(data: $plugin->getVideoUploads()->createUpload(
                'bunny',
                fn(): array => $plugin->getBunny()->createDirectUpload($title),
            ) + [
                'title' => $title,
            ]);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    public function actionUploadStatus(): Response
    {
        if ($denied = $this->guard()) {
            return $denied;
        }
        $request = Craft::$app->getRequest();
        try {
            $uploadId = (string)$request->getBodyParam('uploadId', '');
            $plugin = Plugin::getInstance();
            $plugin->getVideoUploads()->completeUpload('bunny', $uploadId);
            return $this->asSuccess(data: $plugin->getBunny()->getUpload($uploadId));
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    public function actionImport(): Response
    {
        return $this->import(false);
    }

    public function actionCompleteUpload(): Response
    {
        return $this->import(true);
    }

    private function import(bool $complete): Response
    {
        if ($denied = $this->guard(true)) {
            return $denied;
        }
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        try {
            $context = $complete
                ? $plugin->getVideoUploads()->completeUpload('bunny', (string)$request->getBodyParam('uploadId', ''))
                : null;
            $folder = $context
                ? Craft::$app->getAssets()->getFolderById((int)$context['folderId'])
                : $this->folder();
            if (!$folder) {
                throw new \RuntimeException('The upload destination no longer exists.');
            }
            $videoId = Bunny::validateVideoId((string)$request->getBodyParam($complete ? 'uploadId' : 'videoId', ''));
            $providerId = Bunny::playbackId($plugin->getBunny()->getLibraryId(), $videoId);
            $lock = 'polymedia:bunny-import:' . $providerId;
            $mutex = Craft::$app->getMutex();
            if (!$mutex->acquire($lock, 15)) {
                throw new \RuntimeException('This Bunny video is being imported. Retry in a moment.');
            }
            try {
                $record = $plugin->getMediaItems()->getByTypeAndProviderId('bunny', $providerId);
                $asset = $record ? Craft::$app->getAssets()->getAssetById((int)$record->assetId) : null;
                $reused = $asset !== null;
                if ($asset && !$asset->canView(Craft::$app->getUser()->getIdentity())) {
                    throw new \RuntimeException('You do not have permission to use this imported media item.');
                }
                if (!$asset) {
                    $state = $plugin->getBunny()->getAsset($videoId);
                    if ($state['isPublic'] !== true) {
                        throw new \RuntimeException('Wait until processing finishes and enable anonymous native HLS playback. Token, referrer and DRM protected libraries are not supported.');
                    }
                    $title = trim((string)$request->getBodyParam('title', '')) ?: ($state['title'] ?: "Bunny {$videoId}");
                    $asset = $plugin->getManifestWriter()->create(
                        volumeId: (int)$folder->volumeId,
                        folderId: (int)$folder->id,
                        detection: new DetectionResult([
                            'type' => 'bunny',
                            'providerId' => $providerId,
                            'url' => $state['url'],
                            'element' => 'hls-video',
                        ]),
                        title: $title,
                        derivedThumbnail: $state['thumbnailUrl'],
                        extraMetadata: [
                            'bunnyLibraryId' => $state['bunnyLibraryId'],
                            'bunnyVideoId' => $videoId,
                            'bunnyCdnHostname' => $state['bunnyCdnHostname'],
                            'bunnyStatus' => $state['bunnyStatus'],
                            'bunnyPlaybackPolicy' => $state['playbackPolicy'],
                            'bunnyThumbnailUrl' => $state['thumbnailUrl'],
                            'mp4Renditions' => $state['mp4Renditions'] ?? [],
                        ],
                    );
                    $record = $plugin->getMediaItems()->getByAssetId((int)$asset->id);
                }
                if (!$record || !$plugin->getBunnySync()->syncRecord($record)) {
                    throw new \RuntimeException('Media item disappeared during import. Retry.');
                }
                if ($context) {
                    $plugin->getVideoUploads()->assertSelectable($context, $asset);
                }
                return $this->asSuccess(data: [
                    'assetId' => $asset->id,
                    'filename' => $asset->getFilename(),
                    'reused' => $reused,
                    'redirectUrl' => $asset->getCpEditUrl(),
                ]);
            } finally {
                $mutex->release($lock);
            }
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }
    }

    private function folder(): \craft\models\VolumeFolder
    {
        $plugin = Plugin::getInstance();
        $folder = $plugin->getManifestWriter()->resolveFolder(
            (int)Craft::$app->getRequest()->getBodyParam('folderId') ?: null,
            Craft::$app->getUser()->getIdentity(),
            $plugin->getSettings(),
        );
        if (!$folder) {
            throw new \RuntimeException('Choose a writable library folder. The sidecar volume cannot contain media manifests.');
        }
        return $folder;
    }

    private function guard(bool $write = false): ?Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();
        if ($write) {
            $this->requirePostRequest();
        }
        $plugin = Plugin::getInstance();
        if (!$plugin->getIsPro()) {
            return $this->asFailure('Bunny Stream authoring requires Polymedia Pro.', data: ['code' => 'pro_required']);
        }
        if (!$plugin->isBunnyEnabled()) {
            return $this->asFailure('Bunny Stream is not configured.', data: ['code' => 'not_configured']);
        }
        if (!$plugin->isVideoProviderEnabled('bunny')) {
            return $this->asFailure('Bunny Stream is not the active video provider.', data: ['code' => 'inactive_provider']);
        }
        return null;
    }
}
