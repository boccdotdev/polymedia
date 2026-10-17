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

use boccdotdev\polymedia\services\Bunny;
use craft\base\Model;
use craft\helpers\App;

/**
 * Plugin-wide settings for Polymedia.
 *
 * All settings are env-overridable via `App::env()`.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class Settings extends Model
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Media Chrome player engine.
     * @since 2.4.0
     */
    public const ENGINE_MEDIA_CHROME = 'media-chrome';

    /**
     * @var string Video.js v10 player engine.
     * @since 2.4.0
     */
    public const ENGINE_VIDEOJS = 'videojs';

    /**
     * @var string[] Every player engine handle.
     * @since 2.4.0
     */
    public const ENGINES = [self::ENGINE_MEDIA_CHROME, self::ENGINE_VIDEOJS];

    /**
     * @var string[] Video.js skin handles.
     * @since 2.4.0
     */
    public const VIDEOJS_SKINS = ['default', 'neutral', 'compat'];

    /**
     * @var string Mux video hosting provider.
     * @since 2.5.0
     */
    public const PROVIDER_MUX = 'mux';

    /**
     * @var string Bunny Stream video hosting provider.
     * @since 2.5.0
     */
    public const PROVIDER_BUNNY = 'bunny';

    /**
     * @var string[] Every video hosting provider handle.
     * @since 2.5.0
     */
    public const VIDEO_PROVIDERS = [self::PROVIDER_MUX, self::PROVIDER_BUNNY];

    /**
     * @var string HLS playback format.
     * @since 2.5.0
     */
    public const FORMAT_HLS = 'hls';

    /**
     * @var string MP4 playback format.
     * @since 2.5.0
     */
    public const FORMAT_MP4 = 'mp4';

    /**
     * @var string[] Every playback format handle.
     * @since 2.5.0
     */
    public const PLAYBACK_FORMATS = [self::FORMAT_HLS, self::FORMAT_MP4];

    /**
     * @var string Pattern for a whole-value environment variable reference.
     * @since 2.5.0
     */
    public const ENV_REFERENCE_PATTERN = '/^\$[A-Za-z_][A-Za-z0-9_]*$/D';

    // Public Properties
    // =========================================================================

    /**
     * @var ?string UID of the volume where new `.pmedia` manifest files are saved.
     */
    public ?string $defaultVolumeUid = null;

    /**
     * @var ?string Legacy attachments volume setting.
     * @deprecated 1.2.2 Unused; kept for project config BC.
     */
    public ?string $attachmentsVolumeUid = null;

    /**
     * @var ?string UID of the plugin-owned volume for fetched/uploaded posters
     *              and text tracks.
     * @since 2.2.0
     */
    public ?string $sidecarVolumeUid = null;

    /**
     * @var string Front-end player engine, `media-chrome` or `videojs`. Supports
     * env syntax (`$POLYMEDIA_PLAYER_ENGINE`) so one environment can trial
     * Video.js. The two engines can't share a page, so this applies site-wide.
     * Holds the raw value; read the active engine with {@see getPlayerEngine()}.
     * @since 2.4.0
     */
    public string $playerEngine = self::ENGINE_MEDIA_CHROME;

    /**
     * @var string Media Chrome major version for the `scripts()` helper.
     */
    public string $mediaChromeVersion = '4';

    /**
     * @var string Exact Video.js version for the `scripts()` helper. CDN entry
     * files import content-hashed chunks, so it can't be a version range.
     * @since 2.4.0
     */
    public string $videojsVersion = '10.1.0';

    /**
     * @var string Default Video.js skin: `default`, `neutral` or `compat`.
     * Templates can override it per player with the `skin` option.
     * @since 2.4.0
     */
    public string $videojsSkin = 'default';

    /**
     * @var array Default providers to include when `scripts()` is called without arguments.
     */
    public array $defaultProviders = ['hls', 'youtube', 'vimeo'];

    /**
     * @var bool Whether native Assets fields can be configured to only allow polymedia kinds.
     * @deprecated 1.2.2 Unused; kept for project config BC. Removal planned for 2.0.
     */
    public bool $restrictAssetKinds = false;

    /**
     * @var bool Whether to show a CP warning when saving a video-type item with no captions.
     * @deprecated 1.2.2 Unused; kept for project config BC. Removal planned for 2.0.
     */
    public bool $requireCaptionsForVideo = false;

    /**
     * @var bool Auto-set the caption row's siteId to match the current CP site when uploading.
     * @deprecated 1.2.2 Unused; kept for project config BC. Removal planned for 2.0.
     */
    public bool $defaultCaptionLanguageFromSite = true;

    /**
     * @var bool Run the basic `WEBVTT` header check on caption file attachment.
     */
    public bool $validateVttOnUpload = true;

    /**
     * @var bool Download derived thumbnail as poster on URL asset creation when no user poster is set.
     * Mux library imports always attempt a first-frame poster regardless of this setting.
     * @since 1.0.0
     */
    public bool $autoFetchPoster = true;

    /**
     * @var string Script loader mode: `cdn`, `self-host`, or `none`.
     */
    public string $scriptLoaderMode = 'cdn';

    /**
     * @var ?string Base URL for self-hosted scripts when mode is `self-host`.
     */
    public ?string $selfHostBaseUrl = null;

    /**
     * @var string CDN hostname for script tags. Default is jsdelivr; swap to unpkg/esm.sh.
     */
    public string $cdnHost = 'cdn.jsdelivr.net';

    /**
     * @var bool Warn when saving a signed-URL manifest into a publicly accessible volume.
     */
    public bool $warnOnSignedUrlInPublicVolume = true;

    /**
     * @var array Plugin-wide default for field-level allowed providers (empty = all).
     */
    public array $defaultFieldAllowedProviders = [];

    /**
     * @var ?string Mux API Token ID. Supports env syntax (`$MUX_TOKEN_ID`).
     * @since 2.0.0
     */
    public ?string $muxTokenId = null;

    /**
     * @var ?string Mux API Token Secret. Supports env syntax (`$MUX_TOKEN_SECRET`).
     * @since 2.0.0
     */
    public ?string $muxTokenSecret = null;

    /**
     * @var bool When true, hard-deleting a Mux `.pmedia` also deletes the Mux asset.
     * Default off so Craft trash/delete only affects local records.
     * @since 2.0.0
     */
    public bool $deleteMuxAssetOnDelete = false;

    /**
     * @var ?string Mux webhook signing secret. Supports env syntax
     * (`$MUX_WEBHOOK_SECRET`). When empty, the webhook endpoint is disabled
     * and status updates rely on polling/sync.
     * @since 2.2.0
     */
    public ?string $muxWebhookSecret = null;

    /**
     * @var string Hosting provider for new library imports and uploads, `mux`
     * or `bunny`. Existing assets keep their own provider when this changes.
     * @since 2.5.0
     */
    public string $videoProvider = self::PROVIDER_MUX;

    /**
     * @var string Preferred playback format for Mux assets, `hls` or `mp4`.
     * MP4 plays the highest ready static rendition and falls back to HLS.
     * @since 2.5.0
     */
    public string $muxDefaultPlaybackFormat = self::FORMAT_HLS;

    /**
     * @var string Preferred playback format for Bunny Stream assets, `hls` or
     * `mp4`. MP4 plays the highest verified MP4 file and falls back to HLS.
     * @since 2.5.0
     */
    public string $bunnyDefaultPlaybackFormat = self::FORMAT_HLS;

    /**
     * @var ?string Bunny Stream library ID. Supports env syntax (`$BUNNY_LIBRARY_ID`).
     * @since 2.5.0
     */
    public ?string $bunnyLibraryId = null;

    /**
     * @var ?string Environment reference for the Bunny Stream library's write
     * API key (`$BUNNY_API_KEY`). Literal keys are rejected.
     * @since 2.5.0
     */
    public ?string $bunnyApiKey = null;

    /**
     * @var ?string Native Bunny delivery hostname such as `vz-example.b-cdn.net`,
     * without a scheme or path. Supports env syntax (`$BUNNY_CDN_HOSTNAME`).
     * @since 2.5.0
     */
    public ?string $bunnyCdnHostname = null;

    // Public Methods
    // =========================================================================

    /**
     * Returns the active player engine, resolving an environment variable.
     *
     * Falls back to Media Chrome when the resolved value isn't a known engine,
     * so a missing or mistyped variable never breaks rendering.
     *
     * @return string one of {@see ENGINES}
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    public function getPlayerEngine(): string
    {
        $engine = App::parseEnv($this->playerEngine);

        return in_array($engine, self::ENGINES, true) ? $engine : self::ENGINE_MEDIA_CHROME;
    }

    /**
     * Validates that the player engine is a known engine or an environment
     * variable that resolves to one.
     *
     * @param string $attribute the attribute being validated
     *
     * @author boccdotdev
     * @since 2.4.0
     */
    public function validatePlayerEngine(string $attribute): void
    {
        $value = (string)$this->$attribute;
        $resolved = App::parseEnv($value);

        if (in_array($resolved, self::ENGINES, true)) {
            return;
        }

        // An unset variable is allowed; another environment may define it.
        if ($resolved === null && preg_match('/^\$[A-Za-z_][A-Za-z0-9_]*$/', $value)) {
            return;
        }

        $this->addError($attribute, 'Enter media-chrome or videojs, or an environment variable set to one of them.');
    }

    /**
     * Returns a hosting provider's default playback format.
     *
     * @param string $provider `mux` or `bunny`
     * @return string `hls` or `mp4`; `hls` for any other provider
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getDefaultPlaybackFormat(string $provider): string
    {
        $format = match ($provider) {
            self::PROVIDER_MUX => $this->muxDefaultPlaybackFormat,
            self::PROVIDER_BUNNY => $this->bunnyDefaultPlaybackFormat,
            default => self::FORMAT_HLS,
        };

        return $format === self::FORMAT_MP4 ? self::FORMAT_MP4 : self::FORMAT_HLS;
    }

    /**
     * Validates a literal (or resolved) Bunny library ID or delivery hostname.
     *
     * Environment references that are unset here pass, because another
     * environment may define them.
     *
     * @param string $attribute `bunnyLibraryId` or `bunnyCdnHostname`
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function validateBunnyConnection(string $attribute): void
    {
        $value = App::parseEnv((string)$this->$attribute);

        if ($value === null || $value === '' || is_bool($value)) {
            return;
        }

        try {
            match ($attribute) {
                'bunnyLibraryId' => Bunny::validateLibraryId(trim($value)),
                default => Bunny::validateHostname(strtolower(trim($value))),
            };
        } catch (\InvalidArgumentException $e) {
            $this->addError($attribute, $e->getMessage());
        }
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['mediaChromeVersion', 'scriptLoaderMode', 'cdnHost', 'playerEngine', 'videojsVersion', 'videojsSkin'], 'required'];
        $rules[] = [['mediaChromeVersion', 'cdnHost', 'muxTokenId', 'muxTokenSecret', 'muxWebhookSecret'], 'string'];
        $rules[] = [['playerEngine'], 'validatePlayerEngine'];
        $rules[] = [['videojsSkin'], 'in', 'range' => self::VIDEOJS_SKINS];
        $rules[] = [
            ['videojsVersion'],
            'match',
            'pattern' => '/^\d+\.\d+\.\d+(-[0-9A-Za-z.]+)?$/',
            'message' => "Use an exact version such as 10.1.0. Video.js CDN files can't be loaded from a version range.",
        ];
        $rules[] = [
            ['muxTokenId', 'muxTokenSecret', 'muxWebhookSecret'],
            'match',
            'pattern' => '/^\$[A-Za-z_][A-Za-z0-9_]*$/',
            'skipOnEmpty' => true,
            'message' => 'Use an environment variable reference such as `$MUX_TOKEN_ID`. Literal credentials cannot be saved to project config.',
        ];
        $rules[] = [['bunnyLibraryId', 'bunnyApiKey', 'bunnyCdnHostname'], 'string'];
        $rules[] = [
            ['bunnyApiKey'],
            'match',
            'pattern' => self::ENV_REFERENCE_PATTERN,
            'skipOnEmpty' => true,
            'message' => 'Use an environment variable reference such as `$BUNNY_API_KEY`. Literal credentials cannot be saved to project config.',
        ];
        $rules[] = [['bunnyLibraryId', 'bunnyCdnHostname'], 'validateBunnyConnection'];
        $rules[] = [['videoProvider'], 'in', 'range' => self::VIDEO_PROVIDERS];
        $rules[] = [['muxDefaultPlaybackFormat', 'bunnyDefaultPlaybackFormat'], 'in', 'range' => self::PLAYBACK_FORMATS];
        $rules[] = [['selfHostBaseUrl'], 'string'];
        $rules[] = [['defaultVolumeUid', 'attachmentsVolumeUid', 'sidecarVolumeUid'], 'string', 'max' => 36];
        $rules[] = [
            ['sidecarVolumeUid'],
            'compare',
            'compareAttribute' => 'defaultVolumeUid',
            'operator' => '!=',
            'when' => static fn(self $model) => (bool)$model->sidecarVolumeUid
                && (bool)$model->defaultVolumeUid,
            'message' => 'The sidecar volume must be different from the default manifest volume.',
        ];
        $rules[] = [['scriptLoaderMode'], 'in', 'range' => ['cdn', 'self-host', 'none']];
        $rules[] = [['defaultProviders', 'defaultFieldAllowedProviders'], 'each', 'rule' => ['string']];
        $rules[] = [
            [
                'restrictAssetKinds',
                'requireCaptionsForVideo',
                'defaultCaptionLanguageFromSite',
                'validateVttOnUpload',
                'autoFetchPoster',
                'warnOnSignedUrlInPublicVolume',
                'deleteMuxAssetOnDelete',
            ],
            'boolean',
        ];

        return $rules;
    }
}
