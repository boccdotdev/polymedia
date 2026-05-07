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

namespace boccdotdev\polymedia\migrations;

use boccdotdev\polymedia\db\Table;
use craft\db\Migration;

/**
 * Drops the unused `polymedia_field_relations` table and creates
 * `polymedia_field_settings` for per-field plugin settings.
 *
 * @author boccdotdev
 * @since 1.1.0
 */
class m260507_000000_field_settings extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->dropTableIfExists('{{%polymedia_field_relations}}');

        if (!$this->db->tableExists(Table::FIELD_SETTINGS)) {
            $this->createTable(Table::FIELD_SETTINGS, [
                'id' => $this->primaryKey(),
                'fieldUid' => $this->char(36)->notNull(),
                'allowedProviders' => $this->text(),
                'dateCreated' => $this->dateTime()->notNull(),
                'dateUpdated' => $this->dateTime()->notNull(),
                'uid' => $this->uid(),
            ]);

            $this->createIndex(null, Table::FIELD_SETTINGS, ['fieldUid'], true);
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::FIELD_SETTINGS);

        return true;
    }
}
