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
 * Plugin-wide settings for Polymedia.
 *
 * All settings are env-overridable via `App::env()`.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class Settings extends Model
{
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
     * @var string Media Chrome major version for the `scripts()` helper.
     */
    public string $mediaChromeVersion = '4';

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
     * @var string Provider used for new library imports and uploads. Existing assets
     *             retain their own provider regardless of this setting.
     * @since 2.3.0
     */
    public string $videoProvider = 'mux';

    /**
     * @var string Preferred playback format; unavailable MP4s fall back to HLS.
     * @since 2.3.0
     */
    public string $muxDefaultPlaybackFormat = 'hls';

    /**
     * @var string Preferred playback format for Bunny assets.
     * @since 2.3.0
     */
    public string $bunnyDefaultPlaybackFormat = 'hls';

    /**
     * @var ?string Bunny Stream library ID; supports an environment reference.
     * @since 2.3.0
     */
    public ?string $bunnyLibraryId = null;

    /**
     * @var ?string Environment reference for the library's write API key.
     * @since 2.3.0
     */
    public ?string $bunnyApiKey = null;

    /**
     * @var ?string Bunny delivery hostname, without a scheme or path.
     * @since 2.3.0
     */
    public ?string $bunnyCdnHostname = null;

    /**
     * @var ?string Environment reference for the library's read-only API key,
     *              used to verify optional signed webhooks.
     * @since 2.3.0
     */
    public ?string $bunnyWebhookKey = null;

    /**
     * @var bool Remote deletion is opt-in and only applies to hard deletion.
     * @since 2.3.0
     */
    public bool $deleteBunnyAssetOnDelete = false;

    /**
     * @var bool Opt-in Pro routing of new native MP4 uploads. Never implied by
     *           configuring a provider.
     * @since 2.3.0
     */
    public bool $autoRouteVideoUploads = false;

    /**
     * @var string[] Explicit volume UID allowlist for native MP4 upload routing.
     *               An empty list routes no native uploads.
     * @since 2.3.0
     */
    public array $videoUploadVolumeUids = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['mediaChromeVersion', 'scriptLoaderMode', 'cdnHost'], 'required'];
        $rules[] = [['mediaChromeVersion', 'cdnHost', 'muxTokenId', 'muxTokenSecret', 'muxWebhookSecret'], 'string'];
        $rules[] = [['bunnyLibraryId', 'bunnyApiKey', 'bunnyCdnHostname', 'bunnyWebhookKey'], 'string'];
        $rules[] = [
            ['muxTokenId', 'muxTokenSecret', 'muxWebhookSecret', 'bunnyApiKey', 'bunnyWebhookKey'],
            'match',
            'pattern' => '/^\$[A-Za-z_][A-Za-z0-9_]*$/',
            'skipOnEmpty' => true,
            'message' => 'Use an environment variable reference such as `$VIDEO_API_KEY`. Literal credentials cannot be saved to project config.',
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
        $rules[] = [['videoProvider'], 'in', 'range' => ['mux', 'bunny']];
        $rules[] = [['muxDefaultPlaybackFormat', 'bunnyDefaultPlaybackFormat'], 'in', 'range' => ['hls', 'mp4']];
        $rules[] = [['videoUploadVolumeUids'], 'each', 'rule' => ['string', 'max' => 36]];
        $rules[] = [
            ['videoUploadVolumeUids'],
            function(string $attribute): void {
                if ($this->autoRouteVideoUploads && $this->videoUploadVolumeUids === []) {
                    $this->addError($attribute, 'Select at least one volume for automatic video uploads.');
                }

                if ($this->sidecarVolumeUid !== null && in_array($this->sidecarVolumeUid, $this->videoUploadVolumeUids, true)) {
                    $this->addError($attribute, 'The sidecar volume cannot be used for automatic video uploads.');
                }
            },
            'skipOnEmpty' => false,
        ];
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
                'deleteBunnyAssetOnDelete',
                'autoRouteVideoUploads',
            ],
            'boolean',
        ];

        return $rules;
    }
}
