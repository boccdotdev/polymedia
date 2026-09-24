<?php

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\Plugin;
use craft\helpers\App;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Promise\Utils;
use Psr\Http\Message\ResponseInterface;
use yii\base\Component;

/**
 * One library's Stream API and anonymous native playback.
 *
 * API credentials are only sent to the fixed Stream API origin. CDN requests
 * never carry credentials, cookies, a referer, or follow redirects.
 */
class Bunny extends Component
{
    public const API_BASE = 'https://video.bunnycdn.com';
    public const TUS_ENDPOINT = self::API_BASE . '/tusupload';
    private const CDN_OPTIONS = [
        'allow_redirects' => false, 'http_errors' => false,
        'timeout' => 5, 'connect_timeout' => 3, 'stream' => true,
    ];

    /** Injectable transport for fixture tests. */
    public ?ClientInterface $client = null;
    /** @var array<string, ?bool> */
    private array $_playbackChecks = [];
    private array $_mp4Checks = [];

    public function isConfigured(): bool
    {
        try {
            self::validateLibraryId($this->getLibraryId());
            self::validateHostname($this->getCdnHostname());
            return $this->getApiKey() !== '';
        } catch (\InvalidArgumentException) {
            return false;
        }
    }

    public function getLibraryId(): string
    {
        return $this->setting('bunnyLibraryId');
    }

    public function getCdnHostname(): string
    {
        return strtolower($this->setting('bunnyCdnHostname'));
    }

    protected function getApiKey(): string
    {
        return $this->setting('bunnyApiKey', true);
    }

    public function getWebhookKey(): string
    {
        return $this->setting('bunnyWebhookKey', true);
    }

    private function setting(string $name, bool $secret = false): string
    {
        $raw = (string)(Plugin::getInstance()->getSettings()->$name ?? '');
        if ($secret && !preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/D', $raw)) {
            return '';
        }
        $value = (string)(App::parseEnv($raw) ?: '');
        return $value === $raw && str_starts_with($raw, '$') ? '' : trim($value);
    }

