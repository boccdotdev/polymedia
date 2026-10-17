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
