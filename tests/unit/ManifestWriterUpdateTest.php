<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use Craft;
use craft\base\FsInterface;
use craft\elements\Asset;
use craft\helpers\Json;
use craft\models\Volume;
use PHPUnit\Framework\TestCase;

/**
 * Manifest updates replace metadata as a whole and merge everything else.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class ManifestWriterUpdateTest extends TestCase
{
    // Private Properties
    // =========================================================================

    private mixed $_previousApp;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        Craft::$app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods([])->getMock();
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public function testMetadataListsCanShrinkWhileOtherKeysMerge(): void
    {
        $record = new class() extends MediaItemRecord {
            public $id;
            public $type;
            public $url;
            public $title;
            public $providerId;
            public $element;
            public $duration;
            public $width;
            public $height;
            public $metadata;

            public function attributes(): array
            {
                return ['id', 'type', 'url', 'title', 'providerId', 'element', 'duration', 'width', 'height', 'metadata'];
            }
        };
        $record->setAttributes([
            'id' => 7, 'type' => 'bunny', 'url' => 'https://vz-1.b-cdn.net/v/playlist.m3u8', 'title' => 'Example',
            'providerId' => 'v', 'element' => 'hls-video', 'duration' => 30, 'width' => null, 'height' => null,
            'metadata' => Json::encode([
                'bunnyVideoId' => 'v',
                'mp4Renditions' => [['height' => 720], ['height' => 360]],
            ]),
        ], false);

        $items = $this->createMock(MediaItems::class);
        $items->method('getByAssetId')->willReturn($record);
        $items->expects(self::once())->method('save')->willReturn(true);
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getMediaItems'])->getMock();
        $plugin->method('getMediaItems')->willReturn($items);
        Craft::$app->loadedModules[Plugin::class] = $plugin;

        $written = null;
        $fs = $this->createMock(FsInterface::class);
        $fs->method('write')->willReturnCallback(function(string $path, string $contents) use (&$written): void {
            $written = Json::decode($contents);
        });
        $volume = $this->createMock(Volume::class);
        $volume->method('getFs')->willReturn($fs);
        $asset = $this->createMock(Asset::class);
        $asset->id = 70;
        $asset->kind = 'polymedia';
        $asset->method('getVolume')->willReturn($volume);
        $asset->method('getPath')->willReturn('example.pmedia');

        (new ManifestWriter())->update($asset, [
            'title' => 'Renamed',
            'metadata' => ['bunnyVideoId' => 'v', 'mp4Renditions' => []],
        ]);

        self::assertSame([], $written['metadata']['mp4Renditions']);
        self::assertSame('Renamed', $written['title']);
        self::assertSame('https://vz-1.b-cdn.net/v/playlist.m3u8', $written['url']);
        self::assertSame([], Json::decode($record->metadata)['mp4Renditions']);
    }
}
