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
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\helpers\Html;

/**
 * Renders Video.js v10 players and loads them from `@videojs/cdn`.
 *
 * A player is `<video-player>` (or `<audio-player>`) around a packaged skin,
 * or around `<media-container>` when the template supplies its own controls.
 * Providers without a v10 element render standalone with their own controls.
 *
 * @author boccdotdev
 * @since 2.4.0
 */
class VideoJs extends PlayerEngine
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, array{video: string, audio: string}> Skin handle
     * ({@see Settings::VIDEOJS_SKINS}) to skin tags.
     */
    public const SKINS = [
        'default' => ['video' => 'video-skin', 'audio' => 'audio-skin'],
        'neutral' => ['video' => 'video-neutral-skin', 'audio' => 'audio-neutral-skin'],
        'compat' => ['video' => 'video-compat-skin', 'audio' => 'audio-compat-skin'],
    ];

    /**
     * @var array<string, array{0: string, 1: string}> Provider type to v10 media tag and CDN entry.
     *
     * Generic HLS uses the hls.js element: `hls-video` can't play MPEG-TS segments.
     */
    public const MEDIA = [
        'hls' => ['hlsjs-video', 'media/hlsjs-video.js'],
        'bunny' => ['hlsjs-video', 'media/hlsjs-video.js'],
        'dash' => ['dash-video', 'media/dash-video.js'],
        'shaka' => ['shaka-video', 'media/shaka-video.js'],
        'mux' => ['mux-video', 'media/mux-video.js'],
        'youtube' => ['youtube-video', 'media/youtube-video.js'],
        'vimeo' => ['vimeo-video', 'media/vimeo-video.js'],
        'spotify' => ['spotify-audio', 'media/spotify-audio.js'],
        'tiktok' => ['tiktok-video', 'media/tiktok-video.js'],
        'wistia' => ['wistia-video', 'media/wistia-video.js'],
        'twitch' => ['twitch-video', 'media/twitch-video.js'],
        'cloudflare' => ['cloudflare-video', 'media/cloudflare-video.js'],
    ];

    /**
     * @var array<string, string> Providers with no v10 element, loaded from
     * their standalone custom element packages.
     */
    public const STANDALONE_PACKAGES = [
        'jwplayer' => 'jwplayer-video-element@0',
        'peertube' => 'peertube-video-element@1',
        'videojs' => 'videojs-video-element@0',
    ];

    /**
     * @var string[] Types rendered as a bare element with the provider's own
     * controls. Spotify's widget is its only working UI.
     */
    public const STANDALONE_TYPES = ['jwplayer', 'peertube', 'videojs', 'spotify'];

    /**
     * @var string[] Locales `@videojs/cdn` ships. English is built in.
     */
    public const LOCALES = [
        'ar', 'az', 'bg', 'bn', 'bs', 'ca', 'cs', 'cy', 'da', 'de', 'el', 'es', 'et', 'eu', 'fa',
        'fi', 'fr', 'gd', 'gl', 'he', 'hi', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'mr',
        'nb', 'ne', 'nl', 'nn', 'oc', 'pl', 'pt', 'pt-BR', 'pt-PT', 'ro', 'ru', 'sk', 'sl', 'sr',
        'sv', 'te', 'th', 'tr', 'uk', 'vi', 'zh', 'zh-CN', 'zh-TW',
    ];

    /**
     * @var string Layout rules the packaged CSS doesn't cover. Zero specificity,
     * so site styles win. Skins are inline until defined, which ignores
     * `aspect-ratio`; `media-container` stays inline even once defined; iframe
     * providers otherwise stay 300×150 inside a skin, and TikTok's 325×578
     * minimum would overflow it; standalone embeds need a block box to fill
     * the width at their ratio.
     */
    public const CSS = ':where(video-skin,video-neutral-skin,video-compat-skin,audio-skin,audio-neutral-skin,audio-compat-skin):not(:defined),:where(media-container){display:block}'
        . ':where(video-skin,video-neutral-skin,video-compat-skin,media-container)>:where(youtube-video,vimeo-video,cloudflare-video,twitch-video,tiktok-video){display:block;width:100%;height:100%;min-width:0;min-height:0}'
        . ':where(jwplayer-video,peertube-video,videojs-video){display:block;width:100%}';

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function player(RenderContext $context): string
    {
        $options = $context->options;

        // Standalone providers use their own UI, so there's nothing to translate.
        if (in_array($context->type, self::STANDALONE_TYPES, true)) {
            $attrs = $this->_boxAttributes($options) + $this->_mediaAttributes($context, true, true);
            $aspectRatio = $this->_aspectRatio($context);

            if ($aspectRatio !== null) {
                Html::addCssStyle($attrs, ['aspect-ratio' => $aspectRatio], false);
            }

            return $this->_media($context, $attrs, false);
        }

        $children = (string)($options['children'] ?? '');
        $custom = $children !== '';
        $kind = $context->isAudio ? 'audio' : 'video';
        $boxTag = $custom ? 'media-container' : $this->_skin($options['skin'] ?? null)[$kind];

        $boxAttrs = $this->_boxAttributes($options);
        $aspectRatio = $this->_aspectRatio($context);

        if ($aspectRatio !== null) {
            // A ratio in the template's own `style` wins.
            Html::addCssStyle($boxAttrs, ['aspect-ratio' => $aspectRatio], false);
        }

        $playerAttrs = [];

        // The skin's poster layer reads the player, not the media element.
        if ($context->poster !== null && !$context->isAudio) {
            $playerAttrs['poster'] = $context->poster;
        }

        $html = Html::beginTag("{$kind}-player", $playerAttrs)
            . Html::beginTag($boxTag, $boxAttrs)
            // Without a skin there's no poster layer, so the media element shows it.
            . $this->_media($context, $this->_mediaAttributes($context, false, $custom), true)
            . $children
            . Html::endTag($boxTag)
            . Html::endTag("{$kind}-player");

        return $this->_localize($html, $options);
    }

    /**
     * @inheritdoc
     */
    public function element(RenderContext $context): string
    {
        return $this->_media($context, $this->_mediaAttributes($context, true, true), false);
    }

    /**
     * @inheritdoc
     */
    public function scripts(array $providers, string $mode, array $options): string
    {
        if (!in_array($mode, ['cdn', 'self-host'], true)) {
            return '';
        }

        $settings = Plugin::getInstance()->getSettings();
        $version = $options['version'] ?? $settings->videojsVersion;
        $cdnHost = $mode === 'cdn' ? $this->cdnHost() : '';
        $base = $mode === 'cdn'
            ? "https://{$cdnHost}/npm/@videojs/cdn@{$version}"
            : $this->selfHostBaseUrl();

        $tags = [
            Html::tag('link', '', ['rel' => 'stylesheet', 'href' => "{$base}/global.css"]),
            Html::tag('style', self::CSS),
        ];
        $src = [];
        $locale = $this->_locale($options['locale'] ?? null);

        // Loading the pack before the player avoids a flash of English labels.
        if ($locale !== null) {
            $src[] = "{$base}/i18n.js";
            $src[] = "{$base}/locales/{$locale}.js";
        }

        // `skins` adds to the configured skin, which players fall back to.
        $skins = array_unique(array_merge([$settings->videojsSkin], (array)($options['skins'] ?? [])));

        // The audio skin is always loaded: native audio has no provider key,
        // and it only adds ~10 KB over the video skin's shared chunks.
        foreach ($skins as $handle) {
            $skin = $this->_skin($handle);
            $src[] = "{$base}/" . $this->_skinEntry($skin['video']);
            $src[] = "{$base}/" . $this->_skinEntry($skin['audio']);
        }

        foreach ($providers as $provider) {
            if (isset(self::MEDIA[$provider])) {
                $src[] = "{$base}/" . self::MEDIA[$provider][1];
                continue;
            }

            $pkg = self::STANDALONE_PACKAGES[$provider] ?? null;

            if ($pkg === null) {
                continue;
            }

            $src[] = $mode === 'cdn'
                ? "https://{$cdnHost}/npm/{$pkg}/+esm"
                : "{$base}/" . preg_replace('/@\d+$/', '', $pkg) . '.min.js';
        }

        foreach (array_unique($src) as $url) {
            $tags[] = Html::tag('script', '', ['type' => 'module', 'src' => $url]);
        }

        return implode("\n", $tags);
    }

    // Private Methods
    // =========================================================================

    /**
     * Renders the media element with its tracks.
     *
     * @param RenderContext $context the resolved item
     * @param array $attrs the element attributes
     * @param bool $withTracks whether to render `<track>` children
     * @return string
     */
    private function _media(RenderContext $context, array $attrs, bool $withTracks): string
    {
        $tag = self::MEDIA[$this->_mediaType($context)][0] ?? $context->elementTag;

        return Html::beginTag($tag, $attrs)
            . ($withTracks ? $this->tracksHtml($context->tracks) : '')
            . Html::endTag($tag);
    }

    /**
     * Returns the type whose v10 element renders the item: `mp4` when an MP4
     * plays (including a hosted item's rendition), else the provider type.
     *
     * @param RenderContext $context the resolved item
     * @return string
     */
    private function _mediaType(RenderContext $context): string
    {
        return $context->format === 'mp4' ? 'mp4' : $context->type;
    }

    /**
     * Builds the media element attributes.
     *
     * @param RenderContext $context the resolved item
     * @param bool $controls whether to emit native `controls` (no player UI)
     * @param bool $poster whether the element shows the poster itself (no skin)
     * @return array<string, mixed>
     */
    private function _mediaAttributes(RenderContext $context, bool $controls, bool $poster): array
    {
        $isMux = $this->_mediaType($context) === 'mux';
        $providerId = (string)($context->manifest['providerId'] ?? '');
        $attrs = [
            // v10's Mux element has no playback-id attribute.
            'src' => $isMux && $providerId !== ''
                ? 'https://stream.mux.com/' . rawurlencode($providerId) . '.m3u8'
                : $context->src,
        ];

        $attrs += $this->playbackAttributes($context->settings, $controls);

        // Mux timeline thumbnails are cross-origin images.
        if ($isMux) {
            $attrs['crossorigin'] ??= 'anonymous';
        }

        if ($poster && $context->poster !== null && !$context->isAudio) {
            $attrs['poster'] = $context->poster;
        }

        foreach ($context->options['mediaAttrs'] ?? [] as $k => $v) {
            $attrs[$k] = $v;
        }

        return $attrs;
    }

    /**
     * Returns the template's `class`, `id` and `attrs` for the visible box.
     *
     * @param array $options the template options
     * @return array<string, mixed>
     */
    private function _boxAttributes(array $options): array
    {
        $attrs = [];

        if (isset($options['class'])) {
            $attrs['class'] = $options['class'];
        }

        if (isset($options['id'])) {
            $attrs['id'] = $options['id'];
        }

        foreach ($options['attrs'] ?? [] as $k => $v) {
            $attrs[$k] = $v;
        }

        return $attrs;
    }

    /**
     * Resolves the box's CSS aspect ratio.
     *
     * `aspectRatio: false` turns it off and a string replaces it. Otherwise the
     * stored ratio, falling back to 16 / 9 for video and nothing for audio.
     *
     * @param RenderContext $context the resolved item
     * @return ?string
     */
    private function _aspectRatio(RenderContext $context): ?string
    {
        $option = $context->options['aspectRatio'] ?? null;

        if ($option === false) {
            return null;
        }

        if (is_string($option) && $option !== '') {
            return $option;
        }

        return $context->aspectRatio ?? ($context->isAudio ? null : '16 / 9');
    }

    /**
     * Returns the skin tags for a handle.
     *
     * An unknown handle falls back to the configured skin, which `scripts()`
     * loads, rather than to one the page may not have defined.
     *
     * @param mixed $handle `default`, `neutral` or `compat`; null for the setting
     * @return array{video: string, audio: string}
     */
    private function _skin(mixed $handle): array
    {
        if (is_string($handle) && isset(self::SKINS[$handle])) {
            return self::SKINS[$handle];
        }

        return self::SKINS[Plugin::getInstance()->getSettings()->videojsSkin] ?? self::SKINS['default'];
    }

    /**
     * Returns the CDN entry that defines a skin tag.
     *
     * @param string $tag e.g. `video-neutral-skin`
     * @return string e.g. `video-neutral.js`
     */
    private function _skinEntry(string $tag): string
    {
        return substr($tag, 0, -strlen('-skin')) . '.js';
    }

    /**
     * Resolves the player locale: the option, else the current site's language.
     *
     * @param mixed $option the `locale` option; false for none
     * @return ?string a locale `@videojs/cdn` ships, or null for English/none
     */
    private function _locale(mixed $option): ?string
    {
        if ($option === false) {
            return null;
        }

        $language = is_string($option) && $option !== ''
            ? $option
            : (string)Craft::$app->getSites()->getCurrentSite()->language;

        foreach ([$language, explode('-', $language)[0]] as $candidate) {
            foreach (self::LOCALES as $locale) {
                if (strcasecmp($locale, $candidate) === 0) {
                    return $locale;
                }
            }
        }

        return null;
    }

    /**
     * Wraps player markup in `<media-i18n>` for a non-English locale.
     *
     * The skins only translate inside the wrapper.
     *
     * @param string $html the player markup
     * @param array $options the template options
     * @return string
     */
    private function _localize(string $html, array $options): string
    {
        $locale = $this->_locale($options['locale'] ?? null);

        if ($locale === null) {
            return $html;
        }

        return Html::tag('media-i18n', $html, ['lang' => $locale]);
    }
}
