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
 * Everything a player engine needs to render one media item.
 *
 * The Renderer resolves it once (manifest, merged playback settings, poster,
 * tracks, aspect ratio) so engines only decide markup.
 *
 * @author boccdotdev
 * @since 2.4.0
 */
class RenderContext extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var array The manifest from `Renderer::data()`.
     */
    public array $manifest = [];

    /**
     * @var string The provider type (`mux`, `youtube`, `audio`, …).
     */
    public string $type = '';

    /**
     * @var string The media element tag stored for the item (`mux-video`, `video`, …).
     */
    public string $elementTag = 'video';

    /**
     * @var bool Whether the type is audio-only.
     */
    public bool $isAudio = false;

    /**
     * @var PlayerSettings The item defaults merged with template options.
     */
    public PlayerSettings $settings;

    /**
     * @var ?string The resolved poster URL.
     */
    public ?string $poster = null;

    /**
     * @var array<int, array<string, string>> `<track>` attributes in render order.
     */
    public array $tracks = [];

    /**
     * @var ?string The CSS `aspect-ratio` value, e.g. `16 / 9`, when known.
     */
    public ?string $aspectRatio = null;

    /**
     * @var array The raw template options.
     */
    public array $options = [];
}
