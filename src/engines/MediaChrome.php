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

namespace boccdotdev\polymedia\engines;

use boccdotdev\polymedia\base\PlayerEngine;
use boccdotdev\polymedia\models\RenderContext;
use boccdotdev\polymedia\Plugin;
use craft\helpers\Html;

/**
 * Renders `<media-controller>` players and loads Media Chrome with the
 * standalone `*-video-element` provider packages.
 *
 * @author boccdotdev
 * @since 2.4.0
 */
class MediaChrome extends PlayerEngine
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, string> Provider key to npm package.
     */
    public const SCRIPT_MAP = [
        'hls' => 'hls-video-element@1',
        'bunny' => 'hls-video-element@1',
        'dash' => 'dash-video-element@0',
        'shaka' => 'shaka-video-element@0',
        'mux' => '@mux/mux-video@0',
        'youtube' => 'youtube-video-element@1',
        'vimeo' => 'vimeo-video-element@1',
        'spotify' => 'spotify-audio-element@1',
        'tiktok' => 'tiktok-video-element@0',
        'wistia' => 'wistia-video-element@1',
        'jwplayer' => 'jwplayer-video-element@0',
        'twitch' => 'twitch-video-element@0',
        'cloudflare' => 'cloudflare-video-element@0',
        'peertube' => 'peertube-video-element@1',
        'videojs' => 'videojs-video-element@0',
    ];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function player(RenderContext $context): string
    {
        $options = $context->options;
        $controllerAttrs = [];

        if ($context->isAudio) {
            $controllerAttrs['audio'] = true;
        }

        if (isset($options['class'])) {
            $controllerAttrs['class'] = $options['class'];
        }

        if (isset($options['id'])) {
            $controllerAttrs['id'] = $options['id'];
        }

        foreach ($options['attrs'] ?? [] as $k => $v) {
            $controllerAttrs[$k] = $v;
        }

        // Native controls fight Media Chrome's controller UI — omit on player().
        $mediaHtml = Html::beginTag($context->elementTag, $this->_mediaAttributes($context, false))
            . $this->tracksHtml($context->tracks)
            . Html::endTag($context->elementTag);

        if ($context->isAudio && $context->poster !== null) {
            $mediaHtml .= Html::tag('img', '', [
                'slot' => 'poster',
                'src' => $context->poster,
                'alt' => $context->manifest['title'] ?? '',
                'class' => 'polymedia-cover',
            ]);
        }

        $children = $options['children'] ?? '';

        if ($children === '' && $context->isAudio) {
            $children = $this->_defaultAudioControls();
        }

        return Html::beginTag('media-controller', $controllerAttrs)
            . $mediaHtml
            . $children
            . Html::endTag('media-controller');
    }

    /**
     * @inheritdoc
     */
    public function element(RenderContext $context): string
    {
        // element() has no media-controller; emit native controls when requested.
        $attrs = $this->_mediaAttributes($context, true);
        unset($attrs['slot']);

        return Html::beginTag($context->elementTag, $attrs) . Html::endTag($context->elementTag);
    }

    /**
     * @inheritdoc
     */
    public function scripts(array $providers, string $mode, array $options): string
    {
        $version = $options['version'] ?? Plugin::getInstance()->getSettings()->mediaChromeVersion;
        $tags = [];

        if ($mode === 'cdn') {
            $cdnHost = $this->cdnHost();
            $tags[] = Html::tag('script', '', [
                'type' => 'module',
                'src' => "https://{$cdnHost}/npm/media-chrome@{$version}/+esm",
            ]);

            foreach ($providers as $provider) {
                $pkg = self::SCRIPT_MAP[$provider] ?? null;

                if ($pkg) {
                    $tags[] = Html::tag('script', '', [
                        'type' => 'module',
                        'src' => "https://{$cdnHost}/npm/{$pkg}/+esm",
                    ]);
                }
            }
        } elseif ($mode === 'self-host') {
            $base = $this->selfHostBaseUrl();
            $tags[] = Html::tag('script', '', [
                'type' => 'module',
                'src' => "{$base}/media-chrome.min.js",
            ]);

            foreach ($providers as $provider) {
                $pkg = self::SCRIPT_MAP[$provider] ?? null;

                if ($pkg) {
                    $name = str_contains($pkg, '/') ? explode('/', $pkg)[1] : $pkg;
                    $name = preg_replace('/@\d+$/', '', $name);
                    $tags[] = Html::tag('script', '', [
                        'type' => 'module',
                        'src' => "{$base}/{$name}.min.js",
                    ]);
                }
            }
        }

        // Providers can share a package (`hls` and `bunny`).
        return implode("\n", array_unique($tags));
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds the media element attributes.
     *
     * @param RenderContext $context the resolved item
     * @param bool $emitControls whether to emit the native `controls` attribute
     *        (true for bare `element()`; false inside `player()` where Media
     *        Chrome supplies the control UI)
     * @return array<string, mixed>
     */
    private function _mediaAttributes(RenderContext $context, bool $emitControls): array
    {
        $manifest = $context->manifest;
        $attrs = ['slot' => 'media'];

        if ($context->elementTag === 'mux-video') {
            $attrs['playback-id'] = $manifest['providerId'] ?? '';

            if (!isset($manifest['streamType'])) {
                $attrs['stream-type'] = 'on-demand';
            }
        } else {
            $attrs['src'] = $context->src;
        }

        $attrs += $this->playbackAttributes($context->settings, $emitControls);

        if ($context->poster !== null && !$context->isAudio) {
            $attrs['poster'] = $context->poster;
        }

        // Template-supplied attrs on the media element (not the controller).
        foreach ($context->options['mediaAttrs'] ?? [] as $k => $v) {
            $attrs[$k] = $v;
        }

        return $attrs;
    }

    /**
     * Returns default `<media-control-bar>` markup for audio players.
     *
     * Media Chrome does not provide default controls in audio mode,
     * so we inject a sensible default set when no children are supplied.
     *
     * @return string
     */
    private function _defaultAudioControls(): string
    {
        return Html::beginTag('media-control-bar')
            . Html::tag('media-play-button', '')
            . Html::tag('media-time-display', '', ['showduration' => true])
            . Html::tag('media-time-range', '')
            . Html::tag('media-playback-rate-button', '')
            . Html::tag('media-mute-button', '')
            . Html::tag('media-volume-range', '')
            . Html::endTag('media-control-bar');
    }
}
