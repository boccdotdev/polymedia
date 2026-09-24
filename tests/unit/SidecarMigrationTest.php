<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\records\RelatedAssetRecord;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\SidecarMigration;
use boccdotdev\polymedia\services\SidecarStorage;
use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets;
use PHPUnit\Framework\TestCase;

/**
 * Tests migration decisions and execution without touching a site filesystem.
 */
class SidecarMigrationTest extends TestCase
{
    private mixed $previousApp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Craft::$app;
        new \yii\console\Application([
            'id' => 'sidecar-migration-tests',
            'basePath' => dirname(__DIR__, 2),
            'language' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
        parent::tearDown();
    }

    public function testLegacyPosterInAnotherSameTitleFolderStaysInPlace(): void
    {
        $manifest = $this->asset(1, 10, 'hero.pmedia');
        $poster = $this->asset(2, 20, 'poster.jpg');
        $folder = new VolumeFolder([
            'id' => 20,
            'volumeId' => 1,
            'parentId' => 100,
            'name' => 'hero-bbbbbbbb',
            'path' => 'hero-bbbbbbbb/',
        ]);

        $assets = $this->createMock(Assets::class);
        $assets->method('getAssetById')->with(2)->willReturn($poster);
        $assets->method('getFolderById')->willReturnMap([[20, $folder], [10, null]]);
        $assets->expects($this->never())->method('moveAsset');

        $storage = $this->getMockBuilder(SidecarStorage::class)
            ->onlyMethods(['getVolume', 'isSharedAsset', 'moveIntoItem'])
            ->getMock();
        $storage->method('getVolume')->willReturn(new Volume(['id' => 3]));
        $storage->expects($this->never())->method('moveIntoItem');

        $related = $this->createMock(RelatedAssets::class);
        $related->method('getForItem')->with(1)->willReturn([
            new MigrationRelation(['assetId' => 2]),
        ]);

        $migration = new SidecarMigration($assets, $storage, $related);
        $results = $migration->migrateItem($manifest, new MigrationItem(), false);

        $this->assertSame('kept', $results[0]['status']);
        $this->assertSame(2, $results[0]['assetId']);
        $this->assertStringContainsString('ownership', $results[0]['message']);
        $this->assertSame(20, $poster->folderId);
    }

    public function testAmbiguousLegacyPosterBesideManifestIsNotAdopted(): void
    {
        [$migration, $manifest, $record, , $storage] = $this->managedFixture('hero-aaaaaaaa/');
        $manifest->folderId = 20;
        $storage->expects($this->never())->method('moveIntoItem');

        $results = $migration->migrateItem($manifest, $record, false);

        $this->assertSame('kept', $results[0]['status']);
        $this->assertStringContainsString('ownership', $results[0]['message']);
    }

    public function testSharedManagedSidecarIsLeftInItsOriginalVolume(): void
    {
        [$migration, $manifest, $record, , $storage] = $this->managedFixture(shared: true);
        $storage->expects($this->never())->method('moveIntoItem');

        $results = $migration->migrateItem($manifest, $record, false);

        $this->assertSame('kept', $results[0]['status']);
        $this->assertStringContainsString('another item or Craft field', $results[0]['message']);
    }

    public function testDryRunPlansManagedMoveWithoutWriting(): void
    {
        [$migration, $manifest, $record, $assets, $storage] = $this->managedFixture();
        $storage->expects($this->never())->method('moveIntoItem');
        $assets->expects($this->never())->method('moveAsset');

        $results = $migration->migrateItem($manifest, $record, true);

        $this->assertSame(['planned'], array_column($results, 'status'));
        $this->assertStringContainsString('configured sidecar volume', $results[0]['message']);
    }

    public function testFailedMoveIsReportedAndSuccessfulRetryIsNotRepeated(): void
    {
        [$migration, $manifest, $record, , $storage, $poster, $folder] = $this->managedFixture();
        $attempt = 0;
        $storage->method('moveIntoItem')->willReturnCallback(
            static function() use (&$attempt, $poster, $folder): bool {
                if (++$attempt === 1) {
                    return false;
                }

                $poster->volumeId = $folder->volumeId = 3;

                return true;
            },
        );

        $failed = $migration->migrateItem($manifest, $record, false);
        $this->assertSame('failed', $failed[0]['status']);
        $this->assertStringContainsString('Craft declined', $failed[0]['message']);
        $this->assertSame('kept', $failed[1]['status']);

        $retried = $migration->migrateItem($manifest, $record, false);
        $this->assertSame(['moved'], array_column($retried, 'status'));
        $this->assertSame([], $migration->migrateItem($manifest, $record, false));
        $this->assertSame(2, $attempt);
    }

    public function testStorageExceptionIsReportedWithAssetAndDestination(): void
    {
        [$migration, $manifest, $record, , $storage] = $this->managedFixture();
        $storage->method('moveIntoItem')->willThrowException(new \RuntimeException('Storage unavailable'));

        $results = $migration->migrateItem($manifest, $record, false);

        $this->assertSame(2, $results[0]['assetId']);
        $this->assertSame('failed', $results[0]['status']);
        $this->assertStringContainsString('configured sidecar volume', $results[0]['message']);
        $this->assertStringContainsString('Storage unavailable', $results[0]['message']);
    }

    public function testLaterLookupFailureDoesNotLoseEarlierSuccessfulMove(): void
    {
        [$migration, $manifest, $record, , $storage] = $this->managedFixture(laterFailure: true);
        $storage->method('moveIntoItem')->willReturn(true);

        $results = $migration->migrateItem($manifest, $record, false);

        $this->assertSame(['moved', 'failed', 'kept'], array_column($results, 'status'));
        $this->assertSame([2, 3, 1], array_column($results, 'assetId'));
        $this->assertStringContainsString('Lookup unavailable', $results[1]['message']);
    }

    public function testFailedManifestMoveIsReportedInsteadOfCountedAsMoved(): void
    {
        $manifest = $this->asset(1, 10, 'hero.pmedia');
        $folder = new VolumeFolder([
            'id' => 10,
            'volumeId' => 1,
            'parentId' => 100,
            'name' => 'hero-aaaaaaaa',
            'path' => 'hero-aaaaaaaa/',
        ]);
        $parent = new VolumeFolder(['id' => 100, 'volumeId' => 1, 'path' => '']);
        $assets = $this->createMock(Assets::class);
        $assets->method('getFolderById')->willReturnMap([[10, $folder], [100, $parent]]);
        $assets->method('getNameReplacementInFolder')->willReturn('hero.pmedia');
        $assets->method('moveAsset')->willReturn(false);
        $related = $this->createMock(RelatedAssets::class);
        $related->method('getForItem')->willReturn([]);
        $migration = new SidecarMigration($assets, $this->createMock(SidecarStorage::class), $related);

        $results = $migration->migrateItem($manifest, new MigrationItem(), false);

        $this->assertSame(['failed'], array_column($results, 'status'));
        $this->assertSame('manifest', $results[0]['kind']);
        $this->assertSame(1, $results[0]['assetId']);
    }

    private function managedFixture(?string $path = null, bool $shared = false, bool $laterFailure = false): array
    {
        $record = new MigrationItem();
        $manifest = $this->asset(1, 10, 'hero.pmedia');
        $poster = $this->asset(2, 20, 'poster.jpg');
        $folder = new VolumeFolder([
            'id' => 20,
            'volumeId' => 1,
            'parentId' => 100,
            'name' => trim($path ?? $record->assetUid, '/'),
            'path' => $path ?? $record->assetUid . '/',
        ]);

        $assets = $this->createMock(Assets::class);
        $assets->method('getAssetById')->willReturnCallback(static function(int $id) use ($poster): Asset {
            if ($id !== 2) {
                throw new \RuntimeException('Lookup unavailable');
            }

            return $poster;
        });
        $assets->method('getFolderById')->willReturnMap([[20, $folder], [10, null], [100, null]]);

        $storage = $this->getMockBuilder(SidecarStorage::class)
            ->onlyMethods(['getVolume', 'isSharedAsset', 'moveIntoItem'])
            ->getMock();
        $storage->method('getVolume')->willReturn(new Volume(['id' => 3]));
        $storage->method('isSharedAsset')->with(2, 1)->willReturn($shared);

        $related = $this->createMock(RelatedAssets::class);
        $related->method('getForItem')->with(1)->willReturn([
            new MigrationRelation(['assetId' => 2]),
            new MigrationRelation(['assetId' => $laterFailure ? 3 : 2]),
        ]);

        return [new SidecarMigration($assets, $storage, $related), $manifest, $record, $assets, $storage, $poster, $folder];
    }

    private function asset(int $id, int $folderId, string $filename): Asset
    {
        // Asset initialization loads a site's custom fields; these fixtures need
        // only native asset attributes, with no Craft installation or database.
        $asset = (new \ReflectionClass(Asset::class))->newInstanceWithoutConstructor();
        $asset->id = $id;
        $asset->folderId = $folderId;
        $asset->title = 'Hero';
        $asset->filename = $filename;

        return $asset;
    }
}

class MigrationItem extends MediaItemRecord
{
    public int $id = 1;
    public int $assetId = 1;
    public string $assetUid = 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa';
    public string $title = 'Hero';
}

class MigrationRelation extends RelatedAssetRecord
{
    public int $assetId = 0;
}
