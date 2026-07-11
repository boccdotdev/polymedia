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

namespace boccdotdev\polymedia\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;

/**
 * CP asset bundle for Polymedia.
 *
 * Bundles the JS and CSS for the Assets “Add media” disclosure and Mux modals
 * and modal on the asset element index.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class PolymediaAsset extends AssetBundle
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        $this->sourcePath = __DIR__ . '/dist';

        $this->depends = [
            CpAsset::class,
        ];

        $this->js = [
            'upchunk.js',
            'polymedia.js',
        ];

        $this->css = [
            'polymedia.css',
        ];

        parent::init();
    }
}
