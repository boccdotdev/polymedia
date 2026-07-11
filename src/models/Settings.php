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
     * @var ?string UID of the volume for inline-uploaded posters, captions, and transcripts.
     * Falls back to `$defaultVolumeUid` when null.
     * @deprecated 1.2.2 Unused; kept for project config BC. Removal planned for 2.0.
     */
    public ?string $attachmentsVolumeUid = null;

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
     * @var bool Download derived thumbnail as poster on asset creation.
     * @deprecated 1.2.2 Unused until implemented (planned with Mux Pro / 2.0); kept for project config BC.
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


    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['mediaChromeVersion', 'scriptLoaderMode', 'cdnHost'], 'required'];
        $rules[] = [['mediaChromeVersion', 'cdnHost'], 'string'];
        $rules[] = [['selfHostBaseUrl'], 'string'];
        $rules[] = [['defaultVolumeUid', 'attachmentsVolumeUid'], 'string', 'max' => 36];
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
            ],
            'boolean',
        ];

        return $rules;
    }
}
