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

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\helpers\App;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use yii\base\Component;

/**
 * Bunny Stream client for one video library and its native playback host.
 *
 * Credentials come from plugin settings. The API key must be an environment
 * reference; literal keys are treated as unset so they can't reach project
 * config or the API. The key is only sent to the fixed Stream API origin. CDN
 * probes carry no credentials and never follow redirects.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class Bunny extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string The Stream API origin.
     * @since 2.5.0
     */
    public const API_BASE = 'https://video.bunnycdn.com';

    /**
     * @var string The tus endpoint for resumable browser uploads.
     * @since 2.5.0
     */
    public const TUS_ENDPOINT = self::API_BASE . '/tusupload';

    /**
     * @var string Shown when the CDN refuses a playlist request without a token or referrer.
     * @since 2.5.0
     */
    public const PROTECTED_PLAYBACK_MESSAGE = 'Bunny refused anonymous playback. In the library\'s security settings, turn off Block direct URL file access and token authentication, then retry. DRM protected libraries are not supported.';

    /**
     * @var array Guzzle options for anonymous CDN probes.
     */
    private const CDN_OPTIONS = [
        'allow_redirects' => false,
        'http_errors' => false,
        'timeout' => 5,
        'connect_timeout' => 3,
        'stream' => true,
    ];

    /**
     * @var int[] MP4 fallback heights Bunny can encode.
     */
    private const MP4_HEIGHTS = [240, 360, 480, 540, 720, 1080, 1440, 2160];

    // Public Properties
    // =========================================================================

    /**
     * @var ?ClientInterface HTTP transport; injectable for tests.
     * @since 2.5.0
     */
    public ?ClientInterface $client = null;

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, ?bool> Per-request public playback results by playlist URL.
     */
    private array $_playbackChecks = [];

    /**
     * @var array<string, array> Per-request verified MP4 renditions.
     */
    private array $_mp4Checks = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns whether the library ID, API key and native delivery hostname all
     * resolve to valid values.
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function isConfigured(): bool
    {
        try {
            self::validateLibraryId($this->getLibraryId());
            self::validateHostname($this->getCdnHostname());
        } catch (\InvalidArgumentException) {
            return false;
        }

        return $this->getApiKey() !== '';
    }

    /**
     * Returns the resolved Bunny Stream library ID.
     *
     * @return string an empty string when unset
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getLibraryId(): string
    {
        return $this->_setting('bunnyLibraryId');
    }

    /**
     * Returns the resolved native delivery hostname, lowercased.
     *
     * @return string an empty string when unset
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getCdnHostname(): string
    {
        return strtolower($this->_setting('bunnyCdnHostname'));
    }

    /**
     * Returns the resolved webhook verification key (the library's read-only
     * API key), or an empty string when unset or not an environment reference.
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getWebhookKey(): string
    {
        return $this->_setting('bunnyWebhookKey', true);
    }

    /**
     * Verifies a Bunny Stream webhook signature over the raw request body.
     *
     * Pure function, exposed static for unit testing.
     *
     * @param string $body the raw request body
     * @param string $signature the `X-BunnyStream-Signature` header (lowercase hex)
     * @param string $version the `X-BunnyStream-Signature-Version` header
     * @param string $algorithm the `X-BunnyStream-Signature-Algorithm` header
     * @param string $key the library's read-only API key
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function verifyWebhookSignature(string $body, string $signature, string $version, string $algorithm, string $key): bool
    {
        return $key !== ''
            && $version === 'v1'
            && $algorithm === 'hmac-sha256'
            && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals(hash_hmac('sha256', $body, $key), $signature);
    }

    /**
     * Validates a Bunny Stream library ID.
     *
     * @param string $id the library ID
     * @return string the valid ID
     * @throws \InvalidArgumentException when the ID isn't a positive integer
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function validateLibraryId(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Bunny library ID.');
        }

        return $id;
    }

    /**
     * Validates a Bunny Stream video ID (a lowercase GUID).
     *
     * @param string $id the video ID
     * @return string the valid ID
     * @throws \InvalidArgumentException when the ID isn't a GUID
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function validateVideoId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Bunny video ID.');
        }

        return $id;
    }

    /**
     * Validates a native Bunny delivery hostname.
     *
     * Only `*.b-cdn.net` hosts pass. That also keeps server-side probes away
     * from arbitrary hosts, private addresses, ports, paths and userinfo.
     *
     * @param string $host the hostname
     * @return string the valid hostname
     * @throws \InvalidArgumentException when the host isn't a native Bunny host
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function validateHostname(string $host): string
    {
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.b-cdn\.net$/D', $host)) {
            throw new \InvalidArgumentException('Use the native Bunny CDN hostname, such as vz-example.b-cdn.net, without a scheme or path.');
        }

        return $host;
    }

    /**
     * Returns the stored provider ID for a video: `<libraryId>:<videoId>`.
     *
     * @param string $libraryId the library ID
     * @param string $videoId the video ID
     * @return string
     * @throws \InvalidArgumentException when either ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function playbackId(string $libraryId, string $videoId): string
    {
        return self::validateLibraryId($libraryId) . ':' . self::validateVideoId($videoId);
    }

    /**
     * Returns a video's native HLS playlist URL.
     *
     * @param string $host the native delivery hostname
     * @param string $videoId the video ID
     * @return string
     * @throws \InvalidArgumentException when the host or ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function hlsUrl(string $host, string $videoId): string
    {
        return 'https://' . self::validateHostname($host) . '/' . self::validateVideoId($videoId) . '/playlist.m3u8';
    }

    /**
     * Returns a video's thumbnail URL.
     *
     * @param string $host the native delivery hostname
     * @param string $videoId the video ID
     * @param string $filename the thumbnail file name Bunny reports
     * @return ?string null when the file name isn't a plain image name
     * @throws \InvalidArgumentException when the host or ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function thumbnailUrl(string $host, string $videoId, string $filename): ?string
    {
        self::validateHostname($host);
        self::validateVideoId($videoId);

        if (!preg_match('/^[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$/D', $filename)) {
            return null;
        }

        return "https://{$host}/{$videoId}/{$filename}";
    }

    /**
     * Maps a Stream API video status (`VideoModelStatus`) to a Polymedia status.
     *
     * Webhook notification codes are a different enum and must never be
     * passed here.
     *
     * @param int $code the API status code; -1 for a missing video
     * @return string `ready`, `preparing`, `errored`, `deleted` or `unknown`
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function mapStatus(int $code): string
    {
        return match ($code) {
            4, 8 => 'ready',
            5, 6 => 'errored',
            0, 1, 2, 3, 7 => 'preparing',
            -1 => 'deleted',
            default => 'unknown',
        };
    }

    /**
     * Lists or searches the library's videos, newest first.
     *
     * @param int $limit page size, 1 to 100
     * @param int $page 1-based page
     * @param string $search optional search query
     * @return array{items: array<int, array<string, mixed>>, page: int, limit: int, total: int, hasMore: bool}
     * @throws \RuntimeException when Bunny isn't configured, the request fails or a video belongs to another library
     * @throws \InvalidArgumentException when Bunny returns a malformed video
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function listAssets(int $limit = 25, int $page = 1, string $search = ''): array
    {
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $data = $this->_api('GET', '/videos', ['query' => [
            'itemsPerPage' => $limit,
            'page' => $page,
            'search' => trim($search),
            'orderBy' => 'date',
        ]]);
        $total = (int)($data['totalItems'] ?? 0);

        return [
            'items' => array_map(fn(array $video) => $this->mapVideo($video), (array)($data['items'] ?? [])),
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'hasMore' => $page * $limit < $total,
        ];
    }

    /**
     * Returns a video's current state, with public playback checked when ready.
     *
     * A missing video maps to the `deleted` state. Pass the stored host for an
     * existing import: changing settings must never move its playback origin.
     *
     * @param string $videoId the video ID
     * @param ?string $hostname the stored delivery hostname; the setting when null
     * @param bool $discoverMp4 whether to verify MP4 fallback files
     * @return array<string, mixed> see {@see mapVideo()}; `mp4Renditions` is
     *         omitted when MP4 files weren't checked, which keeps the last
     *         known renditions during sync
     * @throws \RuntimeException when Bunny isn't configured, the request fails or the CDN is temporarily unavailable
     * @throws \InvalidArgumentException when an ID or hostname is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getAsset(string $videoId, ?string $hostname = null, bool $discoverMp4 = true): array
    {
        self::validateVideoId($videoId);

        try {
            $video = $this->_api('GET', '/videos/' . $videoId);
        } catch (\RuntimeException $e) {
            if ($e->getCode() !== 404) {
                throw $e;
            }

            return $this->mapVideo([
                'guid' => $videoId,
                'videoLibraryId' => $this->getLibraryId(),
                'status' => -1,
            ], $hostname);
        }

        if (($video['guid'] ?? null) !== $videoId) {
            throw new \RuntimeException('Bunny returned a different video.');
        }

        $mapped = $this->mapVideo($video, $hostname);

        if ($mapped['status'] !== 'ready') {
            return $mapped;
        }

        $mapped['isPublic'] = $this->_playbackChecks[$mapped['url']] ??= $this->_probePublicPlayback($mapped['url']);
        $mapped['playbackPolicy'] = match ($mapped['isPublic']) {
            true => 'public',
            false => 'unsupported',
            null => null,
        };

        if (!$discoverMp4) {
            unset($mapped['mp4Renditions']);

            return $mapped;
        }

        if ($mapped['isPublic'] === true && !empty($video['hasMP4Fallback'])) {
            // An unverifiable optional MP4 must not block playable HLS.
            try {
                $key = $mapped['url'] . ':' . ($video['availableResolutions'] ?? '');
                $mapped['mp4Renditions'] = $this->_mp4Checks[$key] ??= $this->_verifiedMp4Renditions($video, $mapped['bunnyCdnHostname']);
            } catch (\Throwable) {
                unset($mapped['mp4Renditions']);
            }
        }

        return $mapped;
    }

    /**
     * Maps a Stream API video to Polymedia state.
     *
     * Never claims public playback or MP4 files: those come from CDN checks
     * in {@see getAsset()}.
     *
     * @param array $video the API video object
     * @param ?string $hostname the stored delivery hostname; the setting when null
     * @return array<string, mixed>
     * @throws \RuntimeException when the video belongs to another library
     * @throws \InvalidArgumentException when an ID or hostname is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function mapVideo(array $video, ?string $hostname = null): array
    {
        $library = self::validateLibraryId($this->getLibraryId());
        $id = self::validateVideoId((string)($video['guid'] ?? ''));
        $host = self::validateHostname($hostname ?? $this->getCdnHostname());

        if (!isset($video['videoLibraryId']) || (string)$video['videoLibraryId'] !== $library) {
            throw new \RuntimeException('Bunny video does not belong to the configured library.');
        }

        $code = isset($video['status']) ? (int)$video['status'] : -1;

        return [
            'id' => $id,
            'assetId' => $id,
            'videoId' => $id,
            'title' => (string)($video['title'] ?? ''),
            'status' => self::mapStatus($code),
            'bunnyStatus' => $code,
            'bunnyLibraryId' => $library,
            'bunnyVideoId' => $id,
            'bunnyCdnHostname' => $host,
            'duration' => isset($video['length']) ? (float)$video['length'] : null,
            'width' => isset($video['width']) ? (int)$video['width'] : null,
            'height' => isset($video['height']) ? (int)$video['height'] : null,
            'playbackId' => self::playbackId($library, $id),
            'url' => self::hlsUrl($host, $id),
            'thumbnailUrl' => self::thumbnailUrl($host, $id, (string)($video['thumbnailFileName'] ?? '')),
            'isPublic' => null,
            'playbackPolicy' => null,
            'mp4Renditions' => [],
        ];
    }

    /**
     * Deletes a video from the library.
     *
     * @param string $videoId the video ID
     * @throws \RuntimeException when Bunny isn't configured or the request fails
     * @throws \InvalidArgumentException when the ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function deleteAsset(string $videoId): void
    {
        $this->_api('DELETE', '/videos/' . self::validateVideoId($videoId));
    }

    /**
     * Returns the upload signature Bunny's tus endpoint expects.
     *
     * @param string $libraryId the library ID
     * @param string $key the library API key
     * @param int $expires the signature expiry as a Unix timestamp
     * @param string $videoId the video ID
     * @return string
     * @throws \InvalidArgumentException when either ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function uploadSignature(string $libraryId, string $key, int $expires, string $videoId): string
    {
        return hash('sha256', self::validateLibraryId($libraryId) . $key . $expires . self::validateVideoId($videoId));
    }

    /**
     * Creates a video and a short-lived signature for a browser tus upload.
     *
     * The browser gets the signature, never the API key.
     *
     * @param string $title the video title
     * @param array $options optional `expiresIn` seconds (300 to 86400)
     * @return array{uploadId: string, videoId: string, uploadUrl: string, status: string, headers: array<string, string>}
     * @throws \RuntimeException when Bunny isn't configured, the request fails or Bunny returns another library's video
     * @throws \InvalidArgumentException when Bunny returns an invalid video ID
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function createDirectUpload(string $title = '', array $options = []): array
    {
        $video = $this->_api('POST', '/videos', ['json' => [
            'title' => trim($title) !== '' ? trim($title) : 'Untitled video',
        ]]);
        $id = self::validateVideoId((string)($video['guid'] ?? ''));

        if (isset($video['videoLibraryId']) && (string)$video['videoLibraryId'] !== $this->getLibraryId()) {
            throw new \RuntimeException('Bunny returned a video from another library.');
        }

        $expires = time() + max(300, min(86400, (int)($options['expiresIn'] ?? 86400)));

        return [
            'uploadId' => $id,
            'videoId' => $id,
            'uploadUrl' => self::TUS_ENDPOINT,
            'status' => 'waiting',
            'headers' => [
                'AuthorizationSignature' => self::uploadSignature($this->getLibraryId(), $this->getApiKey(), $expires, $id),
                'AuthorizationExpire' => (string)$expires,
                'LibraryId' => $this->getLibraryId(),
                'VideoId' => $id,
            ],
        ];
    }

    /**
     * Returns an uploaded video's processing state for polling.
     *
     * Ready means encoded and publicly playable. MP4 files aren't checked
     * while polling.
     *
     * @param string $videoId the video ID
     * @return array the {@see getAsset()} state plus `uploadId`, `ready`, `failed` and `message`
     * @throws \RuntimeException when Bunny isn't configured, the request fails or the CDN is temporarily unavailable
     * @throws \InvalidArgumentException when the ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getUpload(string $videoId): array
    {
        $asset = $this->getAsset($videoId, discoverMp4: false);

        return $asset + [
            'uploadId' => $videoId,
            'ready' => $asset['status'] === 'ready' && $asset['isPublic'] === true,
            'failed' => in_array($asset['status'], ['errored', 'deleted'], true) || $asset['isPublic'] === false,
            'message' => $asset['isPublic'] === false ? self::PROTECTED_PLAYBACK_MESSAGE : null,
        ];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns the resolved library API key, or an empty string when the
     * setting isn't a set environment reference.
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    protected function getApiKey(): string
    {
        return $this->_setting('bunnyApiKey', true);
    }

    /**
     * Returns the HTTP transport.
     *
     * @return ClientInterface
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    protected function transport(): ClientInterface
    {
        return $this->client ??= Craft::createGuzzleClient();
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves a Bunny setting.
     *
     * @param string $name the settings attribute
     * @param bool $secret whether only an environment reference is accepted
     * @return string the trimmed value, or an empty string when unset
     */
    private function _setting(string $name, bool $secret = false): string
    {
        $raw = (string)(Plugin::getInstance()->getSettings()->$name ?? '');

        if ($raw === '' || ($secret && !preg_match(Settings::ENV_REFERENCE_PATTERN, $raw))) {
            return '';
        }

        $value = App::parseEnv($raw);

        if (!is_string($value) || ($value === $raw && str_starts_with($raw, '$'))) {
            return '';
        }

        return trim($value);
    }

    /**
     * Calls the Stream API for the configured library.
     *
     * @param string $method the HTTP method
     * @param string $path the path below `/library/<id>`
     * @param array $options extra Guzzle options
     * @return array the decoded JSON body
     * @throws \RuntimeException when Bunny isn't configured or the request fails; the code is the HTTP status
     */
    private function _api(string $method, string $path, array $options = []): array
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException('Bunny Stream is not configured. Check the library ID, API key environment variable and native CDN hostname.');
        }

        $response = $this->transport()->request($method, self::API_BASE . '/library/' . $this->getLibraryId() . $path, $options + [
            'headers' => ['AccessKey' => $this->getApiKey(), 'Accept' => 'application/json'],
            'allow_redirects' => false,
            'http_errors' => false,
            'timeout' => 20,
            'connect_timeout' => 5,
        ]);
        $status = $response->getStatusCode();

        if ($status < 200 || $status >= 300) {
            // Never surface the remote body: it may echo request headers.
            throw new \RuntimeException("Bunny Stream API request failed with HTTP {$status}.", $status);
        }

        $body = (string)$response->getBody();

        try {
            $decoded = $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Bunny Stream API returned an invalid response.');
        }

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * Checks that a playlist plays without a token, referrer or key.
     *
     * Follows one level of Bunny's relative variant playlists, never an
     * arbitrary URI from the playlist. Encrypted media is rejected.
     *
     * @param string $url the master playlist URL
     * @return ?bool true when public; false when protected or invalid; null
     *               when the CDN doesn't have the file yet
     * @throws \RuntimeException when the CDN is rate limiting or failing
     */
    private function _probePublicPlayback(string $url): ?bool
    {
        for ($depth = 0; $depth < 2; $depth++) {
            $response = $this->transport()->request('GET', $url, self::CDN_OPTIONS);

            try {
                $body = $response->getBody()->read(131073);
            } finally {
                $response->getBody()->close();
            }

            $status = $response->getStatusCode();

            if ($status === 429 || $status >= 500) {
                throw new \RuntimeException('Bunny playback is temporarily unavailable. Retry synchronization.');
            }

            // The encoder can report ready before the CDN has the playlist.
            if (in_array($status, [404, 409, 425], true)) {
                return null;
            }

            if ($status !== 200 || strlen($body) > 131072 || !str_starts_with(ltrim($body), '#EXTM3U')) {
                return false;
            }

            if (preg_match('/#EXT-X-(?:SESSION-)?KEY:(?!METHOD=NONE)/', $body)) {
                return false;
            }

            if (str_contains($body, '#EXTINF:')) {
                return true;
            }

            if (!preg_match('/^([a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+\.m3u8)\r?$/m', $body, $match)) {
                return false;
            }

            $url = substr($url, 0, (int)strrpos($url, '/') + 1) . $match[1];
        }

        return false;
    }

    /**
     * Returns the MP4 fallback files the CDN actually serves.
     *
     * HLS resolutions only bound the candidates; each file is checked.
     *
     * @param array $video the API video object
     * @param string $host the delivery hostname
     * @return array<int, array{url: string, status: string, height: int, width: ?int}>
     * @throws \RuntimeException when the CDN is rate limiting or failing
     */
    private function _verifiedMp4Renditions(array $video, string $host): array
    {
        $available = array_map(
            static fn(string $label): int => preg_match('/^([0-9]+)p$/D', trim($label), $match) ? (int)$match[1] : 0,
            explode(',', (string)($video['availableResolutions'] ?? '')),
        );
        $candidates = array_values(array_intersect(self::MP4_HEIGHTS, $available));

        if ($candidates === []) {
            $candidates = [240, 360, 480, 720, 1080];
        }

        $videoId = self::validateVideoId((string)$video['guid']);
        $urls = [];
        $requests = [];

        foreach ($candidates as $height) {
            $urls[$height] = "https://{$host}/{$videoId}/play_{$height}p.mp4";
            $requests[$height] = $this->transport()->requestAsync('HEAD', $urls[$height], self::CDN_OPTIONS);
        }

        $sourceWidth = (int)($video['width'] ?? 0);
        $sourceHeight = (int)($video['height'] ?? 0);
        $renditions = [];

        foreach (Utils::settle($requests)->wait() as $height => $result) {
            if ($result['state'] !== 'fulfilled') {
                throw new \RuntimeException('Bunny MP4 availability could not be checked. Retry synchronization.');
            }

            /** @var ResponseInterface $response */
            $response = $result['value'];
            $response->getBody()->close();
            $status = $response->getStatusCode();

            if ($status === 429 || $status >= 500) {
                throw new \RuntimeException('Bunny MP4 availability could not be checked. Retry synchronization.');
            }

            if ($status !== 200 || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'video/mp4')) {
                continue;
            }

            $renditions[] = [
                'url' => $urls[$height],
                'status' => 'ready',
                'height' => $height,
                'width' => $sourceHeight > 0 ? (int)round($sourceWidth * $height / $sourceHeight) : null,
            ];
        }

        return $renditions;
    }
}
