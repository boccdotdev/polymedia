<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\Mux;
use MuxPhp\Models\Asset;
use MuxPhp\Models\AssetStaticRenditions;
use MuxPhp\Models\PlaybackID;
use MuxPhp\Models\StaticRendition;
use PHPUnit\Framework\TestCase;

class MuxRenditionsTest extends TestCase
{
    public function testUploadRequestsOnlyNewStaticRenditionsWhenPreferred(): void
    {
        $previousApp = \Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        \Craft::$app = $app;
        try {
            foreach (['hls', 'mp4'] as $format) {
                $settings = new class() extends \boccdotdev\polymedia\models\Settings {
                    public string $muxDefaultPlaybackFormat = 'hls';
                };
                $settings->muxDefaultPlaybackFormat = $format;
                $plugin = $this->getMockBuilder(\boccdotdev\polymedia\Plugin::class)->disableOriginalConstructor()
                    ->onlyMethods(['getSettings'])->getMock();
                $plugin->method('getSettings')->willReturn($settings);
                $app->loadedModules[\boccdotdev\polymedia\Plugin::class] = $plugin;
                $mux = $this->getMockBuilder(Mux::class)->onlyMethods(['isConfigured'])->getMock();
                $mux->method('isConfigured')->willReturn(true);
                $api = $this->createMock(\MuxPhp\Api\DirectUploadsApi::class);
                $api->expects(self::once())->method('createDirectUpload')->willReturnCallback(
                    function($request) use ($format) {
                        $asset = $request->getNewAssetSettings();
                        self::assertNull($asset->getMp4Support());
                        if ($format === 'mp4') {
                            self::assertSame('highest', $asset->getStaticRenditions()[0]->getResolution());
                        } else {
                            self::assertNull($asset->getStaticRenditions());
                        }
                        return new \MuxPhp\Models\UploadResponse(['data' => new \MuxPhp\Models\Upload(['id' => 'upload', 'status' => 'waiting'])]);
                    },
                );
                (new \ReflectionProperty(Mux::class, '_uploadsApi'))->setValue($mux, $api);
                $mux->createDirectUpload('', ['corsOrigin' => 'https://example.test']);
            }
        } finally {
            \Craft::$app = $previousApp;
        }
    }

    public function testInstalledSdkMapsPerFileStatusAndFilename(): void
    {
        $asset = new Asset([
            'status' => 'ready',
            'playback_ids' => [new PlaybackID(['id' => 'abc123', 'policy' => 'public'])],
            'static_renditions' => new AssetStaticRenditions(['files' => [
                new StaticRendition(['name' => 'highest.mp4', 'status' => 'preparing', 'width' => 1920, 'height' => 1080]),
            ]]),
        ]);
        $mapped = (new Mux())->mapAsset($asset);
        self::assertSame([['url' => 'https://stream.mux.com/abc123/highest.mp4', 'status' => 'preparing', 'width' => 1920, 'height' => 1080]], $mapped['mp4Renditions']);
    }

    public function testLegacyAggregateReadinessAndInvalidFiles(): void
    {
        $data = ['mp4_support' => 'standard', 'static_renditions' => ['status' => 'ready', 'files' => [
            ['name' => 'high.mp4', 'width' => 1920, 'height' => 1080],
            ['name' => '../high.mp4'],
            ['name' => 'audio.m4a'],
        ]]];
        self::assertSame('ready', Mux::mapMp4Renditions($data, 'abc123')[0]['status']);
        self::assertCount(1, Mux::mapMp4Renditions($data, 'abc123'));
        unset($data['mp4_support']);
        self::assertSame('preparing', Mux::mapMp4Renditions($data, 'abc123')[0]['status']);
        self::assertSame([], Mux::mapMp4Renditions($data, null));
    }

    public function testMergePreservesEditorDataAndPartialEvents(): void
    {
        $metadata = ['chapters' => [['title' => 'Intro']], 'mp4Renditions' => [['status' => 'ready']]];
        $merged = MediaItems::mergeMuxAssetState($metadata, 4, 'asset', ['status' => 'ready', 'mp4Renditions' => []]);
        self::assertSame($metadata['chapters'], $merged['metadata']['chapters']);
        self::assertSame([], $merged['metadata']['mp4Renditions']);
        self::assertTrue($merged['changed']);
        $again = MediaItems::mergeMuxAssetState($merged['metadata'], 4, 'asset', ['status' => 'ready']);
        self::assertFalse($again['changed']);
        self::assertSame([], $again['metadata']['mp4Renditions']);
    }
}
