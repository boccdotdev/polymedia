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

use boccdotdev\polymedia\jobs\FetchBunnyPoster;
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
 * remote URL (Mux's own thumbnail, other providers via ThumbnailDeriver)
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
     * A poster this creates is recorded in the item's `posterSource` metadata
     * (asset ID, source URL and any ETag or Last-Modified), so a later refresh
     * can tell it apart from a poster an editor chose.
     *
     * @param MediaItemRecord $record the media item
     * @param ?string $remoteUrl optional remote image URL; derived when null
     * @param bool $force re-download even when a poster already exists
     * @param ?int $expectedPosterId with `$force`, replace only this poster
     * @return ?Asset the poster asset, or null when none could be resolved
     * @throws \RuntimeException when the item can't be locked to attach the poster
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function fetchForItem(
        MediaItemRecord $record,
        ?string $remoteUrl = null,
        bool $force = false,
        ?int $expectedPosterId = null,
    ): ?Asset {
        $related = Plugin::getInstance()->getRelatedAssets();
        $existing = $related->getPoster((int)$record->id);

        if ($this->shouldSkipAutoFetch($existing, $force)) {
            return $existing;
        }

        // A refresh queued for one poster never replaces another.
        if ($expectedPosterId !== null && (int)$existing?->id !== $expectedPosterId) {
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

                if ($current) {
                    $this->_deleteReplacedPoster($current, $folder, (int)$fresh->id);
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
            $this->_persistThumbnailUrl($record, $remoteUrl, $result['poster'] ? array_filter([
                'assetId' => (int)$result['poster']->id,
                'url' => $remoteUrl,
                'etag' => $download['etag'] ?? null,
                'lastModified' => $download['lastModified'] ?? null,
            ], static fn($value) => $value !== null && $value !== '') : null);
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

        $url = Plugin::getInstance()->getMux()->thumbnailUrl($playbackId);
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
     * Queues a poster download for a Bunny item that has a public thumbnail
     * and no poster yet, or a refresh when its thumbnail has changed.
     *
     * The download runs in {@see FetchBunnyPoster}, off the request, and
     * retries while the CDN catches up. Only a poster Polymedia fetched is
     * ever refreshed, never one an editor chose.
     *
     * @param MediaItemRecord $record the media item (type bunny)
     * @param bool $checkRemote also ask the CDN whether the thumbnail at the
     *                          same URL has changed (one HEAD request)
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function ensureBunnyPoster(MediaItemRecord $record, bool $checkRemote = false): void
    {
        if (!Plugin::getInstance()->getRelatedAssets()->getPoster((int)$record->id)) {
            $this->queueBunnyPoster($record);

            return;
        }

        $stale = $this->staleBunnyPosterId($record, $checkRemote);

        if ($stale !== null) {
            $this->queueBunnyPoster($record, replacePosterId: $stale);
        }
    }

    /**
     * Returns the ID of a Bunny item's poster when Polymedia fetched it and
     * Bunny's thumbnail has changed since.
     *
     * Bunny sends no webhook for thumbnail changes. A new thumbnail file
     * changes the URL, which any sync sees. A thumbnail replaced in place
     * keeps its URL, so `$checkRemote` compares its ETag or Last-Modified.
     *
     * @param MediaItemRecord $record the media item (type bunny)
     * @param bool $checkRemote compare the CDN's validators when the URL is unchanged
     * @return ?int the poster to replace, or null to keep it
     *
     * @author boccdotdev
     * @since 2.5.1
     */
    public function staleBunnyPosterId(MediaItemRecord $record, bool $checkRemote = false): ?int
    {
        $plugin = Plugin::getInstance();
        $poster = $plugin->getRelatedAssets()->getPoster((int)$record->id);

        if (!$poster) {
            return null;
        }

        $metadata = $plugin->getMediaItems()->getMetadata($record);
        $source = $metadata['posterSource'] ?? null;

        // An editor's poster, or one fetched before 2.5.1, has no matching source.
        if (!is_array($source) || (int)($source['assetId'] ?? 0) !== (int)$poster->id) {
            return null;
        }

        $url = Bunny::itemThumbnailUrl($metadata);

        if ($url === null) {
            return null;
        }

        if ($url !== ($source['url'] ?? null)) {
            return (int)$poster->id;
        }

        if (!$checkRemote) {
            return null;
        }

        return $this->_remoteChanged($url, $source) ? (int)$poster->id : null;
    }

    /**
     * Queues a retryable Bunny poster download job.
     *
     * @param MediaItemRecord $record the media item
     * @param int $attempt current attempt (0-based)
     * @param ?int $replacePosterId the poster a refresh replaces; null for a first fetch
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function queueBunnyPoster(MediaItemRecord $record, int $attempt = 0, ?int $replacePosterId = null): void
    {
        Craft::$app->getQueue()->delay($attempt > 0 ? $this->backoffSeconds($attempt) : 0)->push(new FetchBunnyPoster([
            'itemId' => (int)$record->id,
            'providerId' => (string)$record->providerId,
            'attempt' => $attempt,
            'replacePosterId' => $replacePosterId,
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
     * Returns the remote thumbnail URL for a media item record.
     *
     * The URL stored in the item's metadata wins, since it's the one fetched
     * or chosen for this item. Otherwise one is derived from the provider ID.
     *
     * @param MediaItemRecord $record
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function deriveRemoteUrl(MediaItemRecord $record): ?string
    {
        $stored = $this->_decodeMetadata($record)['thumbnail'] ?? null;
        $stored = is_string($stored) && $stored !== '' ? $stored : null;

        if ($stored !== null && !$this->_isLegacyMuxDefault($record, $stored)) {
            return $stored;
        }

        $detection = new DetectionResult([
            'type' => (string)$record->type,
            'providerId' => (string)$record->providerId,
            'url' => (string)$record->url,
            'element' => (string)$record->element,
        ]);

        return Plugin::getInstance()->getThumbnailDeriver()->derive($detection) ?? $stored;
    }

    /**
     * HEAD/GET probe of a remote image URL; returns HTTP status or null on network error.
     *
     * Redirects aren't followed, so a poster URL can't bounce the server to
     * another host.
     *
     * @param string $url
     * @return ?int
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function probeRemoteStatus(string $url): ?int
    {
        return $this->probeRemote($url)['status'] ?? null;
    }

    /**
     * HEAD/GET probe of a remote image URL, with its cache validators.
     *
     * Redirects aren't followed, so a poster URL can't bounce the server to
     * another host.
     *
     * @param string $url
     * @return ?array{status: int, etag: ?string, lastModified: ?string} null on network error
     *
     * @author boccdotdev
     * @since 2.5.1
     */
    public function probeRemote(string $url): ?array
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

            return [
                'status' => $status,
                'etag' => $response->getHeaderLine('ETag') ?: null,
                'lastModified' => $response->getHeaderLine('Last-Modified') ?: null,
            ];
        } catch (\Throwable $e) {
            Craft::warning("PosterFetcher probe failed for {$url}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Downloads a remote image to a temp file. Redirects aren't followed.
     *
     * @param string $url
     * @return ?array{path: string, extension: string, etag: ?string, lastModified: ?string} null when not HTTP 200
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
                'etag' => $response->getHeaderLine('ETag') ?: null,
                'lastModified' => $response->getHeaderLine('Last-Modified') ?: null,
            ];
        } catch (\Throwable $e) {
            Craft::warning("PosterFetcher download failed for {$url}: {$e->getMessage()}", __METHOD__);

            return null;
        }
    }

    /**
     * Builds a poster filename that changes whenever the image does.
     *
     * A forced re-fetch saves the new poster next to the one it replaces, and
     * a new URL keeps CDNs from serving the old image after the swap.
     *
     * @param string $title the media item title
     * @param string $path the downloaded image
     * @param string $extension the image extension
     * @return string
     *
     * @author boccdotdev
     * @since 2.3.0
     */
    public function posterFilename(string $title, string $path, string $extension): string
    {
        $slug = StringHelper::slugify($title !== '' ? $title : 'poster');

        if ($slug === '') {
            $slug = 'poster';
        }

        $hash = is_file($path) ? hash_file('crc32b', $path) : false;

        return $hash !== false
            ? "{$slug}-poster-{$hash}.{$extension}"
            : "{$slug}-poster.{$extension}";
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
        $filename = Assets::prepareAssetName($this->posterFilename($title, $tempPath, $extension), true);
        // The same image re-fetched while its predecessor is still attached.
        $filename = Craft::$app->getAssets()->getNameReplacementInFolder($filename, (int)$folder->id);

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

    /**
     * Deletes a poster that a fetch replaced, when Polymedia owns the file.
     *
     * Only a poster in the item's own sidecar folder that nothing else
     * references is removed. Library images an editor chose are kept.
     *
     * @param Asset $previous the poster that was attached before this fetch
     * @param VolumeFolder $folder the item's sidecar folder
     * @param int $itemId the media item ID
     */
    private function _deleteReplacedPoster(Asset $previous, VolumeFolder $folder, int $itemId): void
    {
        if ((int)$previous->folderId !== (int)$folder->id
            || Plugin::getInstance()->getSidecarStorage()->isSharedAsset((int)$previous->id, $itemId)
        ) {
            return;
        }

        try {
            Craft::$app->getElements()->deleteElement($previous, true);
        } catch (\Throwable $e) {
            // The new poster is attached; an orphaned file is the lesser failure.
            Craft::warning(
                "PosterFetcher could not delete replaced poster #{$previous->id}: {$e->getMessage()}",
                __METHOD__,
            );
        }
    }

    /**
     * Whether the image at a URL has changed since a poster was fetched from it.
     *
     * Unknown counts as unchanged: no validators stored or returned, or the
     * probe failed.
     *
     * @param string $url the thumbnail URL
     * @param array $source the poster's recorded source
     * @return bool
     */
    private function _remoteChanged(string $url, array $source): bool
    {
        $probe = $this->probeRemote($url);

        if (!$probe || !in_array($probe['status'], [200, 206], true)) {
            return false;
        }

        foreach (['etag', 'lastModified'] as $validator) {
            $stored = $source[$validator] ?? null;

            if (is_string($stored) && $stored !== '' && $probe[$validator] !== null) {
                return $probe[$validator] !== $stored;
            }
        }

        return false;
    }

    /**
     * Whether a stored thumbnail is the first-frame Mux URL that items
     * imported before 2.5.0 stored by default, rather than one chosen for them.
     *
     * @param MediaItemRecord $record
     * @param string $url the stored thumbnail URL
     * @return bool
     */
    private function _isLegacyMuxDefault(MediaItemRecord $record, string $url): bool
    {
        return $record->type === 'mux'
            && $url === "https://image.mux.com/{$record->providerId}/thumbnail.jpg?time=0";
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
     * @param ?array $posterSource the poster just fetched from it, if any
     */
    private function _persistThumbnailUrl(MediaItemRecord $record, string $thumbnailUrl, ?array $posterSource = null): void
    {
        $metadata = Plugin::getInstance()->getMediaItems()->patchMetadata($record, array_filter([
            'thumbnail' => $thumbnailUrl,
            'posterSource' => $posterSource,
        ], static fn($value) => $value !== null));

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
