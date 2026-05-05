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

use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\elements\Asset;
use craft\helpers\Html;
use craft\helpers\Json;
use Twig\Markup;
use yii\base\Component;

/**
 * Renders Media Chrome player markup from polymedia assets.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class Renderer extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[]
     */
    private const AUDIO_TYPES = ['audio', 'spotify'];

    /**
     * @var array<string, string>
     */
    private const SCRIPT_MAP = [
        'hls' => 'hls-video-element@1',
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
        'peertube' => 'peertube-video-element@0',
        'videojs' => 'videojs-video-element@0',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Renders a full `<media-controller>` player for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options (overrides for player settings, poster, children, etc.)
     * @return Markup the rendered HTML
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function player(Asset $asset, array $options = []): Markup
    {
        $manifest = $this->data($asset);

        if (empty($manifest)) {
            return new Markup('', 'UTF-8');
        }

        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);
        $type = $manifest['type'] ?? '';
        $isAudio = in_array($type, self::AUDIO_TYPES, true);
        $elementMap = Plugin::getInstance()->getUrlDetector()->getElementMap();
        $elementTag = ($record ? $record->element : null) ?? $elementMap[$type] ?? 'video';

        $defaults = $this->_resolveDefaults($record);
        $settings = $this->_mergeOptions($defaults, $options);
        $poster = $this->_resolvePoster($asset, $manifest, $options);

        $controllerAttrs = [];

        if ($isAudio) {
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

        $mediaAttrs = $this->_buildMediaAttrs($manifest, $settings, $poster, $elementTag);
        $trackHtml = $this->_buildTracksHtml($asset, $options);

        $mediaHtml = Html::beginTag($elementTag, $mediaAttrs)
            . $trackHtml
            . Html::endTag($elementTag);

        if ($isAudio && $poster !== null && $poster !== false) {
            $mediaHtml .= Html::tag('img', '', [
                'slot' => 'poster',
                'src' => $poster,
                'alt' => Html::encode($manifest['title'] ?? ''),
                'class' => 'polymedia-cover',
            ]);
        }

        $children = $options['children'] ?? '';

        if ($children === '' && $isAudio) {
            $children = $this->_defaultAudioControls();
        }

        $html = Html::beginTag('media-controller', $controllerAttrs)
            . $mediaHtml
            . $children
            . Html::endTag('media-controller');

        return new Markup($html, 'UTF-8');
    }

    /**
     * Renders just the media element (without `<media-controller>` wrapper).
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options
     * @return Markup the rendered HTML
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function element(Asset $asset, array $options = []): Markup
    {
        $manifest = $this->data($asset);

        if (empty($manifest)) {
            return new Markup('', 'UTF-8');
        }

        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);
        $type = $manifest['type'] ?? '';
        $elementMap = Plugin::getInstance()->getUrlDetector()->getElementMap();
        $elementTag = ($record ? $record->element : null) ?? $elementMap[$type] ?? 'video';

        $defaults = $this->_resolveDefaults($record);
        $settings = $this->_mergeOptions($defaults, $options);
        $poster = $this->_resolvePoster($asset, $manifest, $options);

        $mediaAttrs = $this->_buildMediaAttrs($manifest, $settings, $poster, $elementTag);
        unset($mediaAttrs['slot']);

        $html = Html::beginTag($elementTag, $mediaAttrs) . Html::endTag($elementTag);

        return new Markup($html, 'UTF-8');
    }

    /**
     * Returns the parsed manifest data for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return array the manifest data
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function data(Asset $asset): array
    {
        if ($asset->kind !== 'polymedia') {
            return [];
        }

        try {
            return Plugin::getInstance()->getManifestWriter()->read($asset);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * Checks whether a given asset is a polymedia asset.
     *
     * @param mixed $asset the value to check
     * @return bool
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function is(mixed $asset): bool
    {
        return $asset instanceof Asset && $asset->kind === 'polymedia';
    }

    /**
     * Renders `<script type="module">` tags for Media Chrome and provider elements.
     *
     * @param array $opts options: `providers`, `version`, `mode`
     * @return Markup the script tags
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function scripts(array $opts = []): Markup
    {
        $settings = Plugin::getInstance()->getSettings();
        $mode = $opts['mode'] ?? $settings->scriptLoaderMode;

        if ($mode === 'none') {
            return new Markup('', 'UTF-8');
        }

        $providers = $opts['providers'] ?? $settings->defaultProviders;
        $version = $opts['version'] ?? $settings->mediaChromeVersion;

        $tags = [];

        if ($mode === 'cdn') {
            $cdnHost = $settings->cdnHost;
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
            $base = rtrim($settings->selfHostBaseUrl ?? '', '/');
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

        return new Markup(implode("\n", $tags), 'UTF-8');
    }

    /**
     * Returns the resolved poster URL for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return ?string the poster URL, or null
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function poster(Asset $asset): ?string
    {
        $manifest = $this->data($asset);

        return $this->_resolvePoster($asset, $manifest, []);
    }

    /**
     * Returns track-type related assets for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param string $role `captions`, `subtitles`, or `descriptions`
     * @param ?int $siteId optional site filter
     * @return Asset[]
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function tracks(Asset $asset, string $role = 'captions', ?int $siteId = null): array
    {
        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);

        if (!$record) {
            return [];
        }

        if ($siteId === null) {
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;
        }

        return Plugin::getInstance()->getRelatedAssets()->resolveTracks($record->id, $role, $siteId);
    }

    /**
     * Returns the transcript related asset for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return ?Asset the transcript asset, or null
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function transcript(Asset $asset): ?Asset
    {
        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);

        if (!$record) {
            return null;
        }

        return Plugin::getInstance()->getRelatedAssets()->getTranscript($record->id);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param mixed $record the media item record
     * @return PlayerSettings
     */
    private function _resolveDefaults(mixed $record): PlayerSettings
    {
        $defaults = new PlayerSettings();

        if ($record && $record->defaults) {
            $decoded = Json::decodeIfJson($record->defaults);

            if (is_array($decoded)) {
                $defaults->setAttributes($decoded, false);
            }
        }

        return $defaults;
    }

    /**
     * @param PlayerSettings $defaults the player defaults
     * @param array $options user overrides
     * @return PlayerSettings merged settings
     */
    private function _mergeOptions(PlayerSettings $defaults, array $options): PlayerSettings
    {
        $merged = clone $defaults;

        $booleans = ['autoplay', 'loop', 'muted', 'controls', 'playsinline'];

        foreach ($booleans as $attr) {
            if (isset($options[$attr])) {
                $merged->$attr = (bool)$options[$attr];
            }
        }

        if (isset($options['preload'])) {
            $merged->preload = $options['preload'];
        }

        if (isset($options['crossorigin'])) {
            $merged->crossorigin = $options['crossorigin'];
        }

        return $merged;
    }

    /**
     * Resolves poster URL through the priority chain.
     *
     * @param Asset $asset the polymedia asset
     * @param array $manifest the manifest data
     * @param array $options rendering options
     * @return ?string the poster URL, or null
     */
    private function _resolvePoster(Asset $asset, array $manifest, array $options): ?string
    {
        if (isset($options['poster'])) {
            if ($options['poster'] === false) {
                return null;
            }

            return (string)$options['poster'];
        }

        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);

        if ($record) {
            $posterAsset = Plugin::getInstance()->getRelatedAssets()->getPoster($record->id);

            if ($posterAsset) {
                return $posterAsset->getUrl();
            }
        }

        return $manifest['metadata']['thumbnail'] ?? null;
    }

    /**
     * Builds the HTML attributes for the media element.
     *
     * @param array $manifest the manifest data
     * @param PlayerSettings $settings the resolved settings
     * @param ?string $poster the resolved poster URL
     * @param string $elementTag the element tag name
     * @return array
     */
    private function _buildMediaAttrs(
        array $manifest,
        PlayerSettings $settings,
        ?string $poster,
        string $elementTag,
    ): array {
        $type = $manifest['type'] ?? '';
        $attrs = ['slot' => 'media'];

        if ($elementTag === 'mux-video') {
            $attrs['playback-id'] = $manifest['providerId'] ?? '';

            if (!isset($manifest['streamType'])) {
                $attrs['stream-type'] = 'on-demand';
            }
        } else {
            $attrs['src'] = $manifest['url'] ?? '';
        }

        if ($settings->autoplay) {
            $attrs['autoplay'] = true;
        }

        if ($settings->loop) {
            $attrs['loop'] = true;
        }

        if ($settings->muted) {
            $attrs['muted'] = true;
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

        if ($poster !== null && !in_array($type, self::AUDIO_TYPES, true)) {
            $attrs['poster'] = $poster;
        }

        return $attrs;
    }

    /**
     * Builds `<track>` elements for the media element.
     *
     * @param Asset $asset the polymedia asset
     * @param array $options rendering options
     * @return string
     */
    private function _buildTracksHtml(Asset $asset, array $options): string
    {
        $tracksMode = $options['tracks'] ?? 'auto';

        if ($tracksMode === 'none' || $tracksMode === false) {
            return '';
        }

        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);

        if (!$record) {
            return '';
        }

        $siteId = $tracksMode === 'all' ? null : Craft::$app->getSites()->getCurrentSite()->id;
        $html = '';

        foreach (['captions', 'subtitles', 'descriptions'] as $role) {
            $relatedRecords = \boccdotdev\polymedia\records\RelatedAssetRecord::findAll(
                array_filter([
                    'itemId' => $record->id,
                    'role' => $role,
                    'siteId' => $siteId,
                ]),
            );

            foreach ($relatedRecords as $related) {
                $trackAsset = Craft::$app->getAssets()->getAssetById($related->assetId);

                if (!$trackAsset) {
                    continue;
                }

                $trackAttrs = [
                    'kind' => $role === 'descriptions' ? 'descriptions' : $role,
                    'src' => $trackAsset->getUrl(),
                ];

                if ($related->srclang) {
                    $trackAttrs['srclang'] = $related->srclang;
                }

                if ($related->label) {
                    $trackAttrs['label'] = $related->label;
                }

                $html .= Html::tag('track', '', $trackAttrs);
            }
        }

        return $html;
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
