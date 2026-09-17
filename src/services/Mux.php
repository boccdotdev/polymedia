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

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\helpers\App;
use GuzzleHttp\Client;
use MuxPhp\Api\AssetsApi;
use MuxPhp\Api\DirectUploadsApi;
use MuxPhp\ApiException;
use MuxPhp\Configuration;
use MuxPhp\Models\Asset;
use MuxPhp\Models\AssetMetadata;
use MuxPhp\Models\CreateAssetRequest;
use MuxPhp\Models\CreateUploadRequest;
use MuxPhp\Models\PlaybackID;
use MuxPhp\Models\PlaybackPolicy;
use MuxPhp\Models\Upload;
use yii\base\Component;
use yii\base\Exception;

/**
 * Thin wrapper around the Mux Video PHP SDK for library browse, direct upload,
 * and asset lifecycle operations.
 *
 * Credentials come from plugin settings (env-overridable via `App::parseEnv()`).
 * When either token is empty the service reports not configured and API methods
 * throw rather than calling Mux with blank auth.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class Mux extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Maximum number of assets scanned when searching the library.
     *
     * The Mux Assets API is list-only (no server-side search or title filter),
     * so search pages through the list — newest first — and filters locally.
     * The cap bounds API calls and memory for very large libraries.
     */
    public const SEARCH_SCAN_LIMIT = 1000;

    /**
     * @var string Cache key for the library snapshot used by search.
     */
    private const SNAPSHOT_CACHE_KEY = 'polymedia:mux:library-snapshot';

    /**
     * @var int Snapshot TTL in seconds. Short: search stays fast across
     *          keystrokes without going stale for long after uploads.
     */
    private const SNAPSHOT_CACHE_TTL = 120;

    // Private Properties
    // =========================================================================

    /**
     * @var ?Configuration
     */
    private ?Configuration $_config = null;

    /**
     * @var ?AssetsApi
     */
    private ?AssetsApi $_assetsApi = null;

    /**
     * @var ?DirectUploadsApi
     */
    private ?DirectUploadsApi $_uploadsApi = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns whether both Mux Token ID and Secret resolve to non-empty values.
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function isConfigured(): bool
    {
        return $this->getTokenId() !== '' && $this->getTokenSecret() !== '';
    }

    /**
     * Returns the resolved Mux Token ID (env vars expanded).
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getTokenId(): string
    {
        $raw = Plugin::getInstance()->getSettings()->muxTokenId;

        if ($raw === null || $raw === '') {
            return '';
        }

        return (string)(App::parseEnv($raw) ?: '');
    }

    /**
     * Returns the resolved Mux Token Secret (env vars expanded).
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getTokenSecret(): string
    {
        $raw = Plugin::getInstance()->getSettings()->muxTokenSecret;

        if ($raw === null || $raw === '') {
            return '';
        }

        return (string)(App::parseEnv($raw) ?: '');
    }

    /**
     * Lists Mux assets as CP-friendly DTOs.
     *
     * Each item includes: `assetId`, `playbackId`, `title`, `status`,
     * `duration`, `thumbnailUrl`, `createdAt`, `playbackPolicy`.
     * `alreadyImported` is not set here — controllers enrich via MediaItems.
     *
     * @param int $limit page size (Mux default 25; keep modest for rate limits)
     * @param int $page 1-based page
     * @return array{items: array<int, array<string, mixed>>, page: int, limit: int}
     * @throws Exception when Mux is not configured or the API call fails
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function listAssets(int $limit = 25, int $page = 1): array
    {
        $this->_requireConfigured();

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        try {
            $response = $this->_getAssetsApi()->listAssets($limit, $page);
        } catch (ApiException $e) {
            throw $this->_wrapApiException($e, 'list assets');
        }

        $items = [];

        foreach ($response->getData() ?? [] as $asset) {
            $items[] = $this->mapAsset($asset);
        }

        return [
            'items' => $items,
            'page' => $page,
            'limit' => $limit,
        ];
    }

    /**
     * Searches the Mux library by title, passthrough, asset id, or playback id.
     *
     * The Mux Assets API has no search endpoint, so this filters a cached
     * snapshot of the newest {@see self::SEARCH_SCAN_LIMIT} assets. Matching is
     * case-insensitive substring. Results are paginated locally and include
     * `total` (matches found) and `scanLimited` (whether the library exceeded
     * the scan window, i.e. older assets were not searched).
     *
     * @param string $query the search text
     * @param int $limit page size
     * @param int $page 1-based page
     * @return array{items: array<int, array<string, mixed>>, page: int, limit: int, total: int, scanLimited: bool, scanLimit: int}
     * @throws Exception when Mux is not configured or the API call fails
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function searchAssets(string $query, int $limit = 25, int $page = 1): array
    {
        $query = trim($query);

        if ($query === '') {
            return $this->listAssets($limit, $page)
                + ['total' => 0, 'scanLimited' => false, 'scanLimit' => self::SEARCH_SCAN_LIMIT];
        }

        $this->_requireConfigured();

        $limit = max(1, min(100, $limit));
        $page = max(1, $page);

        $snapshot = $this->_getLibrarySnapshot();
        $matches = $this->filterAssets($snapshot['items'], $query);
        $total = count($matches);

        return [
            'items' => array_slice($matches, ($page - 1) * $limit, $limit),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'scanLimited' => $snapshot['scanLimited'],
            'scanLimit' => self::SEARCH_SCAN_LIMIT,
        ];
    }

    /**
     * Filters mapped asset DTOs by a case-insensitive substring query.
     *
     * Matches against `title`, `passthrough`, `assetId`, and `playbackId`.
     * Pure function — exposed for unit testing.
     *
     * @param array<int, array<string, mixed>> $items mapped assets ({@see mapAsset()})
     * @param string $query non-empty search text
     * @return array<int, array<string, mixed>>
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function filterAssets(array $items, string $query): array
    {
        $needle = mb_strtolower(trim($query));

        if ($needle === '') {
            return array_values($items);
        }

        return array_values(array_filter($items, static function(array $item) use ($needle): bool {
            foreach (['title', 'passthrough', 'assetId', 'playbackId'] as $key) {
                $value = $item[$key] ?? null;

                if (is_string($value) && $value !== '' && str_contains(mb_strtolower($value), $needle)) {
                    return true;
                }
            }

            return false;
        }));
    }

    /**
     * Drops the cached library snapshot so new uploads are searchable at once.
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function invalidateLibrarySnapshot(): void
    {
        Craft::$app->getCache()->delete(self::SNAPSHOT_CACHE_KEY);
    }

    /**
     * Creates a Mux direct upload URL for browser-side UpChunk upload.
     *
     * @param string $title optional asset title stored in Mux meta
     * @param array $options optional keys: `corsOrigin` (string), `passthrough` (string)
     * @return array{uploadId: string, uploadUrl: string, status: string}
     * @throws Exception when Mux is not configured or the API call fails
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function createDirectUpload(string $title = '', array $options = []): array
    {
        $this->_requireConfigured();

        $newAssetSettings = new CreateAssetRequest([
            'playback_policy' => [PlaybackPolicy::_PUBLIC],
        ]);

        if ($title !== '') {
            $newAssetSettings->setMeta(new AssetMetadata(['title' => $title]));
        }

        if (!empty($options['passthrough']) && is_string($options['passthrough'])) {
            $newAssetSettings->setPassthrough($options['passthrough']);
        }

        $createRequest = new CreateUploadRequest([
            'new_asset_settings' => $newAssetSettings,
            'cors_origin' => $options['corsOrigin'] ?? $this->_defaultCorsOrigin(),
        ]);

        try {
            $response = $this->_getUploadsApi()->createDirectUpload($createRequest);
        } catch (ApiException $e) {
            throw $this->_wrapApiException($e, 'create direct upload');
        }

        $upload = $response->getData();

        if (!$upload instanceof Upload) {
            throw new Exception(Craft::t('polymedia', 'Mux did not return an upload payload.'));
        }

        return $this->mapUpload($upload);
    }

    /**
     * Returns status for a direct upload, including Mux asset id when complete.
     *
     * @param string $uploadId Mux upload id
     * @return array{uploadId: string, uploadUrl: ?string, status: string, assetId: ?string}
     * @throws Exception when Mux is not configured or the API call fails
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getUpload(string $uploadId): array
    {
        $this->_requireConfigured();

        try {
            $response = $this->_getUploadsApi()->getDirectUpload($uploadId);
        } catch (ApiException $e) {
            throw $this->_wrapApiException($e, 'get upload');
        }

        $upload = $response->getData();

        if (!$upload instanceof Upload) {
            throw new Exception(Craft::t('polymedia', 'Mux did not return an upload payload.'));
        }

        return $this->mapUpload($upload);
    }

    /**
     * Returns a single Mux asset as a CP-friendly DTO.
     *
     * @param string $muxAssetId Mux asset id
     * @return array<string, mixed>
     * @throws Exception when Mux is not configured or the API call fails
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getAsset(string $muxAssetId): array
    {
        $this->_requireConfigured();

        try {
            $response = $this->_getAssetsApi()->getAsset($muxAssetId);
        } catch (ApiException $e) {
            throw $this->_wrapApiException($e, 'get asset');
        }

        $asset = $response->getData();

        if (!$asset instanceof Asset) {
            throw new Exception(Craft::t('polymedia', 'Mux did not return an asset payload.'));
        }

        return $this->mapAsset($asset);
    }

    /**
     * Deletes a Mux asset by id.
     *
     * @param string $muxAssetId Mux asset id
     * @return bool true when the API call succeeded
     * @throws Exception when Mux is not configured or the API call fails
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function deleteAsset(string $muxAssetId): bool
    {
        $this->_requireConfigured();

        try {
            $this->_getAssetsApi()->deleteAsset($muxAssetId);
        } catch (ApiException $e) {
            throw $this->_wrapApiException($e, 'delete asset');
        }

        return true;
    }

    /**
     * Builds the first-frame Mux Image API thumbnail URL for a playback id.
     *
     * @param string $playbackId public (or signed) playback id
     * @return string
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function firstFrameThumbnailUrl(string $playbackId): string
    {
        return "https://image.mux.com/{$playbackId}/thumbnail.jpg?time=0";
    }

    /**
     * Maps a Mux Asset model to a plain array for CP/JSON consumers.
     *
     * @param Asset $asset
     * @return array<string, mixed>
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function mapAsset(Asset $asset): array
    {
        $playback = $this->_pickPlaybackId($asset);
        $playbackId = $playback['id'];
        $title = '';
        $meta = $asset->getMeta();

        if ($meta instanceof AssetMetadata && $meta->getTitle()) {
            $title = (string)$meta->getTitle();
        } elseif ($asset->getPassthrough()) {
            $title = (string)$asset->getPassthrough();
        }

        $thumbnailUrl = null;

        if ($playbackId !== null && $playbackId !== '') {
            $thumbnailUrl = $this->firstFrameThumbnailUrl($playbackId);
        }

        return [
            'assetId' => $asset->getId(),
            'playbackId' => $playbackId,
            'playbackPolicy' => $playback['policy'],
            'title' => $title,
            'status' => $asset->getStatus(),
            'duration' => $asset->getDuration(),
            'aspectRatio' => $asset->getAspectRatio(),
            'thumbnailUrl' => $thumbnailUrl,
            'createdAt' => $asset->getCreatedAt(),
            'passthrough' => $asset->getPassthrough(),
        ];
    }

    /**
     * Maps a Mux Upload model to a plain array for CP/JSON consumers.
     *
     * @param Upload $upload
     * @return array{uploadId: string, uploadUrl: ?string, status: string, assetId: ?string}
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function mapUpload(Upload $upload): array
    {
        return [
            'uploadId' => (string)$upload->getId(),
            'uploadUrl' => $upload->getUrl(),
            'status' => (string)$upload->getStatus(),
            'assetId' => $upload->getAssetId(),
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the newest {@see self::SEARCH_SCAN_LIMIT} assets, cached briefly.
     *
     * Pages through the Mux list API at 100/page. Cached for
     * {@see self::SNAPSHOT_CACHE_TTL} seconds so per-keystroke searches cost
     * zero API calls after the first; {@see invalidateLibrarySnapshot()} drops
     * it when an upload completes.
     *
     * @return array{items: array<int, array<string, mixed>>, scanLimited: bool}
     * @throws Exception
     */
    private function _getLibrarySnapshot(): array
    {
        $cache = Craft::$app->getCache();
        $cached = $cache->get(self::SNAPSHOT_CACHE_KEY);

        if (is_array($cached) && isset($cached['items'], $cached['scanLimited'])) {
            return $cached;
        }

        $items = [];
        $page = 1;
        $pageSize = 100;
        $scanLimited = false;

        do {
            $result = $this->listAssets($pageSize, $page);
            $count = count($result['items']);
            $items = array_merge($items, $result['items']);
            $page++;

            if (count($items) >= self::SEARCH_SCAN_LIMIT) {
                // A full final page suggests more assets beyond the window.
                $scanLimited = $count === $pageSize;
                $items = array_slice($items, 0, self::SEARCH_SCAN_LIMIT);
                break;
            }
        } while ($count === $pageSize);

        $snapshot = ['items' => $items, 'scanLimited' => $scanLimited];
        $cache->set(self::SNAPSHOT_CACHE_KEY, $snapshot, self::SNAPSHOT_CACHE_TTL);

        return $snapshot;
    }

    /**
     * @throws Exception
     */
    private function _requireConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new Exception(Craft::t(
                'polymedia',
                'Mux is not configured. Add a Token ID and Secret in Polymedia settings.',
            ));
        }
    }

    private function _getConfig(): Configuration
    {
        if ($this->_config === null) {
            $this->_config = Configuration::getDefaultConfiguration()
                ->setUsername($this->getTokenId())
                ->setPassword($this->getTokenSecret());
        }

        return $this->_config;
    }

    private function _getAssetsApi(): AssetsApi
    {
        if ($this->_assetsApi === null) {
            $this->_assetsApi = new AssetsApi(new Client(), $this->_getConfig());
        }

        return $this->_assetsApi;
    }

    private function _getUploadsApi(): DirectUploadsApi
    {
        if ($this->_uploadsApi === null) {
            $this->_uploadsApi = new DirectUploadsApi(new Client(), $this->_getConfig());
        }

        return $this->_uploadsApi;
    }

    /**
     * Prefers a public playback ID; falls back to the first available id.
     *
     * @param Asset $asset
     * @return array{id: ?string, policy: ?string}
     */
    private function _pickPlaybackId(Asset $asset): array
    {
        $playbackIds = $asset->getPlaybackIds() ?? [];
        $fallback = null;

        foreach ($playbackIds as $playbackId) {
            $id = $playbackId->getId();
            $policyValue = $this->_playbackPolicyValue($playbackId);

            $entry = ['id' => $id, 'policy' => $policyValue];

            if ($policyValue === PlaybackPolicy::_PUBLIC) {
                return $entry;
            }

            $fallback ??= $entry;
        }

        return $fallback ?? ['id' => null, 'policy' => null];
    }

    /**
     * Normalizes a playback policy from the Mux SDK to a plain string.
     *
     * The OpenAPI client types this as {@see PlaybackPolicy}, but at runtime
     * the value is the string enum (`public`, `signed`, `drm`).
     *
     * @param PlaybackID $playbackId
     * @return ?string
     */
    private function _playbackPolicyValue(PlaybackID $playbackId): ?string
    {
        /** @var mixed $policy */
        $policy = $playbackId->getPolicy();

        if ($policy === null || $policy === '') {
            return null;
        }

        if (is_string($policy)) {
            return $policy;
        }

        if (is_scalar($policy)) {
            return (string)$policy;
        }

        return null;
    }

    private function _defaultCorsOrigin(): string
    {
        /** @var \craft\web\Application $app */
        $app = Craft::$app;
        $request = $app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return '*';
        }

        $hostInfo = $request->getHostInfo();

        return $hostInfo !== '' ? $hostInfo : '*';
    }

    private function _wrapApiException(ApiException $e, string $action): Exception
    {
        Craft::error(
            "Mux API {$action} failed: " . $e->getMessage(),
            __METHOD__,
        );

        return new Exception(Craft::t(
            'polymedia',
            'Mux API request failed ({action}): {message}',
            [
                'action' => $action,
                'message' => $e->getMessage(),
            ],
        ), (int)$e->getCode(), $e);
    }
}
