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

use boccdotdev\polymedia\models\DetectionResult;
use yii\base\Component;

/**
 * URL detection service.
 *
 * Given any URL string, returns the detected media type and provider-specific data.
 * Pure regex / URL parsing — no HTTP calls.
 *
 * Detection rules are ordered by priority: provider-specific patterns first
 * (Mux before HLS, YouTube/Vimeo before generic), file-extension fallbacks last.
 * First match wins.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class UrlDetector extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var list<array{
     *     regex: string,
     *     type: string,
     *     element: string,
     *     idExtractor?: callable(array): void,
     *     hints?: callable(array): array
     * }>
     */
    private const RULES = [
        // Mux — BEFORE HLS (stream.mux.com m3u8 URLs)
        [
            'regex' => '~stream\.mux\.com/([A-Za-z0-9]+)(?:\.m3u8|/|$)~',
            'type' => 'mux',
            'element' => 'mux-video',
        ],
        // YouTube
        [
            'regex' => '~(?:youtube\.com/(?:watch\?(?:.*&)?v=|shorts/|embed/|v/|live/)|youtu\.be/)([A-Za-z0-9_-]{11})~',
            'type' => 'youtube',
            'element' => 'youtube-video',
        ],
        // Vimeo
        [
            'regex' => '~vimeo\.com/(?:video/|channels/[^/]+/|groups/[^/]+/videos/|album/\d+/video/|showcase/\d+/video/)?(\d+)(?:/([a-zA-Z0-9]+))?~',
            'type' => 'vimeo',
            'element' => 'vimeo-video',
        ],
        // Spotify
        [
            'regex' => '~open\.spotify\.com/(?:intl-[a-z]+/)?(track|episode|playlist|album|show|artist)/([A-Za-z0-9]{22})~',
            'type' => 'spotify',
            'element' => 'spotify-audio',
        ],
        // TikTok — standard and short URLs
        [
            'regex' => '~tiktok\.com/(?:@[^/]+/video/(\d+)|t/([A-Za-z0-9]+))~',
            'type' => 'tiktok',
            'element' => 'tiktok-video',
        ],
        [
            'regex' => '~vm\.tiktok\.com/([A-Za-z0-9]+)~',
            'type' => 'tiktok',
            'element' => 'tiktok-video',
        ],
        // Wistia
        [
            'regex' => '~([a-z0-9]+)\.wistia\.com/medias/([A-Za-z0-9]+)~',
            'type' => 'wistia',
            'element' => 'wistia-video',
        ],
        [
            'regex' => '~wistia\.com/embed/(?:medias|iframe)/([A-Za-z0-9]+)~',
            'type' => 'wistia',
            'element' => 'wistia-video',
        ],
        // JW Player
        [
            'regex' => '~cdn\.jwplayer\.com/(?:players|previews|videos)/([A-Za-z0-9]+)~',
            'type' => 'jwplayer',
            'element' => 'jwplayer-video',
        ],
        [
            'regex' => '~content\.jwplatform\.com/manifests/([A-Za-z0-9]+)\.m3u8~',
            'type' => 'jwplayer',
            'element' => 'jwplayer-video',
        ],
        // Twitch — VODs, clips, channels
        [
            'regex' => '~twitch\.tv/videos/(\d+)~',
            'type' => 'twitch',
            'element' => 'twitch-video',
        ],
        [
            'regex' => '~clips\.twitch\.tv/([A-Za-z0-9_-]+)~',
            'type' => 'twitch',
            'element' => 'twitch-video',
        ],
        [
            'regex' => '~twitch\.tv/([A-Za-z0-9_]+)$~',
            'type' => 'twitch',
            'element' => 'twitch-video',
        ],
        // Cloudflare Stream — BEFORE HLS (videodelivery.net can serve m3u8)
        [
            'regex' => '~(?:videodelivery\.net|customer-[a-z0-9]+\.cloudflarestream\.com)/([a-f0-9]+)~',
            'type' => 'cloudflare',
            'element' => 'cloudflare-video',
        ],
        // HLS (generic .m3u8)
        [
            'regex' => '~\.m3u8(?:[?#]|$)~i',
            'type' => 'hls',
            'element' => 'hls-video',
        ],
        // DASH (generic .mpd)
        [
            'regex' => '~\.mpd(?:[?#]|$)~i',
            'type' => 'dash',
            'element' => 'dash-video',
        ],
        // MP4 / WebM / MOV
        [
            'regex' => '~\.(mp4|webm|mov)(?:[?#]|$)~i',
            'type' => 'mp4',
            'element' => 'video',
        ],
        // Audio
        [
            'regex' => '~\.(mp3|m4a|ogg|wav|flac)(?:[?#]|$)~i',
            'type' => 'audio',
            'element' => 'audio',
        ],
    ];

    // Public Methods
    // =========================================================================

    /**
     * Detect the media type and extract relevant metadata from a URL.
     *
     * @param string $url the URL to detect
     * @param ?string $typeOverride force a specific type (for Shaka, Video.js, PeerTube, or manual override)
     * @return ?DetectionResult null if the URL cannot be classified as media
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function detect(string $url, ?string $typeOverride = null): ?DetectionResult
    {
        $url = trim($url);

        if ($url === '') {
            return null;
        }

        if ($typeOverride !== null) {
            return $this->_detectWithOverride($url, $typeOverride);
        }

        return $this->_detectAuto($url);
    }

    /**
     * Returns the full map of type keys to element tag names.
     *
     * @return array<string, string>
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getElementMap(): array
    {
        return [
            'hls' => 'hls-video',
            'dash' => 'dash-video',
            'shaka' => 'shaka-video',
            'mux' => 'mux-video',
            'youtube' => 'youtube-video',
            'vimeo' => 'vimeo-video',
            'spotify' => 'spotify-audio',
            'tiktok' => 'tiktok-video',
            'wistia' => 'wistia-video',
            'jwplayer' => 'jwplayer-video',
            'twitch' => 'twitch-video',
            'cloudflare' => 'cloudflare-video',
            'peertube' => 'peertube-video',
            'videojs' => 'videojs-video',
            'mp4' => 'video',
            'audio' => 'audio',
        ];
    }

    /**
     * Returns all known provider type keys.
     *
     * @return string[]
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getProviderTypes(): array
    {
        return array_keys($this->getElementMap());
    }

    // Private Methods
    // =========================================================================

    /**
     * Auto-detect media type from URL using the rules table.
     *
     * @param string $url the URL to detect
     * @return ?DetectionResult
     */
    private function _detectAuto(string $url): ?DetectionResult
    {
        foreach (self::RULES as $rule) {
            if (!preg_match($rule['regex'], $url, $matches)) {
                continue;
            }

            $result = new DetectionResult();
            $result->type = $rule['type'];
            $result->element = $rule['element'];
            $result->url = $url;

            $this->_extractId($result, $rule, $matches);

            return $result;
        }

        return null;
    }

    /**
     * Detect with a forced type override.
     *
     * @param string $url the URL
     * @param string $typeOverride the forced type key
     * @return ?DetectionResult
     */
    private function _detectWithOverride(string $url, string $typeOverride): ?DetectionResult
    {
        $elementMap = $this->getElementMap();

        if (!isset($elementMap[$typeOverride])) {
            return null;
        }

        // Try auto-detection first to extract provider ID
        $autoResult = $this->_detectAuto($url);

        $result = new DetectionResult();
        $result->type = $typeOverride;
        $result->element = $elementMap[$typeOverride];
        $result->url = $url;
        $result->providerId = $autoResult->providerId ?? '';
        $result->hints = $autoResult->hints ?? [];

        return $result;
    }

    /**
     * Extract provider ID and hints from regex matches based on rule type.
     *
     * @param DetectionResult $result the result to populate
     * @param array $rule the matching rule
     * @param array $matches regex matches
     */
    private function _extractId(DetectionResult $result, array $rule, array $matches): void
    {
        match ($rule['type']) {
            'mux' => $result->providerId = $matches[1] ?? '',
            'youtube' => $result->providerId = $matches[1] ?? '',
            'vimeo' => $result->providerId = $matches[1] ?? '',
            'spotify' => $this->_extractSpotify($result, $matches),
            'tiktok' => $result->providerId = $matches[1] ?? $matches[2] ?? '',
            'wistia' => $this->_extractWistia($result, $rule, $matches),
            'jwplayer' => $result->providerId = $matches[1] ?? '',
            'twitch' => $result->providerId = $matches[1] ?? '',
            'cloudflare' => $result->providerId = $matches[1] ?? '',
            default => null,
        };
    }

    /**
     * Extract Spotify provider ID and sub-type hint.
     *
     * @param DetectionResult $result the result to populate
     * @param array $matches regex matches
     */
    private function _extractSpotify(DetectionResult $result, array $matches): void
    {
        $result->hints['subType'] = $matches[1] ?? '';
        $result->providerId = $matches[2] ?? '';
    }

    /**
     * Extract Wistia provider ID from different URL patterns.
     *
     * @param DetectionResult $result the result to populate
     * @param array $rule the matching rule
     * @param array $matches regex matches
     */
    private function _extractWistia(DetectionResult $result, array $rule, array $matches): void
    {
        // Two Wistia patterns: domain/medias/{id} (match[2]) and embed/{type}/{id} (match[1])
        if (str_contains($rule['regex'], 'embed')) {
            $result->providerId = $matches[1] ?? '';
        } else {
            $result->providerId = $matches[2] ?? '';
            if (isset($matches[1])) {
                $result->hints['subdomain'] = $matches[1];
            }
        }
    }
}
