<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\PlaybackSources;
use PHPUnit\Framework\TestCase;

class PlaybackSourcesTest extends TestCase
{
    public function testDefaultsOverridesAndHighestReadyDoNotMutateManifest(): void
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
            $settings = [$provider . 'DefaultPlaybackFormat' => 'mp4', 'videoProvider' => 'another-provider'];
            self::assertSame($tag, PlaybackSources::resolve($manifest)['element']);
            self::assertSame('https://example.test/1080.mp4', PlaybackSources::resolve($manifest, $settings)['url']);
            self::assertSame('video', PlaybackSources::resolve($manifest, [], ['playbackFormat' => 'mp4'])['element']);
            self::assertSame($tag, PlaybackSources::resolve($manifest, (object)$settings, ['playbackFormat' => 'hls'])['element']);
            self::assertSame($original, $manifest);
        }
    }

    public function testUnavailableRenditionsAlwaysFallBack(): void
    {
        foreach (['preparing', 'pending', 'errored', 'skipped', null] as $status) {
            $manifest = ['type' => 'mux', 'url' => 'canonical', 'metadata' => ['mp4Renditions' => [
                ['url' => 'https://example.test/high.mp4', 'status' => $status],
            ]]];
            self::assertSame('canonical', PlaybackSources::resolve($manifest, [], ['playbackFormat' => 'mp4'])['url']);
        }
        self::assertSame('mux-video', PlaybackSources::resolve(['type' => 'mux'], [], ['playbackFormat' => 'mp4'])['element']);
    }

    public function testSourceAssetIsResolvedLazilyAndNeverUsesStaleUrl(): void
    {
        $manifest = ['type' => 'mp4', 'url' => 'stale', 'metadata' => ['sourceAssetUid' => 'uid']];
        self::assertSame('current', PlaybackSources::resolve($manifest, [], [], fn($uid) => $uid === 'uid' ? 'current' : null)['url']);
        self::assertSame('', PlaybackSources::resolve($manifest, [], [], fn() => null)['url']);
        self::assertSame('stale', $manifest['url']);
    }

    public function testBrokenSourceIdentityNeverFallsBackToCopiedUrl(): void
    {
        foreach ([[], ['sourceAssetUid' => ''], ['sourceAssetUid' => []]] as $metadata) {
            $manifest = ['type' => 'mp4', 'providerId' => 'asset:uid', 'url' => 'https://stale.test/private.mp4', 'metadata' => $metadata];
            self::assertSame('', PlaybackSources::resolve($manifest)['url']);
        }
        $manifest = ['type' => 'mux', 'providerId' => 'asset:uid', 'url' => 'stale', 'metadata' => ['sourceAssetUid' => 'uid']];
        self::assertSame('', PlaybackSources::resolve($manifest, [], [], fn() => 'unsafe')['url']);
    }

    public function testBunnyUnavailableStateSuppressesStaleSources(): void
    {
        foreach ([['bunnyStatus' => -1], ['bunnyPlaybackPolicy' => 'unsupported']] as $metadata) {
            $metadata['mp4Renditions'] = [['url' => 'https://old.test/a.mp4', 'status' => 'ready']];
            self::assertSame('', PlaybackSources::resolve(['type' => 'bunny', 'url' => 'stale', 'metadata' => $metadata], [], ['playbackFormat' => 'mp4'])['url']);
        }
    }
}
