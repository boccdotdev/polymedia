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
use craft\db\Table as CraftTable;

/**
 * Install migration for the Polymedia plugin.
 *
 * Creates `polymedia_items`, `polymedia_related_assets`, and
 * `polymedia_field_settings` tables with foreign keys and indexes.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class Install extends Migration
{
    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        $this->_createMediaItemsTable();
        $this->_createRelatedAssetsTable();
        $this->_createFieldSettingsTable();

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::FIELD_SETTINGS);
        $this->dropTableIfExists(Table::RELATED_ASSETS);
        $this->dropTableIfExists(Table::MEDIA_ITEMS);

        return true;
    }

    // Private Methods
    // =========================================================================

    /**
     * Creates the `polymedia_items` table.
     */
    private function _createMediaItemsTable(): void
    {
        $this->createTable(Table::MEDIA_ITEMS, [
            'id' => $this->primaryKey(),
            'assetId' => $this->integer()->notNull(),
            'assetUid' => $this->char(36)->notNull(),
            'type' => $this->string(32)->notNull(),
            'url' => $this->text()->notNull(),
            'providerId' => $this->string(255)->notNull()->defaultValue(''),
            'element' => $this->string(64)->notNull(),
            'title' => $this->string(255)->notNull(),
            'duration' => $this->integer(),
            'width' => $this->integer(),
            'height' => $this->integer(),
            'defaults' => $this->text(),
            'metadata' => $this->text(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::MEDIA_ITEMS, ['assetId'], true);
        $this->createIndex(null, Table::MEDIA_ITEMS, ['assetUid'], true);
        $this->createIndex(null, Table::MEDIA_ITEMS, ['type']);

        $this->addForeignKey(null, Table::MEDIA_ITEMS, ['assetId'], CraftTable::ASSETS, ['id'], 'CASCADE', null);
    }

    /**
     * Creates the `polymedia_related_assets` table.
     */
    private function _createRelatedAssetsTable(): void
    {
        $this->createTable(Table::RELATED_ASSETS, [
            'id' => $this->primaryKey(),
            'itemId' => $this->integer()->notNull(),
            'assetId' => $this->integer()->notNull(),
            'role' => $this->string(32)->notNull(),
            'siteId' => $this->integer(),
            'srclang' => $this->string(16),
            'label' => $this->string(255),
            'isDefault' => $this->boolean()->notNull()->defaultValue(false),
            'sortOrder' => $this->smallInteger(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::RELATED_ASSETS, ['itemId', 'role']);
        $this->createIndex(null, Table::RELATED_ASSETS, ['itemId', 'role', 'siteId']);

        $this->addForeignKey(null, Table::RELATED_ASSETS, ['itemId'], Table::MEDIA_ITEMS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RELATED_ASSETS, ['assetId'], CraftTable::ASSETS, ['id'], 'CASCADE', null);
        $this->addForeignKey(null, Table::RELATED_ASSETS, ['siteId'], CraftTable::SITES, ['id'], 'CASCADE', null);
    }

    /**
     * Creates the `polymedia_field_settings` table.
     *
     * Stores per-field Polymedia settings (e.g. `allowedProviders`) for plain
     * Assets fields where the `polymedia` kind is enabled. Keyed by field UID
     * so the row survives field renames.
     */
    private function _createFieldSettingsTable(): void
    {
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
}
