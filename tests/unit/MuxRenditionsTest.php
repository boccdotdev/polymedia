<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\WebhooksController;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\Mux;
use Craft;
use MuxPhp\Api\DirectUploadsApi;
use MuxPhp\Models\Asset;
use MuxPhp\Models\AssetStaticRenditions;
use MuxPhp\Models\PlaybackID;
use MuxPhp\Models\StaticRendition;
use MuxPhp\Models\Upload;
use MuxPhp\Models\UploadResponse;
use PHPUnit\Framework\TestCase;

/**
 * Mux MP4 static rendition requests, mapping and merging.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class MuxRenditionsTest extends TestCase
{
    // Public Methods
    // =========================================================================

    public function testUploadRequestsStaticRenditionOnlyWhenMp4IsPreferred(): void
    {
        $previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;

        try {
            foreach (['hls', 'mp4'] as $format) {
                $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
                    ->onlyMethods(['getSettings'])->getMock();
                $plugin->method('getSettings')->willReturn(new Settings(['muxDefaultPlaybackFormat' => $format]));
                $app->loadedModules[Plugin::class] = $plugin;

                $mux = $this->getMockBuilder(Mux::class)->onlyMethods(['isConfigured'])->getMock();
                $mux->method('isConfigured')->willReturn(true);
                $api = $this->createMock(DirectUploadsApi::class);
                $api->expects(self::once())->method('createDirectUpload')->willReturnCallback(
                    function($request) use ($format) {
                        $asset = $request->getNewAssetSettings();
                        self::assertNull($asset->getMp4Support(), 'Never the deprecated mp4_support flag.');

                        if ($format === 'mp4') {
                            self::assertSame('highest', $asset->getStaticRenditions()[0]->getResolution());
                        } else {
                            self::assertNull($asset->getStaticRenditions());
                        }

                        return new UploadResponse(['data' => new Upload(['id' => 'upload', 'status' => 'waiting'])]);
                    },
                );
                (new \ReflectionProperty(Mux::class, '_uploadsApi'))->setValue($mux, $api);
                $mux->createDirectUpload('', ['corsOrigin' => 'https://example.test']);
            }
        } finally {
            Craft::$app = $previousApp;
        }
    }

    public function testSdkAssetMapsPerFileStatusAndFilename(): void
    {
        $asset = new Asset([
            'status' => 'ready',
            'playback_ids' => [new PlaybackID(['id' => 'abc123', 'policy' => 'public'])],
            'static_renditions' => new AssetStaticRenditions(['files' => [
                new StaticRendition(['name' => 'highest.mp4', 'status' => 'preparing', 'width' => 1920, 'height' => 1080]),
            ]]),
        ]);

        self::assertSame(
            [['url' => 'https://stream.mux.com/abc123/highest.mp4', 'status' => 'preparing', 'width' => 1920, 'height' => 1080]],
            (new Mux())->mapAsset($asset)['mp4Renditions'],
        );
    }

    public function testSignedPlaybackMapsNoRenditions(): void
    {
        $asset = new Asset([
            'playback_ids' => [new PlaybackID(['id' => 'abc123', 'policy' => 'signed'])],
            'static_renditions' => new AssetStaticRenditions(['files' => [
                new StaticRendition(['name' => 'highest.mp4', 'status' => 'ready']),
            ]]),
        ]);

        self::assertSame([], (new Mux())->mapAsset($asset)['mp4Renditions']);
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
        self::assertSame([], Mux::mapMp4Renditions($data, 'abc/../x'));
    }

    public function testWebhookPayloadOnlyCarriesRenditionsWhenPresent(): void
    {
        $data = ['id' => 'asset', 'playback_ids' => [['id' => 'abc123', 'policy' => 'public']]];

        self::assertArrayNotHasKey('mp4Renditions', Mux::mapWebhookAssetData($data));
        self::assertSame([], Mux::mapWebhookAssetData($data + ['static_renditions' => []])['mp4Renditions']);
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

    public function testRenditionEventsResolveTheirAsset(): void
    {
        self::assertTrue(WebhooksController::isRenditionEvent('video.asset.static_rendition.ready'));
        self::assertTrue(WebhooksController::isRenditionEvent('video.asset.static_renditions.ready'));
        self::assertFalse(WebhooksController::isRenditionEvent('video.asset.ready'));

        // A per-file event's data.id is the rendition, not the asset.
        self::assertSame('', WebhooksController::renditionAssetId('video.asset.static_rendition.ready', ['data' => ['id' => 'rendition']]));
        self::assertSame('asset', WebhooksController::renditionAssetId('video.asset.static_rendition.ready', [
            'data' => ['id' => 'rendition'],
            'object' => ['type' => 'asset', 'id' => 'asset'],
        ]));
        self::assertSame('asset', WebhooksController::renditionAssetId('video.asset.static_renditions.ready', ['data' => ['id' => 'asset']]));
    }
}
