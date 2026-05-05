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

namespace boccdotdev\polymedia\models;

use craft\base\Model;

/**
 * Result of URL detection from the UrlDetector service.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class DetectionResult extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Media type key (e.g. `youtube`, `hls`, `mux`, `audio`).
     */
    public string $type = '';

    /**
     * @var string Extracted provider-specific ID (YouTube video ID, Mux playback ID, etc.).
     */
    public string $providerId = '';

    /**
     * @var string Original URL, normalized.
     */
    public string $url = '';

    /**
     * @var string Web component element tag to use (e.g. `youtube-video`, `hls-video`).
     */
    public string $element = '';

    /**
     * @var array Provider-specific hints (e.g. Spotify sub-type: track, episode, playlist).
     */
    public array $hints = [];
}
