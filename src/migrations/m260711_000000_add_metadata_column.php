<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\migrations;

use boccdotdev\polymedia\db\Table;
use craft\db\Migration;

/**
 * Adds a `metadata` JSON text column to `polymedia_items` for DB-first
 * manifest reads (thumbnail, provider hints, future Mux fields).
 *
 * @author boccdotdev
 * @since 1.3.0
 */
class m260711_000000_add_metadata_column extends Migration
{
    /**
     * @inheritdoc
     */
    public function safeUp(): bool
    {
        if (!$this->db->columnExists(Table::MEDIA_ITEMS, 'metadata')) {
            $this->addColumn(Table::MEDIA_ITEMS, 'metadata', $this->text()->after('defaults'));
        }

        return true;
    }

    /**
     * @inheritdoc
     */
    public function safeDown(): bool
    {
        if ($this->db->columnExists(Table::MEDIA_ITEMS, 'metadata')) {
            $this->dropColumn(Table::MEDIA_ITEMS, 'metadata');
        }

        return true;
    }
}
