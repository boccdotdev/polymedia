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

use boccdotdev\polymedia\base\PlayerEngineInterface;
use boccdotdev\polymedia\engines\MediaChrome;
use boccdotdev\polymedia\engines\VideoJs;
use boccdotdev\polymedia\helpers\PlaybackSources;
use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\models\RenderContext;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\elements\Asset;
use craft\errors\SiteNotFoundException;
use craft\helpers\Json;
use Twig\Markup;
use yii\base\Component;

/**
 * Renders player markup and script tags for polymedia assets.
 *
 * Resolves each item once into a {@see RenderContext} and hands markup to the
 * active {@see PlayerEngineInterface}.
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
     * @var string[] Types whose source the Renderer resolves.
     */
    private const SOURCE_TYPES = ['mux', 'bunny', 'mp4'];

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, PlayerEngineInterface> Engines by handle.
     */
    private array $_engines = [];

    // Public Methods
    // =========================================================================

    /**
     * Renders a full player for a polymedia asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options (overrides for player settings, poster, children,
     *        `playbackFormat` for Mux/Bunny items, etc.)
     * @return Markup the rendered HTML
     * @throws SiteNotFoundException if tracks or the player locale need the current site and there is none
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function player(Asset $asset, array $options = []): Markup
    {
        $context = $this->_context($asset, $options, true);

        if ($context === null) {
            return new Markup('', 'UTF-8');
        }

        return new Markup($this->getEngine()->player($context), 'UTF-8');
    }

    /**
     * Renders just the media element, without the player wrapper.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options, including `playbackFormat`
     * @return Markup the rendered HTML
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function element(Asset $asset, array $options = []): Markup
    {
        $context = $this->_context($asset, $options, false);

        if ($context === null) {
            return new Markup('', 'UTF-8');
        }

        return new Markup($this->getEngine()->element($context), 'UTF-8');
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
     * Returns the media's aspect ratio as a CSS `aspect-ratio` value.
     *
     * Lets a template size the player's box before the video loads, so lazy
     * players don't shift the page. Prefers the stored ratio (Mux's display
     * ratio), then the stored video dimensions. Mux items store both.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return ?string e.g. `16 / 9`, or null when the ratio is unknown
     *
     * @author boccdotdev
     * @since 2.3.0
     */
    public function aspectRatio(Asset $asset): ?string
    {
        return $this->_aspectRatio($this->data($asset));
    }

    /**
     * Converts a stored `W:H` ratio to a CSS `aspect-ratio` value.
     *
     * Pure function — exposed static for unit testing.
     *
     * @param mixed $ratio a ratio such as `16:9` (as Mux reports it) or `16/9`
     * @return ?string e.g. `16 / 9`, or null when the value isn't a positive ratio
     *
     * @author boccdotdev
     * @since 2.3.0
     */
    public static function cssAspectRatio(mixed $ratio): ?string
    {
        if (!is_string($ratio)
            || !preg_match('/^\s*(\d+(?:\.\d+)?)\s*[:\/]\s*(\d+(?:\.\d+)?)\s*$/', $ratio, $matches)
            || (float)$matches[1] <= 0
            || (float)$matches[2] <= 0
        ) {
            return null;
        }

        return "{$matches[1]} / {$matches[2]}";
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
     * Renders `<script type="module">` tags for the player and provider elements.
     *
     * @param array $opts options: `providers`, `version`, `mode`; with Video.js
     *        also `skins` and `locale`. A list is shorthand for `providers`.
     * @return Markup the script tags
     * @throws SiteNotFoundException if the Video.js locale needs the current site and there is none
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function scripts(array $opts = []): Markup
    {
        // `scripts(['mux', 'bunny'])` is shorthand for the providers option.
        if ($opts !== [] && array_is_list($opts)) {
            $opts = ['providers' => $opts];
        }

        $settings = Plugin::getInstance()->getSettings();
        $mode = $opts['mode'] ?? $settings->scriptLoaderMode;

        if ($mode === 'none') {
            return new Markup('', 'UTF-8');
        }

        $providers = $opts['providers'] ?? $settings->defaultProviders;

        return new Markup($this->getEngine()->scripts($providers, $mode, $opts), 'UTF-8');
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
        $record = Plugin::getInstance()->getMediaItems()->getByAssetId($asset->id);

        return $this->_resolvePoster($record, $this->data($asset), []);
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

    /**
     * Returns the player engine selected by the `playerEngine` setting.
     *
     * @return PlayerEngineInterface
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    public function getEngine(): PlayerEngineInterface
    {
        $handle = Plugin::getInstance()->getSettings()->getPlayerEngine();

        return $this->_engines[$handle] ??= match ($handle) {
            Settings::ENGINE_VIDEOJS => new VideoJs(),
            default => new MediaChrome(),
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves everything an engine needs to render an item.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $options rendering options
     * @param bool $withTracks whether to resolve `<track>` children
     * @return ?RenderContext null when the asset has no manifest
     * @throws SiteNotFoundException if tracks need the current site and there is none
     */
    private function _context(Asset $asset, array $options, bool $withTracks): ?RenderContext
    {
        $manifest = $this->data($asset);

        if (empty($manifest)) {
            return null;
        }

        $plugin = Plugin::getInstance();
        $type = $manifest['type'] ?? '';
        $source = PlaybackSources::resolve($manifest, $plugin->getSettings(), $options);

        // Nothing playable, e.g. a deleted hosted video.
        if ($source['url'] === '') {
            return null;
        }

        $record = $plugin->getMediaItems()->getByAssetId($asset->id);
        $elementMap = $plugin->getUrlDetector()->getElementMap();

        return new RenderContext([
            'manifest' => $manifest,
            'type' => $type,
            'elementTag' => $source['element'] ?? ($record ? $record->element : null) ?? $elementMap[$type] ?? 'video',
            'src' => $source['url'],
            'format' => $source['format'],
            'isAudio' => in_array($type, self::AUDIO_TYPES, true),
            'settings' => $this->_mergeOptions($this->_resolveDefaults($record), $options),
            'poster' => $this->_resolvePoster($record, $manifest, $options),
            'tracks' => $withTracks ? $this->_resolveTracks($record, $options) : [],
            'aspectRatio' => $this->_aspectRatio($manifest),
            'options' => $this->_sourceSafeOptions($type, $options),
        ]);
    }

    /**
     * Drops media attributes that would replace a resolved source.
     *
     * For hosted and file items the plugin owns the source, so a template's
     * `mediaAttrs` can't swap the URL, playback ID or MIME type under it.
     *
     * @param string $type the provider type
     * @param array $options rendering options
     * @return array
     */
    private function _sourceSafeOptions(string $type, array $options): array
    {
        if (!isset($options['mediaAttrs']) || !is_array($options['mediaAttrs'])
            || !in_array($type, self::SOURCE_TYPES, true)
        ) {
            return $options;
        }

        $options['mediaAttrs'] = array_filter(
            $options['mediaAttrs'],
            static fn(mixed $key): bool => !in_array(strtolower((string)$key), ['src', 'playback-id', 'type'], true),
            ARRAY_FILTER_USE_KEY,
        );

        return $options;
    }

    /**
     * Returns the CSS aspect ratio from a manifest: the stored ratio, then
     * the stored video dimensions.
     *
     * @param array $manifest the manifest data
     * @return ?string
     */
    private function _aspectRatio(array $manifest): ?string
    {
        return self::cssAspectRatio($manifest['metadata']['aspectRatio'] ?? null)
            ?? (isset($manifest['width'], $manifest['height'])
                ? self::cssAspectRatio("{$manifest['width']}:{$manifest['height']}")
                : null);
    }

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
     * @param ?MediaItemRecord $record the media item record
     * @param array $manifest the manifest data
     * @param array $options rendering options
     * @return ?string the poster URL, or null
     */
    private function _resolvePoster(?MediaItemRecord $record, array $manifest, array $options): ?string
    {
        if (isset($options['poster'])) {
            if ($options['poster'] === false) {
                return null;
            }

            return (string)$options['poster'];
        }

        if ($record) {
            $posterAsset = Plugin::getInstance()->getRelatedAssets()->getPoster($record->id);

            if ($posterAsset) {
                return $posterAsset->getUrl();
            }
        }

        return $manifest['metadata']['thumbnail'] ?? null;
    }

    /**
     * Resolves `<track>` attributes for captions, subtitles and descriptions.
     *
     * @param ?MediaItemRecord $record the media item record
     * @param array $options rendering options (`tracks`: `auto`, `all`, `none`/false)
     * @return array<int, array<string, string>>
     * @throws SiteNotFoundException if there is no current site
     */
    private function _resolveTracks(?MediaItemRecord $record, array $options): array
    {
        $tracksMode = $options['tracks'] ?? 'auto';

        if ($tracksMode === 'none' || $tracksMode === false || !$record) {
            return [];
        }

        $siteId = $tracksMode === 'all' ? null : Craft::$app->getSites()->getCurrentSite()->id;
        $tracks = [];

        foreach (['captions', 'subtitles', 'descriptions'] as $role) {
            $relatedRecords = Plugin::getInstance()->getRelatedAssets()->getTrackRecords((int)$record->id, $role, $siteId);

            foreach ($relatedRecords as $related) {
                $trackAsset = Craft::$app->getAssets()->getAssetById($related->assetId);

                if (!$trackAsset) {
                    continue;
                }

                $trackAttrs = [
                    'kind' => $role,
                    'src' => $trackAsset->getUrl(),
                ];

                if ($related->srclang) {
                    $trackAttrs['srclang'] = $related->srclang;
                }

                if ($related->label) {
                    $trackAttrs['label'] = $related->label;
                }

                $tracks[] = $trackAttrs;
            }
        }

        return $tracks;
    }
}
