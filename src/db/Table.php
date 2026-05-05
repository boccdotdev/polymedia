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

namespace boccdotdev\polymedia\db;

/**
 * Table name constants for the Polymedia plugin.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
abstract class Table
{
    // Const Properties
    // =========================================================================

    public const MEDIA_ITEMS = '{{%polymedia_items}}';
    public const RELATED_ASSETS = '{{%polymedia_related_assets}}';
    public const FIELD_RELATIONS = '{{%polymedia_field_relations}}';
}
