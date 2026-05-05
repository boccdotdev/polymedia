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
 * Deterministic thumbnail URL derivation.
 *
 * No HTTP calls. No oEmbed. Pure URL transformation based on provider ID.
 * Returns a thumbnail URL for providers with known patterns, or null.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class ThumbnailDeriver extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Derive a thumbnail URL from a detection result.
     *
     * @param DetectionResult $detection the detection result to derive from
     * @return ?string the thumbnail URL, or null if no deterministic pattern exists
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function derive(DetectionResult $detection): ?string
    {
        if ($detection->providerId === '') {
            return null;
        }

        return match ($detection->type) {
            'mux' => "https://image.mux.com/{$detection->providerId}/thumbnail.jpg",
            'youtube' => "https://i.ytimg.com/vi/{$detection->providerId}/maxresdefault.jpg",
            'vimeo' => "https://vumbnail.com/{$detection->providerId}.jpg",
            'cloudflare' => "https://videodelivery.net/{$detection->providerId}/thumbnails/thumbnail.jpg",
            'wistia' => "https://embed-ssl.wistia.com/deliveries/{$detection->providerId}.jpg",
            default => null,
        };
    }

    /**
     * Returns the YouTube fallback thumbnail URL (always exists, lower resolution).
     *
     * @param string $videoId the YouTube video ID
     * @return string the fallback thumbnail URL
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getYouTubeFallback(string $videoId): string
    {
        return "https://i.ytimg.com/vi/{$videoId}/hqdefault.jpg";
    }
}
