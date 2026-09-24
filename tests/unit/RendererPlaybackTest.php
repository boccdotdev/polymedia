<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\Renderer;
use boccdotdev\polymedia\services\UrlDetector;
use Craft;
use PHPUnit\Framework\TestCase;

class RendererPlaybackTest extends TestCase
{
    public function testDataResolvesSourceReferencesWithoutRewritingTheirManifest(): void
    {
        $previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;
        try {
            $manifest = ['type' => 'mp4', 'url' => 'https://old.example/video.mp4', 'metadata' => ['sourceAssetUid' => 'source-uid']];
            $writer = $this->createMock(\boccdotdev\polymedia\services\ManifestWriter::class);
            $writer->method('read')->willReturn($manifest);
            $writer->expects(self::never())->method('update');
            $sources = $this->createMock(\boccdotdev\polymedia\services\SourceAssets::class);
            $sources->method('resolveUrl')->with($manifest)->willReturnOnConsecutiveCalls('https://new.example/video.mp4', null);
            $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
                ->onlyMethods(['getManifestWriter', 'getSourceAssets'])->getMock();
            $plugin->method('getManifestWriter')->willReturn($writer);
            $plugin->method('getSourceAssets')->willReturn($sources);
            $app->loadedModules[Plugin::class] = $plugin;
            $asset = $this->getMockBuilder(\craft\elements\Asset::class)->disableOriginalConstructor()->getMock();
            $asset->kind = 'polymedia';
            $renderer = new Renderer();
            self::assertSame('https://new.example/video.mp4', $renderer->data($asset)['url']);
            self::assertSame('', $renderer->data($asset)['url']);
            self::assertSame('https://old.example/video.mp4', $manifest['url']);
        } finally {
            Craft::$app = $previousApp;
        }
    }

    public function testRealPlayerAndElementPathsAndScriptLoading(): void
    {
        $previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;
        try {
            $items = $this->createMock(MediaItems::class);
            $items->method('getByAssetId')->willReturn(null);
            $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
                ->onlyMethods(['getSettings', 'getMediaItems', 'getUrlDetector', 'getMux'])->getMock();
            $plugin->method('getSettings')->willReturn(new Settings());
            $plugin->method('getMediaItems')->willReturn($items);
            $plugin->method('getUrlDetector')->willReturn(new UrlDetector());
            $plugin->expects(self::never())->method('getMux');
            $app->loadedModules[Plugin::class] = $plugin;
            $asset = $this->getMockBuilder(\craft\elements\Asset::class)->disableOriginalConstructor()->getMock();
            $asset->id = 1;
            $renderer = $this->getMockBuilder(Renderer::class)->onlyMethods(['data'])->getMock();
            $manifest = ['type' => 'mux', 'providerId' => 'abc123', 'url' => 'https://stream.mux.com/abc123.m3u8',
                'metadata' => ['mp4Renditions' => [['url' => 'https://stream.mux.com/abc123/highest.mp4', 'status' => 'ready']]], ];
            $renderer->method('data')->willReturnCallback(static function() use (&$manifest) {
                return $manifest;
            });
            $options = ['poster' => false, 'tracks' => 'none'];
            $hls = (string)$renderer->element($asset, $options);
            self::assertStringContainsString('<mux-video', $hls);
            self::assertStringContainsString('playback-id="abc123"', $hls);
            self::assertStringContainsString('stream-type="on-demand"', $hls);
            self::assertStringNotContainsString(' src=', $hls);
            $mp4 = (string)$renderer->player($asset, $options + ['playbackFormat' => 'mp4', 'children' => '<media-control-bar></media-control-bar>',
                'mediaAttrs' => ['SRC' => 'bad', 'playback-id' => 'bad', 'type' => 'application/x-mpegURL'], ]);
            self::assertStringContainsString('<media-controller>', $mp4);
            self::assertStringContainsString('<video src="https://stream.mux.com/abc123/highest.mp4"', $mp4);
            self::assertStringContainsString('slot="media"', $mp4);
            self::assertStringContainsString('<media-control-bar>', $mp4);
            self::assertStringNotContainsString('bad', $mp4);
            self::assertStringNotContainsString('application/x-mpegURL', $mp4);
            $manifest['type'] = 'bunny';
            self::assertStringContainsString('<hls-video', (string)$renderer->element($asset, $options));
            self::assertStringContainsString('<video', (string)$renderer->element($asset, $options + ['playbackFormat' => 'mp4']));
            $scripts = (string)$renderer->scripts(['bunny']);
            self::assertStringContainsString('hls-video-element@1', $scripts);
            self::assertStringNotContainsString('@mux/mux-video', $scripts);
            self::assertSame(1, substr_count((string)$renderer->scripts(['providers' => ['hls', 'bunny']]), 'hls-video-element@1'));
            $manifest['metadata']['bunnyStatus'] = -1;
            self::assertSame('', (string)$renderer->element($asset, $options));
            self::assertSame('', (string)$renderer->player($asset, $options));
        } finally {
            Craft::$app = $previousApp;
        }
    }
}
