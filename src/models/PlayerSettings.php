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
 * Per-asset playback defaults stored in the `defaults` JSON column.
 *
 * These are defaults attached to the asset — Twig calls can override per-use.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class PlayerSettings extends Model
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether the media should autoplay.
     */
    public bool $autoplay = false;

    /**
     * @var bool Whether the media should loop.
     */
    public bool $loop = false;

    /**
     * @var bool Whether the media should be muted by default.
     */
    public bool $muted = false;

    /**
     * @var bool Whether to show playback controls.
     */
    public bool $controls = true;

    /**
     * @var bool Whether to allow inline playback on iOS.
     */
    public bool $playsinline = true;

    /**
     * @var string Preload strategy: `none`, `metadata`, or `auto`.
     */
    public string $preload = 'metadata';

    /**
     * @var ?string Crossorigin setting: `anonymous`, `use-credentials`, or null.
     */
    public ?string $crossorigin = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['autoplay', 'loop', 'muted', 'controls', 'playsinline'], 'boolean'];
        $rules[] = [['preload'], 'in', 'range' => ['none', 'metadata', 'auto']];
        $rules[] = [['crossorigin'], 'in', 'range' => ['anonymous', 'use-credentials']];

        return $rules;
    }
}
