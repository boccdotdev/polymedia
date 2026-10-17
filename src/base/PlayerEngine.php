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

use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\Plugin;
use craft\helpers\App;
use craft\helpers\Html;
use yii\base\Component;

/**
 * Markup helpers shared by player engines.
 *
 * @author boccdotdev
 * @since 2.4.0
 */
abstract class PlayerEngine extends Component implements PlayerEngineInterface
{
    // Protected Methods
    // =========================================================================

    /**
     * Returns the playback attributes for a media element.
     *
     * @param PlayerSettings $settings the resolved settings
     * @param bool $emitControls whether to emit the native `controls` attribute
     * @return array<string, mixed>
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    protected function playbackAttributes(PlayerSettings $settings, bool $emitControls): array
    {
        $attrs = [];

        if ($settings->autoplay) {
            $attrs['autoplay'] = true;
        }

        if ($settings->loop) {
            $attrs['loop'] = true;
        }

        if ($settings->muted) {
            $attrs['muted'] = true;
        }

        if ($emitControls && $settings->controls) {
            $attrs['controls'] = true;
        }

        if ($settings->playsinline) {
            $attrs['playsinline'] = true;
        }

        if ($settings->preload !== 'metadata') {
            $attrs['preload'] = $settings->preload;
        }

        if ($settings->crossorigin) {
            $attrs['crossorigin'] = $settings->crossorigin;
        }

        return $attrs;
    }

    /**
     * Renders `<track>` children from resolved track attributes.
     *
     * @param array<int, array<string, string>> $tracks
     * @return string
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    protected function tracksHtml(array $tracks): string
    {
        $html = '';

        foreach ($tracks as $attrs) {
            $html .= Html::tag('track', '', $attrs);
        }

        return $html;
    }

    /**
     * Returns the CDN host, resolving an environment variable reference.
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    protected function cdnHost(): string
    {
        $settings = Plugin::getInstance()->getSettings();

        return App::parseEnv($settings->cdnHost) ?: $settings->cdnHost;
    }

    /**
     * Returns the self-host base URL without a trailing slash.
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    protected function selfHostBaseUrl(): string
    {
        $settings = Plugin::getInstance()->getSettings();
        $base = App::parseEnv($settings->selfHostBaseUrl ?? '') ?: ($settings->selfHostBaseUrl ?? '');

        return rtrim((string)$base, '/');
    }
}
