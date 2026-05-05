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
 * ActiveRecord for the `polymedia_items` table.
 *
 * @property int $id
 * @property int $assetId
 * @property string $assetUid
 * @property string $type
 * @property string $url
 * @property string $providerId
 * @property string $element
 * @property string $title
 * @property ?int $duration
 * @property ?int $width
 * @property ?int $height
 * @property ?string $defaults
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class MediaItemRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::MEDIA_ITEMS;
    }
}
