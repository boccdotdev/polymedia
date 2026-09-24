<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\records\RelatedAssetRecord;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\PosterFetcher;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\SidecarStorage;
use Craft;
use craft\elements\Asset;
use craft\models\VolumeFolder;
use PHPUnit\Framework\TestCase;
use yii\mutex\FileMutex;

/**
 * Exercises the production orchestration with controlled persistence and HTTP.
 * Interleavings run at the download boundary, without sleeps or a database.
 */
class MuxConcurrencyTest extends TestCase
{
    private mixed $previousApp;
    private MediaItems $items;
    private RelatedAssets $related;
    private SidecarStorage $storage;
    private MediaItemRecord $record;
    private ?Asset $poster = null;
    private bool $exists = true;
    private bool $locked = false;
    private bool $acquire = true;
    private bool $saveSucceeds = true;
    private int $saves = 0;
    private int $created = 0;
    private bool $attachmentFails = false;
    private Plugin $plugin;
    private \craft\services\Elements $elements;

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        $this->record = new class() extends MediaItemRecord {
            public $id;
            public $assetId;
            public $metadata;
            public $duration;
            public $title;
            public $type;

            public function attributes(): array
            {
                return ['id', 'assetId', 'metadata', 'duration', 'title', 'type'];
            }
        };
        $this->record->setAttributes([
            'id' => 7, 'assetId' => 70, 'metadata' => '{}',
            'duration' => null, 'title' => 'Example', 'type' => 'mux',
        ], false);

        $mutex = $this->createMock(FileMutex::class);
        $mutex->method('acquire')->willReturnCallback(function(): bool {
            self::assertFalse($this->locked, 'Item locks must not nest.');
            return $this->locked = $this->acquire;
        });
        $mutex->method('release')->willReturnCallback(function(): bool {
            $this->locked = false;
            return true;
        });
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getAssetById')->willReturnCallback(fn() => $this->exists ? $this->asset(70) : null);
        $this->elements = $this->createMock(\craft\services\Elements::class);
        $app = $this->getMockBuilder(\craft\web\Application::class)
            ->disableOriginalConstructor()->onlyMethods(['getMutex', 'getAssets', 'getElements'])->getMock();
        $app->method('getMutex')->willReturn($mutex);
        $app->method('getAssets')->willReturn($assets);
        $app->method('getElements')->willReturn($this->elements);
        Craft::$app = $app;

