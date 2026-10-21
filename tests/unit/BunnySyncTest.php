<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\BunnySync;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\PosterFetcher;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\models\VolumeFolder;
use PHPUnit\Framework\TestCase;
use yii\mutex\FileMutex;

/**
 * Bunny import and the shared, lock-guarded state pipeline.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnySyncTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';

    // Private Properties
    // =========================================================================

    private mixed $_previousApp;
    private BunnySyncPlugin $_plugin;
    private MediaItemRecord $_record;
    private array $_state;
    private bool $_locked = false;
    private bool $_acquire = true;
    private bool $_exists = true;
    private bool $_saveSucceeds = true;
    private int $_saves = 0;
    private int $_reads = 0;
    private bool $_concurrentSync = false;
    private ?Asset $_asset = null;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        $this->_record = $this->_newRecord();
        $this->_state = [
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-old.b-cdn.net',
            'bunnyStatus' => 4,
            'status' => 'ready',
            'isPublic' => true,
            'playbackPolicy' => 'public',
            'mp4Renditions' => [],
            'thumbnailUrl' => null,
            'duration' => 12.5,
            'width' => 1920,
            'height' => 1080,
            'title' => 'Remote title',
            'url' => 'https://vz-old.b-cdn.net/' . self::VIDEO . '/playlist.m3u8',
        ];

        $mutex = $this->createMock(FileMutex::class);
        $mutex->method('acquire')->willReturnCallback(function(string $name): bool {
            if (str_starts_with($name, 'polymedia:bunny-import:')) {
                return true;
            }

            self::assertFalse($this->_locked, 'Item locks are not re-entrant.');

            return $this->_locked = $this->_acquire;
        });
        $mutex->method('release')->willReturnCallback(function(string $name): bool {
            if (!str_starts_with($name, 'polymedia:bunny-import:')) {
                $this->_locked = false;
            }

            return true;
        });
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getAssetById')->willReturnCallback(fn() => $this->_asset);
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getMutex', 'getAssets'])->getMock();
        $app->method('getMutex')->willReturn($mutex);
        $app->method('getAssets')->willReturn($assets);
        Craft::$app = $app;

        $items = $this->getMockBuilder(MediaItems::class)
            ->onlyMethods(['getById', 'getByAssetId', 'getByTypeAndProviderId', 'save'])->getMock();
        $items->method('getById')->willReturnCallback(fn() => $this->_exists ? clone $this->_record : null);
        $items->method('getByAssetId')->willReturnCallback(fn() => $this->_exists ? clone $this->_record : null);
        $items->method('getByTypeAndProviderId')->willReturnCallback(fn() => $this->_exists ? clone $this->_record : null);
        $items->method('save')->willReturnCallback(function(MediaItemRecord $record): bool {
            self::assertTrue($this->_locked, 'Writes happen under the item lock.');
            $this->_saves++;

            if ($this->_saveSucceeds) {
                $this->_record = clone $record;
            }

            return $this->_saveSucceeds;
        });

        $bunny = $this->createMock(Bunny::class);
        $bunny->method('getLibraryId')->willReturn('133');
        $bunny->method('getAsset')->willReturnCallback(function(string $video, ?string $host = null): array {
            self::assertFalse($this->_locked, 'Slow network requests must not hold the item lock.');
            self::assertSame(self::VIDEO, $video);
            $this->_reads++;

            if ($this->_concurrentSync) {
                $metadata = json_decode($this->_record->metadata, true);
                $metadata['bunnySyncVersion'] = 1;
                $metadata['bunnyStatus'] = 4;
                $this->_record->metadata = json_encode($metadata);
            }

            return $this->_state;
        });

        $this->_plugin = (new \ReflectionClass(BunnySyncPlugin::class))->newInstanceWithoutConstructor();
        $this->_plugin->items = $items;
        $this->_plugin->bunny = $bunny;
        $this->_plugin->posters = $this->createMock(PosterFetcher::class);
        $this->_plugin->writer = $this->createMock(ManifestWriter::class);
        Craft::$app->loadedModules[Plugin::class] = $this->_plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public function testUnknownItemsNeverReadRemoteOrImport(): void
    {
        $this->_exists = false;

        self::assertNull((new BunnySync())->syncVideo('133', self::VIDEO));
        self::assertSame(0, $this->_reads);
        self::assertSame(0, $this->_saves);
    }

    public function testSyncUsesTheStoredOriginAndPreservesEditorFields(): void
    {
        $this->_plugin->bunny->expects(self::exactly(2))->method('getAsset')->with(self::VIDEO, 'vz-old.b-cdn.net');
        $sync = new BunnySync();
        $sync->syncRecord($this->_record);
        $sync->syncRecord($this->_record);

        $metadata = json_decode($this->_record->metadata, true);
        self::assertSame(1, $this->_saves, 'Unchanged state writes nothing.');
        self::assertSame(13, $this->_record->duration);
        self::assertSame(1920, $this->_record->width);
        self::assertSame('editor.jpg', $metadata['thumbnail']);
        self::assertSame(1, $metadata['bunnySyncVersion']);
        self::assertFalse($this->_locked);
    }

    public function testLockFailureIsRetryableWithoutWriting(): void
    {
        $this->_acquire = false;

        try {
            (new BunnySync())->syncRecord($this->_record);
            self::fail('Lock failure must not report success.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->_saves);
        }
    }

    public function testSaveFailureIsRetryableAndReleasesLock(): void
    {
        $this->_saveSucceeds = false;

        try {
            (new BunnySync())->syncRecord($this->_record);
            self::fail('Save failure must not report success.');
        } catch (\RuntimeException) {
            self::assertSame(5, $this->_record->duration);
            self::assertFalse($this->_locked);
        }
    }

    public function testCdnPropagationDelayDoesNotOverwriteStoredState(): void
    {
        $this->_state['isPublic'] = null;

        try {
            (new BunnySync())->syncRecord($this->_record);
            self::fail('CDN propagation delay must request a retry.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->_saves);
            self::assertFalse($this->_locked);
        }
    }

    public function testOverlappingSyncResponseCannotOverwriteNewerState(): void
    {
        $this->_concurrentSync = true;
        $this->_state['bunnyStatus'] = 2;

        try {
            (new BunnySync())->syncRecord($this->_record);
            self::fail('A competing sync must make this response retryable.');
        } catch (\RuntimeException) {
            self::assertSame(4, json_decode($this->_record->metadata, true)['bunnyStatus']);
            self::assertSame(0, $this->_saves);
            self::assertFalse($this->_locked);
        }
    }

    public function testForeignLibraryIsNotSynced(): void
    {
        $this->_record->providerId = '134:' . self::VIDEO;

        try {
            (new BunnySync())->syncRecord($this->_record);
            self::fail('A record from another library must not sync.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->_reads);
        }
    }

    public function testPublicThumbnailQueuesPosterOutsideTheLock(): void
    {
        $this->_state['thumbnailUrl'] = 'https://vz-old.b-cdn.net/' . self::VIDEO . '/thumbnail.jpg';
        $this->_plugin->posters->expects(self::once())->method('ensureBunnyPoster')
            ->willReturnCallback(fn() => self::assertFalse($this->_locked));

        (new BunnySync())->syncRecord($this->_record);
        self::assertSame($this->_state['thumbnailUrl'], json_decode($this->_record->metadata, true)['bunnyThumbnailUrl']);
    }

    public function testImportReusesAVisibleExistingItem(): void
    {
        $this->_asset = $this->_assetMock(true);
        $this->_plugin->writer->expects(self::never())->method('create');

        $result = (new BunnySync())->importVideo(self::VIDEO, new VolumeFolder(['id' => 4, 'volumeId' => 2]), $this->createMock(User::class));

        self::assertTrue($result['reused']);
        self::assertSame(1, $this->_reads, 'A re-import refreshes state.');
    }

    public function testImportRefusesAnExistingItemTheUserCannotView(): void
    {
        $this->_asset = $this->_assetMock(false);
        $this->expectException(\yii\web\ForbiddenHttpException::class);

        (new BunnySync())->importVideo(self::VIDEO, new VolumeFolder(['id' => 4, 'volumeId' => 2]), $this->createMock(User::class));
    }

    public function testImportRequiresPublicPlayback(): void
    {
        $this->_exists = false;
        $this->_state['isPublic'] = false;
        $this->_plugin->writer->expects(self::never())->method('create');
        $this->expectException(\RuntimeException::class);

        (new BunnySync())->importVideo(self::VIDEO, new VolumeFolder(['id' => 4, 'volumeId' => 2]), $this->createMock(User::class));
    }

    public function testImportCreatesAPinnedManifest(): void
    {
        $this->_exists = false;
        $asset = $this->_assetMock(true);
        $this->_plugin->writer->expects(self::once())->method('create')->willReturnCallback(
            function(int $volumeId, int $folderId, DetectionResult $detection, string $title, ?string $thumbnail, array $metadata) use ($asset): Asset {
                self::assertSame([2, 4], [$volumeId, $folderId]);
                self::assertSame('bunny', $detection->type);
                self::assertSame('133:' . self::VIDEO, $detection->providerId);
                self::assertSame('hls-video', $detection->element);
                self::assertSame('Remote title', $title);
                self::assertSame(['bunnyLibraryId' => '133', 'bunnyVideoId' => self::VIDEO, 'bunnyCdnHostname' => 'vz-old.b-cdn.net'], $metadata);
                $this->_exists = true;

                return $asset;
            },
        );

        $result = (new BunnySync())->importVideo(self::VIDEO, new VolumeFolder(['id' => 4, 'volumeId' => 2]), $this->createMock(User::class));

        self::assertFalse($result['reused']);
        self::assertSame(4, json_decode($this->_record->metadata, true)['bunnyStatus']);
        self::assertSame(1, $this->_reads, 'The fetched state is applied without a second request.');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns an imported media item record.
     */
    private function _newRecord(): MediaItemRecord
    {
        $record = new class() extends MediaItemRecord {
            public $id = 7;
            public $assetId = 70;
            public $type = 'bunny';
            public $providerId = '133:657bb740-a71b-4529-a012-528021c31a92';
            public $metadata;
            public $duration = 5;
            public $width;
            public $height;
        };
        $record->metadata = json_encode([
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-old.b-cdn.net',
            'thumbnail' => 'editor.jpg',
        ]);

        return $record;
    }

    /**
     * Returns a mock asset the user can or can't view.
     */
    private function _assetMock(bool $canView): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = 70;
        $asset->method('canView')->willReturn($canView);

        return $asset;
    }
}

/**
 * Plugin fixture with stubbed services.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnySyncPlugin extends Plugin
{
    // Public Properties
    // =========================================================================

    public MediaItems $items;
    public Bunny $bunny;
    public PosterFetcher $posters;
    public ManifestWriter $writer;

    // Public Methods
    // =========================================================================

    public function getMediaItems(): MediaItems
    {
        return $this->items;
    }

    public function getBunny(): Bunny
    {
        return $this->bunny;
    }

    public function getPosterFetcher(): PosterFetcher
    {
        return $this->posters;
    }

    public function getManifestWriter(): ManifestWriter
    {
        return $this->writer;
    }
}
