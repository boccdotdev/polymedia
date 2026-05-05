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
 * ActiveRecord for the `polymedia_field_relations` table.
 *
 * Stores per-placement poster overrides for PolymediaField, keyed by relation ID.
 *
 * @property int $id
 * @property int $relationId
 * @property ?int $posterAssetId
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class FieldRelationRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::FIELD_RELATIONS;
    }
}
