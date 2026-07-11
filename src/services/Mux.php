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
            if (!$playbackId instanceof PlaybackID) {
                continue;
            }

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
