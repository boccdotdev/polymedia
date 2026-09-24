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
        $this->assertNull($settings->muxWebhookSecret);
        $this->assertNull($settings->sidecarVolumeUid);
        $this->assertFalse($settings->deleteMuxAssetOnDelete);
        $this->assertTrue($settings->autoFetchPoster);
    }

    public function testMuxCredentialsMustUseEnvironmentReferences(): void
    {
        if (!class_exists(\Yii::class, false)) {
            require_once dirname(__DIR__, 2) . '/vendor/yiisoft/yii2/Yii.php';
        }
        if (\Yii::$app === null) {
            new \yii\console\Application([
                'id' => 'polymedia-tests',
                'basePath' => dirname(__DIR__, 2),
            ]);
        }

        $literal = new Settings([
            'muxTokenId' => 'literal-token-id',
            'muxTokenSecret' => 'literal-token-secret',
            'muxWebhookSecret' => 'literal-webhook-secret',
        ]);

        $this->assertFalse($literal->validate([
            'muxTokenId',
            'muxTokenSecret',
            'muxWebhookSecret',
        ]));
        $this->assertTrue($literal->hasErrors('muxTokenId'));
        $this->assertTrue($literal->hasErrors('muxTokenSecret'));
        $this->assertTrue($literal->hasErrors('muxWebhookSecret'));

        $references = new Settings([
            'muxTokenId' => '$MUX_TOKEN_ID',
            'muxTokenSecret' => '$MUX_TOKEN_SECRET',
            'muxWebhookSecret' => '$MUX_WEBHOOK_SECRET',
        ]);

        $this->assertTrue($references->validate([
            'muxTokenId',
            'muxTokenSecret',
            'muxWebhookSecret',
        ]));
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

    public function testVerifyWebhookSignature(): void
    {
        $secret = 'whsec_test';
        $payload = '{"type":"video.asset.ready","data":{"id":"a1"}}';
        $now = 1_700_000_000;
        $header = 't=' . $now . ',v1=' . hash_hmac('sha256', $now . '.' . $payload, $secret);

        $this->assertTrue(Mux::verifyWebhookSignature($payload, $header, $secret, 300, $now));
        // A rotated-in second v1 candidate still passes.
        $this->assertTrue(Mux::verifyWebhookSignature($payload, $header . ',v1=deadbeef', $secret, 300, $now));

        // Tampered body, wrong secret, stale timestamp, malformed/missing header, empty secret.
        $this->assertFalse(Mux::verifyWebhookSignature($payload . 'x', $header, $secret, 300, $now));
        $this->assertFalse(Mux::verifyWebhookSignature($payload, $header, 'other-secret', 300, $now));
        $this->assertFalse(Mux::verifyWebhookSignature($payload, $header, $secret, 300, $now + 301));
        $this->assertFalse(Mux::verifyWebhookSignature($payload, 'v1=abc', $secret, 300, $now));
        $this->assertFalse(Mux::verifyWebhookSignature($payload, '', $secret, 300, $now));
        $this->assertFalse(Mux::verifyWebhookSignature($payload, $header, '', 300, $now));
    }

    public function testMapWebhookAssetDataPrefersPublicPlayback(): void
    {
        $state = Mux::mapWebhookAssetData([
            'id' => 'asset-1',
            'status' => 'ready',
            'duration' => 24.1,
            'playback_ids' => [
                ['id' => 'signed-pb', 'policy' => 'signed'],
                ['id' => 'public-pb', 'policy' => 'public'],
            ],
        ]);

        $this->assertSame('asset-1', $state['assetId']);
        $this->assertSame('public-pb', $state['playbackId']);
        $this->assertSame('public', $state['playbackPolicy']);
        $this->assertSame('ready', $state['status']);
        $this->assertSame(24.1, $state['duration']);

        // Falls back to the first playback id when none are public.
        $signedOnly = Mux::mapWebhookAssetData([
            'id' => 'asset-2',
            'playback_ids' => [['id' => 'signed-pb', 'policy' => 'signed']],
        ]);
        $this->assertSame('signed-pb', $signedOnly['playbackId']);

        // Degrades cleanly on an empty payload.
        $empty = Mux::mapWebhookAssetData([]);
        $this->assertSame('', $empty['assetId']);
        $this->assertNull($empty['playbackId']);
    }

    public function testMergeMuxAssetStateAppliesStatusDurationAndAssetId(): void
    {
        $result = MediaItems::mergeMuxAssetState(
            ['thumbnail' => 'https://image.mux.com/pb1/thumbnail.jpg?time=0'],
            null,
            'asset-1',
            ['status' => 'ready', 'duration' => 12.4, 'playbackId' => 'pb1'],
        );

        $this->assertTrue($result['changed']);
        $this->assertSame('asset-1', $result['metadata']['muxAssetId']);
        $this->assertSame('ready', $result['metadata']['muxStatus']);
        // Concurrent writers' keys survive the merge.
        $this->assertArrayHasKey('thumbnail', $result['metadata']);
        $this->assertSame(12, $result['duration']);
    }

    public function testMergeMuxAssetStateIsIdempotent(): void
    {
        $first = MediaItems::mergeMuxAssetState([], null, 'asset-1', ['status' => 'ready', 'duration' => 30]);
        $second = MediaItems::mergeMuxAssetState(
            $first['metadata'],
            $first['duration'],
            'asset-1',
            ['status' => 'ready', 'duration' => 30],
        );

        $this->assertTrue($first['changed']);
        $this->assertFalse($second['changed']);
        $this->assertSame($first['metadata'], $second['metadata']);
        $this->assertSame($first['duration'], $second['duration']);
    }

    public function testMergeMuxAssetStateIgnoresEmptyAndInvalidValues(): void
    {
        $result = MediaItems::mergeMuxAssetState(
            ['muxAssetId' => 'asset-1', 'muxStatus' => 'ready'],
            30,
            'asset-1',
            ['status' => '', 'duration' => 'not-a-number'],
        );

        $this->assertFalse($result['changed']);
        $this->assertSame('ready', $result['metadata']['muxStatus']);
        $this->assertSame(30, $result['duration']);

        // Zero/negative durations are Mux placeholders, not real lengths.
        $zero = MediaItems::mergeMuxAssetState([], null, 'asset-1', ['duration' => 0]);
        $this->assertSame(['muxAssetId' => 'asset-1'], $zero['metadata']);
        $this->assertNull($zero['duration']);
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
