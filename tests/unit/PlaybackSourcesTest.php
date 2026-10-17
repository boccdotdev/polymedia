<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\helpers\PlaybackSources;
use boccdotdev\polymedia\models\Settings;
use PHPUnit\Framework\TestCase;

/**
 * HLS/MP4 source selection from stored state.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class PlaybackSourcesTest extends TestCase
{
    // Public Methods
    // =========================================================================

    public function testDefaultsOverridesAndHighestReadyRenditionLeaveManifestAlone(): void
    {
        foreach (['mux' => 'mux-video', 'bunny' => 'hls-video'] as $provider => $tag) {
            $manifest = ['type' => $provider, 'url' => 'https://example.test/stream.m3u8', 'metadata' => [
                'mp4Renditions' => [
                    ['status' => 'ready', 'url' => 'https://example.test/720.mp4', 'width' => 1280, 'height' => 720],
                    ['status' => 'preparing', 'url' => 'https://example.test/4k.mp4', 'width' => 3840, 'height' => 2160],
                    ['status' => 'ready', 'url' => 'https://example.test/1080.mp4', 'width' => 1920, 'height' => 1080],
                ],
            ]];
            $original = $manifest;
            $mp4 = new Settings([$provider . 'DefaultPlaybackFormat' => 'mp4']);

            self::assertSame(['url' => 'https://example.test/stream.m3u8', 'element' => $tag, 'format' => 'hls'], PlaybackSources::resolve($manifest));
            self::assertSame(['url' => 'https://example.test/1080.mp4', 'element' => 'video', 'format' => 'mp4'], PlaybackSources::resolve($manifest, $mp4));
            self::assertSame('video', PlaybackSources::resolve($manifest, null, ['playbackFormat' => 'mp4'])['element']);
            self::assertSame($tag, PlaybackSources::resolve($manifest, $mp4, ['playbackFormat' => 'hls'])['element']);
            self::assertSame('video', PlaybackSources::resolve($manifest, $mp4, ['playbackFormat' => 'iframe'])['element'], 'Unknown options fall back to the default.');
            self::assertSame($original, $manifest);
        }
    }

    public function testPreferenceIsPerProvider(): void
    {
        $settings = new Settings(['muxDefaultPlaybackFormat' => 'mp4']);
        $metadata = ['mp4Renditions' => [['status' => 'ready', 'url' => 'https://example.test/a.mp4']]];

        self::assertSame('mp4', PlaybackSources::resolve(['type' => 'mux', 'url' => 'x', 'metadata' => $metadata], $settings)['format']);
        self::assertSame('hls', PlaybackSources::resolve(['type' => 'bunny', 'url' => 'x', 'metadata' => $metadata], $settings)['format']);
    }

    public function testUnavailableRenditionsFallBackToTheStream(): void
    {
        foreach (['preparing', 'pending', 'errored', 'skipped', null] as $status) {
            $manifest = ['type' => 'mux', 'url' => 'canonical', 'metadata' => ['mp4Renditions' => [
                ['url' => 'https://example.test/high.mp4', 'status' => $status],
            ]]];
            self::assertSame('canonical', PlaybackSources::resolve($manifest, null, ['playbackFormat' => 'mp4'])['url']);
        }

        foreach (['javascript:alert(1)', '//example.test/a.mp4', '/relative.mp4'] as $url) {
            $manifest = ['type' => 'mux', 'url' => 'canonical', 'metadata' => ['mp4Renditions' => [
                ['url' => $url, 'status' => 'ready'],
            ]]];
            self::assertSame('canonical', PlaybackSources::resolve($manifest, null, ['playbackFormat' => 'mp4'])['url']);
        }

        self::assertSame('mux-video', PlaybackSources::resolve(['type' => 'mux'], null, ['playbackFormat' => 'mp4'])['element']);
    }

    public function testOtherProvidersPlayTheirUrl(): void
    {
        self::assertSame(['url' => 'https://example.test/v.mp4', 'element' => 'video', 'format' => 'mp4'], PlaybackSources::resolve(['type' => 'mp4', 'url' => 'https://example.test/v.mp4']));
        self::assertSame(['url' => 'https://example.test/v.m3u8', 'element' => null, 'format' => 'hls'], PlaybackSources::resolve(['type' => 'hls', 'url' => 'https://example.test/v.m3u8']));
        self::assertSame(['url' => 'https://youtu.be/x', 'element' => null, 'format' => null], PlaybackSources::resolve(['type' => 'youtube', 'url' => 'https://youtu.be/x'], null, ['playbackFormat' => 'mp4']));
    }

    public function testBunnyUnavailableStateSuppressesStaleSources(): void
    {
        foreach ([['bunnyStatus' => -1], ['bunnyPlaybackPolicy' => 'unsupported']] as $metadata) {
            $metadata['mp4Renditions'] = [['url' => 'https://old.test/a.mp4', 'status' => 'ready']];
            self::assertSame('', PlaybackSources::resolve(['type' => 'bunny', 'url' => 'stale', 'metadata' => $metadata], null, ['playbackFormat' => 'mp4'])['url']);
        }
    }
}
