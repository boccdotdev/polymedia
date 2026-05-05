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

namespace boccdotdev\polymedia\variables;

use boccdotdev\polymedia\Plugin;
use craft\elements\Asset;
use Twig\Markup;

/**
 * Twig variable for `craft.polymedia.*` calls.
 *
 * Proxies to the Renderer service for player rendering, script loading,
 * and asset introspection.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class PolymediaVariable
{
    // Public Methods
    // =========================================================================

    /**
     * Renders a full `<media-controller>` player.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options
     * @return Markup
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function player(Asset $asset, array $options = []): Markup
    {
        return Plugin::getInstance()->getRenderer()->player($asset, $options);
    }

    /**
     * Renders just the media element (no controller wrapper).
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options
     * @return Markup
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function element(Asset $asset, array $options = []): Markup
    {
        return Plugin::getInstance()->getRenderer()->element($asset, $options);
    }

    /**
     * Returns parsed manifest data for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return array
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function data(Asset $asset): array
    {
        return Plugin::getInstance()->getRenderer()->data($asset);
    }

    /**
     * Checks whether a value is a polymedia asset.
     *
     * @param mixed $asset the value to check
     * @return bool
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function is(mixed $asset): bool
    {
        return Plugin::getInstance()->getRenderer()->is($asset);
    }

    /**
     * Renders `<script type="module">` tags for Media Chrome and providers.
     *
     * @param array $opts options: `providers`, `version`, `mode`
     * @return Markup
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function scripts(array $opts = []): Markup
    {
        return Plugin::getInstance()->getRenderer()->scripts($opts);
    }

    /**
     * Returns the resolved poster URL for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return ?string
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function poster(Asset $asset): ?string
    {
        return Plugin::getInstance()->getRenderer()->poster($asset);
    }

    /**
     * Returns track-type related assets (captions, subtitles, descriptions).
     *
     * @param Asset $asset the `.pmedia` asset
     * @param string $role the track role
     * @param ?int $siteId optional site filter
     * @return Asset[]
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function tracks(Asset $asset, string $role = 'captions', ?int $siteId = null): array
    {
        return Plugin::getInstance()->getRenderer()->tracks($asset, $role, $siteId);
    }

    /**
     * Returns the transcript related asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return ?Asset
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function transcript(Asset $asset): ?Asset
    {
        return Plugin::getInstance()->getRenderer()->transcript($asset);
    }
}
