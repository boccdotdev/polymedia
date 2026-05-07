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
 * ActiveRecord for the `polymedia_field_settings` table.
 *
 * @property int $id
 * @property string $fieldUid
 * @property ?string $allowedProviders
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 *
 * @author boccdotdev
 * @since 1.1.0
 */
class FieldSettingsRecord extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public static function tableName(): string
    {
        return Table::FIELD_SETTINGS;
    }
}
