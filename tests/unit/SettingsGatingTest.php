<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\EditorContent;
use boccdotdev\polymedia\services\MediaItems;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for settings defaults and pure edition/status helpers.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class SettingsGatingTest extends TestCase
{
    // Public Methods
    // =========================================================================

    public function testMuxSettingsDefaults(): void
    {
        $settings = new Settings();

        $this->assertNull($settings->muxTokenId);
        $this->assertNull($settings->muxTokenSecret);
        $this->assertFalse($settings->deleteMuxAssetOnDelete);
        $this->assertTrue($settings->autoFetchPoster);
    }

    public function testEditionsAreLiteThenPro(): void
    {
        $this->assertSame(
            [Plugin::EDITION_LITE, Plugin::EDITION_PRO],
            Plugin::editions(),
        );
    }

    public function testMuxStatusTokens(): void
    {
        $editor = new EditorContent();

        $this->assertSame('ready', $editor->muxStatusToken('ready'));
        $this->assertSame('processing', $editor->muxStatusToken('preparing'));
        $this->assertSame('errored', $editor->muxStatusToken('errored'));
        $this->assertSame('unknown', $editor->muxStatusToken(''));
        $this->assertSame('unknown', $editor->muxStatusToken('something-else'));
    }

    public function testDecodeMetadataJson(): void
    {
        $this->assertSame([], MediaItems::decodeMetadataJson(null));
        $this->assertSame([], MediaItems::decodeMetadataJson(''));
        $this->assertSame([], MediaItems::decodeMetadataJson('not-json'));

        $meta = MediaItems::decodeMetadataJson('{"muxAssetId":"abc","muxStatus":"ready"}');
        $this->assertSame('abc', $meta['muxAssetId']);
        $this->assertSame('ready', $meta['muxStatus']);

        $this->assertSame(
            ['muxAssetId' => 'x'],
            MediaItems::decodeMetadataJson(['muxAssetId' => 'x']),
        );
    }
}