    public static function validateLibraryId(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Bunny library ID.');
        }
        return $id;
    }

    public static function validateVideoId(string $id): string
    {
        if (!preg_match('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $id)) {
            throw new \InvalidArgumentException('Invalid Bunny video ID.');
        }
        return $id;
    }

    public static function validateHostname(string $host): string
    {
        // Native pull-zone hosts only. This also keeps server-side probes away
        // from arbitrary hosts, private addresses, ports, paths and userinfo.
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.b-cdn\.net$/D', $host)) {
            throw new \InvalidArgumentException('Use the native Bunny CDN hostname, such as vz-example.b-cdn.net, without a scheme or path.');
        }
        return $host;
    }

    public static function playbackId(string $libraryId, string $videoId): string
    {
        return self::validateLibraryId($libraryId) . ':' . self::validateVideoId($videoId);
    }

    public static function hlsUrl(string $host, string $videoId): string
    {
        return 'https://' . self::validateHostname($host) . '/' . self::validateVideoId($videoId) . '/playlist.m3u8';
    }

    public static function thumbnailUrl(string $host, string $videoId, string $filename): ?string
    {
        self::validateHostname($host);
        self::validateVideoId($videoId);
        if (!preg_match('/^[a-zA-Z0-9_-]+\.(?:jpg|jpeg|png|webp)$/D', $filename)) {
            return null;
        }
        return "https://{$host}/{$videoId}/{$filename}";
    }

    public function listAssets(int $limit = 25, int $page = 1, string $search = ''): array
    {
        $limit = max(1, min(100, $limit));
        $page = max(1, $page);
        $data = $this->api('GET', '/videos', ['query' => [
            'itemsPerPage' => $limit,
            'page' => $page,
            'search' => trim($search),
            'orderBy' => 'date',
        ]]);
        return [
            'items' => array_map(fn(array $video) => $this->mapVideo($video), $data['items'] ?? []),
            'page' => $page,
            'limit' => $limit,
            'total' => (int)($data['totalItems'] ?? 0),
            'hasMore' => $page * $limit < (int)($data['totalItems'] ?? 0),
        ];
    }

    /**
     * A stored host can be supplied for an existing import. Changing settings
     * must never silently rewrite an imported video's playback origin.
     */
    public function getAsset(string $videoId, ?string $hostname = null, bool $discoverMp4 = true): array
    {
        self::validateVideoId($videoId);
        try {
            $video = $this->api('GET', '/videos/' . $videoId);
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
        if ($mapped['status'] === 'ready') {
            $mapped['isPublic'] = $this->_playbackChecks[$mapped['url']] ??= $this->probePublicPlayback($mapped['url']);
            $mapped['playbackPolicy'] = match ($mapped['isPublic']) {
                true => 'public',
                false => 'unsupported',
                null => null,
            };
            if (!$discoverMp4) {
                unset($mapped['mp4Renditions']);
            } elseif ($mapped['isPublic'] === true && !empty($video['hasMP4Fallback'])) {
                // An unavailable optional MP4 must not block playable HLS.
                // Omitting this key preserves last-known renditions during sync.
                try {
                    $key = $mapped['url'] . ':' . ($video['availableResolutions'] ?? '');
                    $mapped['mp4Renditions'] = $this->_mp4Checks[$key] ??= $this->verifiedMp4Renditions($video, $mapped['bunnyCdnHostname']);
                } catch (\Throwable) {
                    unset($mapped['mp4Renditions']);
                }
            }
        }
        return $mapped;
    }

    /**
     * VideoModelStatus from the Stream API. Webhook notification status codes
     * are a DIFFERENT enum and must never be passed to this mapper.
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
            // The video API does not establish the library's playback policy.
            'isPublic' => null,
            'playbackPolicy' => null,
            'mp4Renditions' => [],
        ];
    }

    public function createDirectUpload(string $title = '', array $options = []): array
    {
        $video = $this->api('POST', '/videos', ['json' => [
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

    public static function uploadSignature(string $libraryId, string $key, int $expires, string $videoId): string
    {
        return hash('sha256', self::validateLibraryId($libraryId) . $key . $expires . self::validateVideoId($videoId));
    }

    public function getUpload(string $videoId): array
    {
        $asset = $this->getAsset($videoId, discoverMp4: false);
        return $asset + [
            'uploadId' => $videoId,
            'ready' => $asset['status'] === 'ready' && $asset['isPublic'] === true,
            'failed' => in_array($asset['status'], ['errored', 'deleted'], true) || $asset['isPublic'] === false,
            'message' => $asset['isPublic'] === false
                ? 'Native anonymous HLS playback is unavailable. Token, referrer and DRM protected libraries are not supported.'
                : null,
        ];
    }

    public function deleteAsset(string $videoId): void
    {
        $this->api('DELETE', '/videos/' . self::validateVideoId($videoId));
    }

    public static function verifyWebhookSignature(string $body, string $signature, string $version, string $algorithm, string $key): bool
    {
        return $key !== '' && $version === 'v1' && $algorithm === 'hmac-sha256'
            && preg_match('/^[a-f0-9]{64}$/D', $signature) === 1
            && hash_equals(hash_hmac('sha256', $body, $key), $signature);
    }

    private function api(string $method, string $path, array $options = []): array
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
        if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            // Never surface a remote response or request headers containing keys.
            throw new \RuntimeException('Bunny Stream API request failed with HTTP ' . $response->getStatusCode() . '.', $response->getStatusCode());
        }
        $body = (string)$response->getBody();
        return $body === '' ? [] : json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    }

    protected function transport(): ClientInterface
    {
        return $this->client ??= new Client();
    }

    private function cdn(string $method, string $url): ResponseInterface
    {
        return $this->transport()->request($method, $url, self::CDN_OPTIONS);
    }

    /**
     * Check the master and a media playlist without a token or referer.
     * Reject encrypted media, which the native public integration cannot serve.
     */
    private function probePublicPlayback(string $url): ?bool
    {
        for ($depth = 0; $depth < 2; $depth++) {
            $response = $this->cdn('GET', $url);
            try {
                $body = $response->getBody()->read(131073);
            } finally {
                $response->getBody()->close();
            }
            if ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500) {
                throw new \RuntimeException('Bunny playback is temporarily unavailable. Retry synchronization.');
            }
            if (in_array($response->getStatusCode(), [404, 409, 425], true)) {
                // The encoder can report ready before the CDN sees the file.
                return null;
            }
            if ($response->getStatusCode() !== 200 || strlen($body) > 131072 || !str_starts_with(ltrim($body), '#EXTM3U')) {
                return false;
            }
            if (preg_match('/#EXT-X-(?:SESSION-)?KEY:(?!METHOD=NONE)/', $body)) {
                return false;
            }
            if (str_contains($body, '#EXTINF:')) {
                return true;
            }
            // Bunny uses relative resolution/playlist paths. Do not follow an
            // arbitrary URI supplied by a playlist.
            if (!preg_match('/^([a-zA-Z0-9_-]+\/[a-zA-Z0-9_.-]+\.m3u8)\r?$/m', $body, $match)) {
                return false;
            }
            $url = substr($url, 0, (int)strrpos($url, '/') + 1) . $match[1];
        }
        return false;
    }

    private function verifiedMp4Renditions(array $video, string $host): array
    {
        $renditions = [];
        $available = array_map(
            static fn(string $label): int => preg_match('/^([0-9]+)p$/D', trim($label), $match) ? (int)$match[1] : 0,
            explode(',', (string)($video['availableResolutions'] ?? '')),
        );
        // HLS metadata supplies bounded candidates, never evidence of MP4
        // availability. Even a 2160p-only library must pass a real file check.
        $candidates = array_values(array_intersect([240, 360, 480, 540, 720, 1080, 1440, 2160], $available));
        if ($candidates === []) {
            $candidates = [240, 360, 480, 720, 1080];
        }
        $requests = [];
        foreach ($candidates as $height) {
            $url = 'https://' . $host . '/' . self::validateVideoId($video['guid']) . "/play_{$height}p.mp4";
            $requests[$height] = $this->transport()->requestAsync('HEAD', $url, self::CDN_OPTIONS);
        }
        foreach (Utils::settle($requests)->wait() as $height => $result) {
            if ($result['state'] !== 'fulfilled') {
                throw new \RuntimeException('Bunny MP4 availability could not be checked. Retry synchronization.');
            }
            $url = 'https://' . $host . '/' . self::validateVideoId($video['guid']) . "/play_{$height}p.mp4";
            $response = $result['value'];
            $response->getBody()->close();
            if ($response->getStatusCode() === 429 || $response->getStatusCode() >= 500) {
                throw new \RuntimeException('Bunny MP4 availability could not be checked. Retry synchronization.');
            }
            if ($response->getStatusCode() !== 200 || !str_starts_with(strtolower($response->getHeaderLine('Content-Type')), 'video/mp4')) {
                continue;
            }
            $sourceWidth = (int)($video['width'] ?? 0);
            $sourceHeight = (int)($video['height'] ?? 0);
            $renditions[] = [
                'url' => $url, 'status' => 'ready', 'height' => $height,
                'width' => $sourceHeight > 0 ? (int)round($sourceWidth * $height / $sourceHeight) : null,
            ];
        }
        return $renditions;
    }
}
