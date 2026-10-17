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

namespace boccdotdev\polymedia\base;

use boccdotdev\polymedia\models\RenderContext;
use craft\errors\SiteNotFoundException;

/**
 * A front-end player library that Polymedia can render markup and script tags for.
 *
 * @author boccdotdev
 * @since 2.4.0
 */
interface PlayerEngineInterface
{
    /**
     * Returns the full player markup for a media item.
     *
     * @param RenderContext $context the resolved item
     * @return string
     * @throws SiteNotFoundException if an engine reads the current site and there is none
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    public function player(RenderContext $context): string;

    /**
     * Returns the bare media element markup, with native controls when enabled.
     *
     * @param RenderContext $context the resolved item
     * @return string
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    public function element(RenderContext $context): string;

    /**
     * Returns the tags that load the engine and its provider elements.
     *
     * @param string[] $providers provider keys to load
     * @param string $mode `cdn` or `self-host`; engines emit nothing for any other value
     * @param array $options the raw `scripts()` options
     * @return string
     * @throws SiteNotFoundException if an engine reads the current site and there is none
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    public function scripts(array $providers, string $mode, array $options): string;
}
