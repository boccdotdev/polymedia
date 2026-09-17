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
use boccdotdev\polymedia\services\Mux;
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

    public function testFilterAssetsMatchesTitleIdsAndPassthroughCaseInsensitively(): void
    {
        $mux = new Mux();
        $items = [
            ['title' => 'Launch Day Recap', 'passthrough' => null, 'assetId' => 'a1', 'playbackId' => 'pb1'],
            ['title' => '', 'passthrough' => 'client-promo', 'assetId' => 'a2', 'playbackId' => 'pb2'],
            ['title' => 'Untitled', 'passthrough' => null, 'assetId' => 'AbCdEf123', 'playbackId' => 'xYz789'],
        ];

        $this->assertCount(1, $mux->filterAssets($items, 'launch'));
        $this->assertSame('a1', $mux->filterAssets($items, 'LAUNCH DAY')[0]['assetId']);
        $this->assertSame('a2', $mux->filterAssets($items, 'promo')[0]['assetId']);
        $this->assertSame('AbCdEf123', $mux->filterAssets($items, 'abcdef')[0]['assetId']);
        $this->assertSame('xYz789', $mux->filterAssets($items, 'XYZ')[0]['playbackId']);
        $this->assertSame([], $mux->filterAssets($items, 'no-such-video'));
        $this->assertCount(3, $mux->filterAssets($items, '  '));
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
