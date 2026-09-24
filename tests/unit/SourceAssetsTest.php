<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\SourceAssets;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\fs\Local;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\TestCase;
use yii\mutex\FileMutex;
use yii\web\ForbiddenHttpException;

class SourceAssetsTest extends TestCase
{
    private mixed $previousApp;
    private SourceAssets $service;
    private Asset $source;
    private Asset $wrapper;
    private User $user;
    private Local $fs;
    private Volume $volume;
    private ManifestWriter $writer;
    private MediaItems $items;
    private bool $exists = true;
    private bool $canView = true;
    private bool $canSave = true;
    private bool $locked = false;
    private bool $acquire = true;
    private ?string $url = 'https://media.example/video.mp4';

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        $this->fs = $this->getMockBuilder(Local::class)->disableOriginalConstructor()->onlyMethods(['fileExists'])->getMock();
        $this->fs->hasUrls = true;
        $this->fs->method('fileExists')->willReturnCallback(fn() => $this->exists);
        $this->volume = $this->getMockBuilder(Volume::class)->onlyMethods(['getFs'])->getMock();
        $this->volume->uid = 'library';
        $this->volume->method('getFs')->willReturn($this->fs);
        $this->source = $this->asset(10, 'video.mp4', 'video');
        $this->wrapper = $this->asset(20, 'video.pmedia', 'polymedia');
        $this->user = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['can'])->getMock();
        $this->user->method('can')->willReturnCallback(fn() => $this->canSave);

        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getAssetById')->willReturnCallback(fn(int $id) => match ($id) {
            10 => $this->source,
            20 => $this->wrapper,
            default => null,
        });
        $mutex = $this->createMock(FileMutex::class);
        $mutex->method('acquire')->willReturnCallback(function(string $name): bool {
            self::assertSame('polymedia:source:uid-10', $name);
            return $this->locked = $this->acquire;
        });
        $mutex->method('release')->willReturnCallback(function(): bool {
            $this->locked = false;
            return true;
        });
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getAssets', 'getMutex'])->getMock();
        $app->method('getAssets')->willReturn($assets);
        $app->method('getMutex')->willReturn($mutex);
        Craft::$app = $app;

        $this->writer = $this->createMock(ManifestWriter::class);
        $this->items = $this->createMock(MediaItems::class);
        $plugin = $this->getMockBuilder(SourceAssetsTestPlugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getManifestWriter', 'getMediaItems'])->getMock();
        $plugin->method('getSettings')->willReturn(new Settings(['sidecarVolumeUid' => 'sidecar']));
        $plugin->method('getManifestWriter')->willReturn($this->writer);
        $plugin->method('getMediaItems')->willReturn($this->items);
        Craft::$app->loadedModules[Plugin::class] = $plugin;

        $this->service = new class() extends SourceAssets {
            public array $sources = [];
            public int $queries = 0;
            protected function findSources(array $uids): array
            {
                $this->queries++;
                return array_values(array_filter($this->sources, fn(Asset $asset) => in_array($asset->uid, $uids, true)));
            }
        };
        $this->service->sources = [$this->source];
        $plugin->sources = $this->service;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
    }

    public function testRenderingDoesNotProbeTheSourceFilesystem(): void
    {
        $this->fs->expects(self::never())->method('fileExists');
        self::assertSame($this->url, $this->service->resolveUrl($this->manifest()));
    }

    public function testImportRejectsAMissingSourceFile(): void
    {
        $this->exists = false;
        $this->writer->expects(self::never())->method('create');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->import(10, 30, $this->user);
    }

    public function testUsesCurrentUrlAfterMoveAndNeverFallsBackAfterDeletion(): void
    {
        $manifest = $this->manifest();
        self::assertSame($this->url, $this->service->resolveUrl($manifest));
        $this->service->reset();
        $this->url = 'https://media.example/renamed/video.mp4';
        self::assertSame($this->url, $this->service->resolveUrl($manifest));
        $this->service->reset();
        $this->service->sources = [];
        self::assertNull($this->service->resolveUrl($manifest));
        self::assertNull($this->service->resolveUrl($manifest));
        self::assertSame(3, $this->service->queries);
    }

    public function testBatchPrimesSourcesAndMissesOnlyOnce(): void
    {
        $missing = $this->manifest('missing');
        $this->service->prime([$this->manifest(), $missing, $this->manifest()]);
        self::assertSame($this->url, $this->service->resolveUrl($this->manifest()));
        self::assertNull($this->service->resolveUrl($missing));
        self::assertSame(1, $this->service->queries);
    }

    public function testGraphQlUrlUsesCurrentSourceAndReturnsNullWhenSourceDisappears(): void
    {
        $record = new class() extends MediaItemRecord {
            public $url = 'https://stale.example/old.mp4';
            public $providerId = 'asset:uid-10';
            public $metadata = '{"sourceAssetUid":"uid-10"}';
        };
        $type = new \boccdotdev\polymedia\gql\types\PolymediaDataType([
            'name' => 'SourceAssetMedia',
            'fields' => ['url' => ['type' => \GraphQL\Type\Definition\Type::string()]],
        ]);
        $schema = new \GraphQL\Type\Schema([
            'query' => new \GraphQL\Type\Definition\ObjectType([
                'name' => 'Query',
                'fields' => [
                    'media' => [
                        'type' => $type,
                        'resolve' => fn() => new \boccdotdev\polymedia\gql\PolymediaData($record),
                    ],
                ],
            ]),
        ]);
        $result = \GraphQL\GraphQL::executeQuery($schema, '{ media { url } }')->toArray();
        self::assertSame(['data' => ['media' => ['url' => $this->url]]], $result);
        $this->service->reset();
        $this->service->sources = [];
        $result = \GraphQL\GraphQL::executeQuery($schema, '{ media { url } }')->toArray();
        self::assertSame(['data' => ['media' => ['url' => null]]], $result);
        $record->providerId = 'ordinary-provider';
        $record->metadata = '{}';
        $result = \GraphQL\GraphQL::executeQuery($schema, '{ media { url } }')->toArray();
        self::assertSame(['data' => ['media' => ['url' => $record->url]]], $result);
    }

    public function testOrdinaryProvidersStayUnchangedAndMalformedReferencesFailClosed(): void
    {
        self::assertSame('https://youtube.com/watch?v=abc', $this->service->resolveUrl(['url' => 'https://youtube.com/watch?v=abc']));
        self::assertNull($this->service->resolveUrl(['url' => 'https://stale.example', 'providerId' => 'asset:uid-10']));
        self::assertNull($this->service->resolveUrl(['url' => 'https://stale.example', 'metadata' => ['sourceAssetUid' => []]]));
        self::assertSame(0, $this->service->queries);
    }

    public function testUnavailablePrivateSidecarAndRecursiveSourcesAreNotPlayable(): void
    {
        foreach (['private', 'trashed', 'sidecar', 'wrapper', 'unsupported', 'unsafe'] as $case) {
            $this->exists = $case !== 'missing';
            $this->fs->hasUrls = $case !== 'private';
            $this->source->dateDeleted = $case === 'trashed' ? new \DateTime() : null;
            $this->volume->uid = $case === 'sidecar' ? 'sidecar' : 'library';
            $this->source->kind = $case === 'wrapper' ? 'polymedia' : 'video';
            $this->source->filename = $case === 'unsupported' ? 'video.avi' : 'video.mp4';
            $this->url = $case === 'unsafe' ? 'file:///video.mp4' : 'https://media.example/video.mp4';
            $this->service->reset();
            self::assertNull($this->service->resolveUrl($this->manifest()), $case);
        }
    }

    public function testImportWritesOnlyAWrapperWithStableIdentityUnderLock(): void
    {
        $this->writableDestination();
        $this->items->method('getByTypeAndProviderId')->willReturnCallback(function(string $type, string $id) {
            self::assertTrue($this->locked);
            self::assertSame('mp4', $type);
            self::assertSame('asset:uid-10', $id);
            return null;
        });
        $this->writer->expects(self::once())->method('create')->willReturnCallback(
            function(int $volumeId, int $folderId, DetectionResult $detection, string $title, ?string $thumbnail, array $metadata): Asset {
                self::assertTrue($this->locked);
                self::assertSame(2, $volumeId);
                self::assertSame(30, $folderId);
                self::assertSame('video', $detection->element);
                self::assertSame('mp4', $detection->type);
                self::assertSame('asset:uid-10', $detection->providerId);
                self::assertSame('asset:uid-10', $detection->url);
                self::assertSame(['sourceAssetUid' => 'uid-10'], $metadata);
                return $this->wrapper;
            },
        );
        self::assertSame($this->wrapper, $this->service->import(10, 30, $this->user));
        self::assertFalse($this->locked);
        self::assertSame('video.mp4', $this->source->getFilename());
    }

    public function testExistingWrapperIsReusedWithoutWriting(): void
    {
        $this->writableDestination();
        $record = new class() extends MediaItemRecord {
            public $assetId = 20;
        };
        $this->items->method('getByTypeAndProviderId')->willReturn($record);
        $this->writer->expects(self::never())->method('create');
        self::assertSame($this->wrapper, $this->service->import(10, 30, $this->user));
        self::assertFalse($this->locked);
    }

    public function testImportRequiresSourceAccess(): void
    {
        $this->canView = false;
        $this->writer->expects(self::never())->method('create');
        $this->expectException(ForbiddenHttpException::class);
        $this->service->import(10, 30, $this->user);
    }

    public function testSupportedVideoExtensionsAreCaseInsensitive(): void
    {
        foreach (['MP4', 'WebM', 'MOV'] as $extension) {
            $this->source->filename = 'video.' . $extension;
            $this->service->reset();
            self::assertSame($this->url, $this->service->resolveUrl($this->manifest()));
        }
    }

    public function testCannotReuseWrapperInVolumeWithoutSavePermission(): void
    {
        $this->writableDestination();
        $this->canSave = false;
        $record = new class() extends MediaItemRecord {
            public $assetId = 20;
        };
        $this->items->method('getByTypeAndProviderId')->willReturn($record);
        $this->writer->expects(self::never())->method('create');
        try {
            $this->service->import(10, 30, $this->user);
            self::fail('Must enforce permissions on the reused wrapper too.');
        } catch (ForbiddenHttpException) {
            self::assertFalse($this->locked);
        }
    }

    public function testUnavailableSourceCannotBeImported(): void
    {
        $this->fs->hasUrls = false;
        $this->writer->expects(self::never())->method('create');
        $this->expectException(\InvalidArgumentException::class);
        $this->service->import(10, 30, $this->user);
    }

    public function testImportRequiresWritableDestination(): void
    {
        $this->writer->method('resolveFolder')->willReturn(null);
        $this->writer->expects(self::never())->method('create');
        $this->expectException(ForbiddenHttpException::class);
        $this->service->import(10, 30, $this->user);
    }

    public function testLockFailureDoesNotCreateWrapper(): void
    {
        $this->writableDestination();
        $this->acquire = false;
        $this->writer->expects(self::never())->method('create');
        $this->expectException(\RuntimeException::class);
        $this->service->import(10, 30, $this->user);
    }

    public function testFailedWriteReleasesLock(): void
    {
        $this->writableDestination();
        $this->writer->method('create')->willThrowException(new \RuntimeException('write failed'));
        try {
            $this->service->import(10, 30, $this->user);
            self::fail('Write must fail.');
        } catch (\RuntimeException) {
            self::assertFalse($this->locked);
        }
    }

    private function writableDestination(): void
    {
        $this->writer->method('resolveFolder')->willReturn(new VolumeFolder(['id' => 30, 'volumeId' => 2]));
    }

    private function manifest(string $uid = 'uid-10'): array
    {
        return ['url' => 'https://stale.example/old.mp4', 'metadata' => ['sourceAssetUid' => $uid]];
    }

    private function asset(int $id, string $filename, string $kind): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)->disableOriginalConstructor()
            ->onlyMethods(['canView', 'getVolume', 'getPath', 'getUrl'])->getMock();
        $asset->id = $id;
        $asset->uid = 'uid-' . $id;
        $asset->title = 'Example video';
        $asset->filename = $filename;
        $asset->kind = $kind;
        $asset->method('canView')->willReturnCallback(fn() => $this->canView);
        $asset->method('getVolume')->willReturn($this->volume);
        $asset->method('getPath')->willReturn($filename);
        $asset->method('getUrl')->willReturnCallback(fn() => $this->url);
        return $asset;
    }
}

class SourceAssetsTestPlugin extends Plugin
{
    public SourceAssets $sources;

    public function getSourceAssets(): SourceAssets
    {
        return $this->sources;
    }
}
