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

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\jobs\FetchMuxPoster;
use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use yii\base\Component;

/**
 * Downloads a remote thumbnail into the item's managed sidecar folder and
 * attaches it via {@see RelatedAssets}.
 *
 * Priority: existing related poster wins (user upload). Otherwise derive a
 * remote URL (Mux first frame at `?time=0`, other providers via ThumbnailDeriver)
 * and download when possible. Mux assets that are still processing enqueue
 * {@see FetchMuxPoster}.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class PosterFetcher extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * HTTP statuses treated as “not ready yet” for retryable Mux thumbs.
     *
     * @var int[]
     */
    private const RETRYABLE_STATUSES = [404, 409, 425, 429, 502, 503, 504];

    // Public Methods
    // =========================================================================

    /**
     * Whether auto-fetch should leave an existing poster alone.
     *
     * User-uploaded / already-attached posters win unless `$force` is true.
     *
     * @param ?Asset $existingPoster related poster asset, if any
     * @param bool $force re-download even when a poster already exists
     * @return bool
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function shouldSkipAutoFetch(?Asset $existingPoster, bool $force = false): bool
    {
        return $existingPoster !== null && !$force;
    }

    /**
     * Returns the existing poster if present, otherwise downloads `$remoteUrl`
     * (or a derived URL) into the managed sidecar folder and attaches it.
     *
     * @param MediaItemRecord $record the media item
     * @param ?string $remoteUrl optional remote image URL; derived when null
     * @param bool $force re-download even when a poster already exists
     * @return ?Asset the poster asset, or null when none could be resolved
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function fetchForItem(
        MediaItemRecord $record,
        ?string $remoteUrl = null,
        bool $force = false,
    ): ?Asset {
        $related = Plugin::getInstance()->getRelatedAssets();
        $existing = $related->getPoster((int)$record->id);

        if ($this->shouldSkipAutoFetch($existing, $force)) {
            return $existing;
        }

        $remoteUrl = $remoteUrl ?: $this->deriveRemoteUrl($record);

        if ($remoteUrl === null || $remoteUrl === '') {
            return null;
        }

        // Existing installations may not have configured sidecars yet. Keep
        // their remote-thumbnail fallback without downloading an unusable file.
        if (!Plugin::getInstance()->getSidecarStorage()->getVolume()) {
            $this->_persistThumbnailUrl($record, $remoteUrl);

            return null;
        }

        // HTTP stays outside the item lock. No storage is created until the
        // item and its poster have been re-read after the download.
        $download = $this->downloadToTemp($remoteUrl);

        if ($download === null) {
            return null;
        }

        try {
            $result = Plugin::getInstance()->getMediaItems()->withItemLock($record, function() use ($record, $related, $existing, $force, $download): array {
                $fresh = Plugin::getInstance()->getMediaItems()->getById((int)$record->id);

                if (!$fresh || !Craft::$app->getAssets()->getAssetById((int)$fresh->assetId)) {
                    return ['poster' => null, 'persist' => false];
                }

                $current = $related->getPoster((int)$fresh->id);

                // Force replaces only the poster observed at the start. An
                // editor or another fetch that changed it while HTTP ran wins.
                if ($this->shouldSkipAutoFetch($current, $force)
                    || ($current?->id !== $existing?->id)
                ) {
                    return ['poster' => $current, 'persist' => false];
                }

                $folder = Plugin::getInstance()->getSidecarStorage()->getItemFolder($fresh);

                if (!$folder) {
                    return ['poster' => null, 'persist' => true];
                }

                $poster = $this->_createPosterAsset(
                    $download['path'],
                    $download['extension'],
                    $folder,
                    (int)$folder->volumeId,
                    (string)$fresh->title,
                );

                if (!$poster) {
                    return ['poster' => null, 'persist' => false];
                }

                try {
                    $related->attach(
                        itemId: (int)$fresh->id,
                        assetId: (int)$poster->id,
                        role: 'poster',
                    );
                } catch (\Throwable $e) {
                    // Only this newly-created, unattached candidate is ours
                    // to discard. Never delete the previous selected poster.
                    if (!$related->isAssetRelated((int)$poster->id)) {
                        Craft::$app->getElements()->deleteElement($poster, true);
                    }
                    throw $e;
                }

                return ['poster' => $poster, 'persist' => true];
            });

            if ($result === null) {
                throw new \RuntimeException("Could not lock media item #{$record->id} to attach its poster.");
            }
        } finally {
            if (is_file($download['path'])) {
                @unlink($download['path']);
            }
        }

        // patchMetadata takes the same non-reentrant item lock.
        if ($result['persist']) {
            $this->_persistThumbnailUrl($record, $remoteUrl);
        }

        return $result['poster'];
    }

    /**
     * Ensures a poster for a Mux item: tries a sync download, else queues a job.
     *
     * Always runs for Mux library/upload imports (even when `autoFetchPoster` is
     * off) unless a user poster is already attached.
     *
     * @param MediaItemRecord $record the media item (type mux)
     * @param ?string $playbackId Mux playback id; falls back to record.providerId
     * @return ?Asset poster when available immediately
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function ensureMuxPoster(MediaItemRecord $record, ?string $playbackId = null): ?Asset
    {
        $related = Plugin::getInstance()->getRelatedAssets();
        $existing = $related->getPoster((int)$record->id);

        if ($existing) {
            return $existing;
        }

        $playbackId = $playbackId ?: (string)$record->providerId;

        if ($playbackId === '') {
            return null;
        }

        $url = Plugin::getInstance()->getMux()->firstFrameThumbnailUrl($playbackId);
        $status = $this->probeRemoteStatus($url);

        if ($status === 200) {
            return $this->fetchForItem($record, $url);
        }

        if ($status === null || in_array($status, self::RETRYABLE_STATUSES, true)) {
            $this->queueMuxPoster($record, $playbackId);
        }

        $this->_persistThumbnailUrl($record, $url);

        return null;
    }

    /**
     * Queues a retryable Mux poster download job.
     *
     * @param MediaItemRecord $record the media item
     * @param string $playbackId Mux playback id
     * @param int $attempt current attempt (0-based)
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function queueMuxPoster(MediaItemRecord $record, string $playbackId, int $attempt = 0): void
    {
        Craft::$app->getQueue()->delay($this->backoffSeconds($attempt))->push(new FetchMuxPoster([
            'itemId' => (int)$record->id,
            'playbackId' => $playbackId,
            'attempt' => $attempt,
        ]));
    }

    /**
     * Exponential backoff delay in seconds for Mux poster retries.
     *
     * @param int $attempt 0-based attempt index
     * @return int
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function backoffSeconds(int $attempt): int
    {
        // 15s, 30s, 60s, 120s, … capped at 10 minutes
        return min(600, 15 * (2 ** max(0, $attempt)));
    }

    /**
     * Derives a remote thumbnail URL for a media item record.
     *
     * @param MediaItemRecord $record
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function deriveRemoteUrl(MediaItemRecord $record): ?string
    {
        $detection = new DetectionResult([
            'type' => (string)$record->type,
            'providerId' => (string)$record->providerId,
            'url' => (string)$record->url,
            'element' => (string)$record->element,
        ]);

        $derived = Plugin::getInstance()->getThumbnailDeriver()->derive($detection);

        if ($derived) {
            return $derived;
        }

        $metadata = $this->_decodeMetadata($record);

        return isset($metadata['thumbnail']) && is_string($metadata['thumbnail'])
            ? $metadata['thumbnail']
            : null;
    }

    /**
     * HEAD/GET probe of a remote image URL; returns HTTP status or null on network error.
     *
     * @param string $url
     * @return ?int
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function probeRemoteStatus(string $url): ?int
    {
        try {
            $client = Craft::createGuzzleClient(['timeout' => 10, 'http_errors' => false, 'allow_redirects' => false]);
            $response = $client->head($url);

            $status = $response->getStatusCode();

            // Some CDNs reject HEAD; fall back to a ranged GET.
            if ($status === 403 || $status === 405) {
                $response = $client->get($url, [
                    'headers' => ['Range' => 'bytes=0-0'],
                ]);
                $status = $response->getStatusCode();
            }

            return $status;
        } catch (\Throwable $e) {
            Craft::warning("PosterFetcher probe failed for {$url}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Downloads a remote image to a temp file.
     *
     * @param string $url
     * @return ?array{path: string, extension: string} null when not HTTP 200
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function downloadToTemp(string $url): ?array
    {
        try {
            $client = Craft::createGuzzleClient(['timeout' => 30, 'http_errors' => false, 'allow_redirects' => false]);
            $response = $client->get($url);

            if ($response->getStatusCode() !== 200) {
                return null;
            }

            $contentType = $response->getHeaderLine('Content-Type');
            $extension = $this->_extensionFromContentType($contentType) ?? 'jpg';
            $path = Assets::tempFilePath($extension);
            file_put_contents($path, (string)$response->getBody());

            if (!is_file($path) || filesize($path) === 0) {
                @unlink($path);

                return null;
            }

            return [
                'path' => $path,
                'extension' => $extension,
            ];
        } catch (\Throwable $e) {
            Craft::warning("PosterFetcher download failed for {$url}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $tempPath
     * @param string $extension
     * @param VolumeFolder $folder
     * @param int $volumeId
     * @param string $title
     * @return ?Asset
     */
    protected function _createPosterAsset(
        string $tempPath,
        string $extension,
        VolumeFolder $folder,
        int $volumeId,
        string $title,
    ): ?Asset {
        $slug = StringHelper::slugify($title !== '' ? $title : 'poster');

        if ($slug === '') {
            $slug = 'poster';
        }

        $filename = "{$slug}-poster.{$extension}";
        $filename = Assets::prepareAssetName($filename, true);

        // Keep the temp file for Craft to move; copy so callers can still unlink original.
        $uploadPath = Assets::tempFilePath($extension);
        if (!@copy($tempPath, $uploadPath)) {
            $uploadPath = $tempPath;
        }

        $asset = new Asset();
        $asset->tempFilePath = $uploadPath;
        $asset->filename = $filename;
        $asset->newFolderId = (int)$folder->id;
        $asset->volumeId = $volumeId;
        $asset->title = $title !== '' ? $title : pathinfo($filename, PATHINFO_FILENAME);
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            Craft::warning(
                'PosterFetcher could not save poster asset: ' . implode(', ', $asset->getFirstErrors()),
                __METHOD__,
            );

            if ($uploadPath !== $tempPath && is_file($uploadPath)) {
                @unlink($uploadPath);
            }

            return null;
        }

        return $asset;
    }

    private function _extensionFromContentType(string $contentType): ?string
    {
        $contentType = strtolower(trim(explode(';', $contentType)[0]));

        return match ($contentType) {
            'image/jpeg', 'image/jpg' => 'jpg',
            'image/png' => 'png',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
            default => null,
        };
    }

    /**
     * @param MediaItemRecord $record
     * @return array
     */
    private function _decodeMetadata(MediaItemRecord $record): array
    {
        if ($record->metadata === null || $record->metadata === '') {
            return [];
        }

        $decoded = Json::decodeIfJson($record->metadata);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Writes the remote thumbnail URL into record + manifest metadata.
     *
     * Goes through {@see MediaItems::patchMetadata()} so a concurrent status
     * write (queue job vs. sync/import) can't be lost to a stale merge.
     *
     * @param MediaItemRecord $record
     * @param string $thumbnailUrl
     */
    private function _persistThumbnailUrl(MediaItemRecord $record, string $thumbnailUrl): void
    {
        $metadata = Plugin::getInstance()->getMediaItems()->patchMetadata($record, [
            'thumbnail' => $thumbnailUrl,
        ]);

        if ($metadata === null) {
            return;
        }

        $asset = Craft::$app->getAssets()->getAssetById((int)$record->assetId);

        if (!$asset) {
            return;
        }

        try {
            Plugin::getInstance()->getManifestWriter()->update($asset, [
                'metadata' => $metadata,
            ]);
        } catch (\Throwable $e) {
            Craft::warning(
                "PosterFetcher could not update manifest metadata for asset #{$asset->id}: {$e->getMessage()}",
                __METHOD__,
            );
        }
    }
}