        $this->items = $this->getMockBuilder(MediaItems::class)
            ->onlyMethods(['getById', 'save', 'patchMetadata', 'getByMuxAssetId', 'getByAssetId', 'deleteByAssetId'])->getMock();
        $this->items->method('getById')->willReturnCallback(fn() => $this->exists ? clone $this->record : null);
        $this->items->method('getByMuxAssetId')->willReturn(null);
        $this->items->method('save')->willReturnCallback(function(MediaItemRecord $record): bool {
            $this->saves++;
            if ($this->saveSucceeds) {
                $this->record = clone $record;
            }
            return $this->saveSucceeds;
        });
        $this->items->method('patchMetadata')->willReturnCallback(function(): ?array {
            self::assertFalse($this->locked);
            return null;
        });
        $this->related = $this->getMockBuilder(RelatedAssets::class)
            ->onlyMethods(['getPoster', 'attach', 'clearPoster', 'isAssetRelated'])->getMock();
        $this->related->method('getPoster')->willReturnCallback(fn() => $this->poster);
        $this->related->method('clearPoster')->willReturnCallback(function(): int {
            self::assertTrue($this->locked);
            $this->poster = null;
            return 1;
        });
        $this->related->method('attach')->willReturnCallback(function(int $itemId, int $assetId): RelatedAssetRecord {
            self::assertTrue($this->locked);
            if ($this->attachmentFails) {
                throw new \RuntimeException('Attachment failed.');
            }
            $this->poster = $this->asset($assetId);
            return new RelatedAssetRecord();
        });
        $this->storage = $this->createMock(SidecarStorage::class);
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getMediaItems', 'getRelatedAssets', 'getSidecarStorage', 'getSettings', 'getMux', 'getPosterFetcher'])->getMock();
        $plugin->method('getMediaItems')->willReturn($this->items);
        $plugin->method('getRelatedAssets')->willReturn($this->related);
        $plugin->method('getSidecarStorage')->willReturn($this->storage);
        $plugin->method('getSettings')->willReturn(new \boccdotdev\polymedia\models\Settings());
        $this->plugin = $plugin;
        Craft::$app->loadedModules[Plugin::class] = $plugin;
    }

    protected function tearDown(): void
    {
        \yii\base\Event::off(Asset::class, Asset::EVENT_BEFORE_DELETE);
        \yii\base\Event::off(\craft\services\Elements::class, \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT);
        Craft::$app = $this->previousApp;
    }

    public function testStateLockFailureIsRetryableAndUnchangedIsSuccess(): void
    {
        $this->acquire = false;
        try {
            $this->items->applyMuxAssetState('mux-1', ['status' => 'errored'], $this->record);
            self::fail('Lock failure must not report success.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->saves);
        }
        $this->acquire = true;
        self::assertNotNull($this->items->applyMuxAssetState('mux-1', ['status' => 'errored'], $this->record));
        self::assertNotNull($this->items->applyMuxAssetState('mux-1', ['status' => 'errored'], $this->record));
        self::assertSame(1, $this->saves);
        self::assertFalse($this->locked);
    }

    public function testStateSaveFailureIsRetryable(): void
    {
        $this->saveSucceeds = false;
        try {
            $this->items->applyMuxAssetState('mux-1', ['status' => 'errored'], clone $this->record);
            self::fail('Save failure must not report success.');
        } catch (\RuntimeException) {
            self::assertSame('{}', $this->record->metadata);
            self::assertFalse($this->locked);
        }
        $this->saveSucceeds = true;
        self::assertNotNull($this->items->applyMuxAssetState('mux-1', ['status' => 'errored'], clone $this->record));
        self::assertSame(2, $this->saves);
    }

    public function testUnknownAndDisappearedStateAreIgnored(): void
    {
        self::assertNull($this->items->applyMuxAssetState('unknown', []));
        $this->exists = false;
        self::assertNull($this->items->applyMuxAssetState('mux-1', [], $this->record));
        self::assertSame(0, $this->saves);
    }

    /** @dataProvider forceModes */
    public function testEditorPosterAttachedDuringDownloadWins(bool $force): void
    {
        $this->storage->expects(self::never())->method('getItemFolder');
        $fetcher = $this->fetcher(function(): void {
            $this->poster = $this->asset(88);
        });
        self::assertSame(88, $fetcher->fetchForItem($this->record, 'https://example.test/poster', $force)?->id);
        self::assertSame(0, $this->created);
    }

    public static function forceModes(): array
    {
        return [[false], [true]];
    }

    public function testDisappearedItemDoesNotRecreateStorage(): void
    {
        $this->storage->expects(self::never())->method('getItemFolder');
        $fetcher = $this->fetcher(function(): void {
            $this->exists = false;
        });
        self::assertNull($fetcher->fetchForItem($this->record, 'https://example.test/poster'));
        self::assertSame(0, $this->created);
    }

    public function testPosterLockTimeoutIsRetryableWithoutCreatingAsset(): void
    {
        $this->acquire = false;
        $this->storage->expects(self::never())->method('getItemFolder');
        $this->expectException(\RuntimeException::class);
        $this->fetcher()->fetchForItem($this->record, 'https://example.test/poster');
    }

    public function testConcurrentFetchesCreateOnlyOneAsset(): void
    {
        $this->allowFolder();
        $second = $this->fetcher();
        $first = $this->fetcher(function() use ($second): void {
            $second->fetchForItem($this->record, 'https://example.test/poster');
        });
        self::assertSame(101, $first->fetchForItem($this->record, 'https://example.test/poster')?->id);
        self::assertSame(1, $this->created);
    }

    public function testForceReplacesUnchangedInitialPoster(): void
    {
        $this->poster = $this->asset(88);
        $this->allowFolder();
        self::assertSame(101, $this->fetcher()->fetchForItem($this->record, 'https://example.test/poster', true)?->id);
        self::assertSame(1, $this->created);
    }

    public function testManualClearDuringForcedDownloadWins(): void
    {
        $this->poster = $this->asset(88);
        $this->storage->expects(self::never())->method('getItemFolder');
        $fetcher = $this->fetcher(function(): void {
            $this->related->savePoster($this->record, []);
        });
        self::assertNull($fetcher->fetchForItem($this->record, 'https://example.test/poster', true));
        self::assertSame(0, $this->created);
    }

    /** @dataProvider candidateReferences */
    public function testFailedAttachmentDiscardsOnlyUnreferencedCandidate(bool $referenced): void
    {
        $this->allowFolder();
        $this->poster = $this->asset(88);
        $this->attachmentFails = true;
        $this->related->method('isAssetRelated')->with(101)->willReturn($referenced);
        $this->elements->expects($referenced ? self::never() : self::once())->method('deleteElement')
            ->with(self::callback(fn(Asset $asset) => $asset->id === 101), true)->willReturn(true);
        try {
            $this->fetcher()->fetchForItem($this->record, 'https://example.test/poster', true);
            self::fail('Attachment failure must propagate.');
        } catch (\RuntimeException $e) {
            self::assertSame('Attachment failed.', $e->getMessage());
            self::assertSame(88, $this->poster->id);
            self::assertFalse($this->locked);
        }
    }

    public static function candidateReferences(): array
    {
        return [[false], [true]];
    }

    /** @dataProvider candidateReferences */
    public function testPosterJobRetriesFailureUnlessItemDisappeared(bool $disappeared): void
    {
        $mux = $this->createMock(\boccdotdev\polymedia\services\Mux::class);
        $mux->method('firstFrameThumbnailUrl')->willReturn('https://example.test/poster');
        $this->plugin->method('getMux')->willReturn($mux);
        $fetcher = $this->createMock(PosterFetcher::class);
        $fetcher->method('fetchForItem')->willReturnCallback(function() use ($disappeared): never {
            $this->exists = !$disappeared;
            throw new \RuntimeException('Lock unavailable.');
        });
        $fetcher->expects($disappeared ? self::never() : self::once())->method('queueMuxPoster')
            ->with(self::anything(), 'playback-1', 1);
        $this->plugin->method('getPosterFetcher')->willReturn($fetcher);
        $job = $this->getMockBuilder(\boccdotdev\polymedia\jobs\FetchMuxPoster::class)
            ->onlyMethods(['setProgress'])->getMock();
        $job->itemId = 7;
        $job->playbackId = 'playback-1';
        $job->execute($this->createMock(\craft\queue\QueueInterface::class));
    }

    public function testHardDeleteCapturesRecordBeforeCascadeAndCleansAfterCommit(): void
    {
        $register = new \ReflectionMethod(Plugin::class, '_registerAssetDeleteHandler');
        $register->invoke($this->plugin);
        $asset = $this->asset(70);
        $asset->kind = 'polymedia';
        $asset->hardDelete = true;
        $this->items->expects(self::once())->method('getByAssetId')->with(70)->willReturn($this->record);
        $this->items->expects(self::once())->method('deleteByAssetId')->with(70)->willReturn(0);
        $this->storage->expects(self::once())->method('deleteForAsset')->with($asset)
            ->willReturnCallback(function(): void {
                self::assertTrue($this->locked);
                self::assertFalse($this->exists, 'Cleanup must follow the cascade.');
            });

        \yii\base\Event::trigger($asset, Asset::EVENT_BEFORE_DELETE);
        $this->exists = false;
        // Asset after-delete is still inside Craft's DB transaction.
        \yii\base\Event::trigger($asset, Asset::EVENT_AFTER_DELETE);
        self::assertFalse($this->locked);
        \yii\base\Event::trigger($this->elements, \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT,
            new \craft\events\ElementEvent(['element' => $asset]));
    }

    public function testSoftDeleteDoesNotCaptureOrCleanSidecars(): void
    {
        (new \ReflectionMethod(Plugin::class, '_registerAssetDeleteHandler'))->invoke($this->plugin);
        $asset = $this->asset(70);
        $asset->kind = 'polymedia';
        $asset->hardDelete = false;
        $this->items->expects(self::never())->method('getByAssetId');
        $this->storage->expects(self::never())->method('deleteForAsset');
        \yii\base\Event::trigger($asset, Asset::EVENT_BEFORE_DELETE);
        \yii\base\Event::trigger($this->elements, \craft\services\Elements::EVENT_AFTER_DELETE_ELEMENT,
            new \craft\events\ElementEvent(['element' => $asset]));
    }

    private function allowFolder(): void
    {
        $folder = new VolumeFolder();
        $folder->id = 3;
        $folder->volumeId = 4;
        $this->storage->method('getItemFolder')->willReturnCallback(function() use ($folder): VolumeFolder {
            self::assertTrue($this->locked);
            return $folder;
        });
    }

    private function fetcher(?callable $duringDownload = null): PosterFetcher
    {
        $fetcher = $this->getMockBuilder(PosterFetcher::class)
            ->onlyMethods(['downloadToTemp', '_createPosterAsset'])->getMock();
        $fetcher->method('downloadToTemp')->willReturnCallback(function() use ($duringDownload): array {
            self::assertFalse($this->locked, 'Do not hold item locks during HTTP.');
            $duringDownload?->__invoke();
            return ['path' => '/nonexistent/polymedia-unit-test', 'extension' => 'jpg'];
        });
        $fetcher->method('_createPosterAsset')->willReturnCallback(function(): Asset {
            self::assertTrue($this->locked);
            return $this->asset(100 + ++$this->created);
        });
        return $fetcher;
    }

    private function asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = $id;
        return $asset;
    }
}
