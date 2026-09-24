<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\BunnySync;
use boccdotdev\polymedia\services\MediaItems;
use Craft;
use PHPUnit\Framework\TestCase;
use yii\mutex\FileMutex;

class BunnySyncTest extends TestCase
{
    private mixed $previousApp;
    private BunnySyncPlugin $plugin;
    private MediaItemRecord $record;
    private array $state;
    private bool $locked = false;
    private bool $acquire = true;
    private bool $exists = true;
    private bool $saveSucceeds = true;
    private int $saves = 0;
    private int $reads = 0;
    private bool $concurrentSync = false;

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        $this->record = new class() extends MediaItemRecord {
            public $id = 7;
            public $assetId = 70;
            public $type = 'bunny';
            public $providerId = '133:657bb740-a71b-4529-a012-528021c31a92';
            public $metadata;
            public $duration = 5;
        };
        $this->record->metadata = json_encode([
            'bunnyLibraryId' => '133', 'bunnyVideoId' => '657bb740-a71b-4529-a012-528021c31a92',
            'bunnyCdnHostname' => 'vz-old.b-cdn.net', 'thumbnail' => 'editor.jpg',
        ]);
        $this->state = [
            'bunnyLibraryId' => '133', 'bunnyVideoId' => '657bb740-a71b-4529-a012-528021c31a92',
            'bunnyCdnHostname' => 'vz-old.b-cdn.net', 'bunnyStatus' => 4,
            'status' => 'ready', 'isPublic' => true, 'playbackPolicy' => 'public',
            'mp4Renditions' => [], 'thumbnailUrl' => null, 'duration' => 12.5,
        ];
        $mutex = $this->createMock(FileMutex::class);
        $mutex->method('acquire')->willReturnCallback(function(): bool {
            self::assertFalse($this->locked);
            return $this->locked = $this->acquire;
        });
        $mutex->method('release')->willReturnCallback(function(): bool {
            $this->locked = false;
            return true;
        });
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getAssetById')->willReturn(null);
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getMutex', 'getAssets'])->getMock();
        $app->method('getMutex')->willReturn($mutex);
        $app->method('getAssets')->willReturn($assets);
        Craft::$app = $app;
        $this->plugin = (new \ReflectionClass(BunnySyncPlugin::class))->newInstanceWithoutConstructor();
        $items = $this->getMockBuilder(MediaItems::class)
            ->onlyMethods(['getById', 'getByTypeAndProviderId', 'save'])->getMock();
        $items->method('getById')->willReturnCallback(fn() => $this->exists ? clone $this->record : null);
        $items->method('getByTypeAndProviderId')->willReturnCallback(fn() => $this->exists ? clone $this->record : null);
        $items->method('save')->willReturnCallback(function(MediaItemRecord $record): bool {
            self::assertTrue($this->locked);
            $this->saves++;
            if ($this->saveSucceeds) {
                $this->record = clone $record;
            }
            return $this->saveSucceeds;
        });
        $bunny = $this->createMock(Bunny::class);
        $bunny->method('getLibraryId')->willReturn('133');
        $bunny->method('getAsset')->willReturnCallback(function(string $video, ?string $host): array {
            self::assertFalse($this->locked, 'Slow network requests must not hold the editor item lock.');
            self::assertSame('657bb740-a71b-4529-a012-528021c31a92', $video);
            self::assertSame('vz-old.b-cdn.net', $host, 'Use stored origin, not current settings.');
            $this->reads++;
            if ($this->concurrentSync) {
                $metadata = json_decode($this->record->metadata, true);
                $metadata['bunnySyncVersion'] = 1;
                $metadata['bunnyStatus'] = 4;
                $this->record->metadata = json_encode($metadata);
            }
            return $this->state;
        });
        $this->plugin->items = $items;
        $this->plugin->bunny = $bunny;
        Craft::$app->loadedModules[Plugin::class] = $this->plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
    }

    public function testUnknownItemsNeverReadRemoteOrImport(): void
    {
        $this->exists = false;
        self::assertNull((new BunnySync())->syncVideo('133', '657bb740-a71b-4529-a012-528021c31a92'));
        self::assertSame(0, $this->reads);
        self::assertSame(0, $this->saves);
    }

    public function testLockFailureIsRetryableWithoutReadingRemote(): void
    {
        $this->acquire = false;
        try {
            (new BunnySync())->syncRecord($this->record);
            self::fail('Lock failure must not report success.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->reads);
            self::assertSame(0, $this->saves);
        }
    }

    public function testDuplicateNotificationsRefreshAuthoritativeStateAndPreserveEditorFields(): void
    {
        $sync = new BunnySync();
        $sync->syncRecord($this->record);
        $sync->syncRecord($this->record);
        self::assertSame(2, $this->reads);
        self::assertSame(13, $this->record->duration);
        self::assertSame('editor.jpg', json_decode($this->record->metadata, true)['thumbnail']);
        self::assertFalse($this->locked);
    }

    public function testSaveFailureIsRetryableAndReleasesLock(): void
    {
        $this->saveSucceeds = false;
        try {
            (new BunnySync())->syncRecord($this->record);
            self::fail('Save failure must not report success.');
        } catch (\RuntimeException) {
            self::assertSame(5, $this->record->duration);
            self::assertFalse($this->locked);
        }
    }

    public function testCdnPropagationFailureDoesNotOverwriteStoredState(): void
    {
        $this->state['isPublic'] = null;
        try {
            (new BunnySync())->syncRecord($this->record);
            self::fail('CDN propagation failure must request a retry.');
        } catch (\RuntimeException) {
            self::assertSame(0, $this->saves);
            self::assertFalse($this->locked);
        }
    }

    public function testOverlappingSyncResponseCannotOverwriteNewerState(): void
    {
        $this->concurrentSync = true;
        $this->state['bunnyStatus'] = 2;
        try {
            (new BunnySync())->syncRecord($this->record);
            self::fail('A competing sync must make this response retryable.');
        } catch (\RuntimeException) {
            self::assertSame(4, json_decode($this->record->metadata, true)['bunnyStatus']);
            self::assertSame(0, $this->saves);
            self::assertFalse($this->locked);
        }
    }
}

class BunnySyncPlugin extends Plugin
{
    public MediaItems $items;
    public Bunny $bunny;

    public function getMediaItems(): MediaItems
    {
        return $this->items;
    }

    public function getBunny(): Bunny
    {
        return $this->bunny;
    }
}
