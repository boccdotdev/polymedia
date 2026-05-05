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

namespace boccdotdev\polymedia\records;

use boccdotdev\polymedia\db\Table;
use craft\db\ActiveRecord;

/**
 * ActiveRecord for the `polymedia_related_assets` table.
 *
 * @property int $id
 * @property int $itemId
 * @property int $assetId
 * @property string $role
 * @property ?int $siteId
 * @property ?string $srclang
 * @property ?string $label
 * @property bool $isDefault
 * @property ?int $sortOrder
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class RelatedAssetRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::RELATED_ASSETS;
    }
}
