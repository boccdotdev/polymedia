<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\LegacyFolders;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\SidecarStorage;
use craft\elements\Asset;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Pruning deletes only empty legacy folders beside their `.pmedia`, deepest
 * first, and says why every other one stays.
 *
 * @author boccdotdev
 * @since 2.6.0
 */
class LegacyFoldersTest extends TestCase
{
    // Private Properties
    // =========================================================================

    private mixed $_previousApp;

    /**
     * @var array<int, VolumeFolder>
     */
    private array $_folders = [];

    /**
     * @var array<int, Asset[]> assets by folder ID
     */
    private array $_assets = [];

    /**
     * @var array<int, string> unindexed files by folder ID
     */
    private array $_files = [];

    /**
     * @var int[] asset IDs another item uses
     */
    private array $_related = [];

    /**
     * @var int[] folder IDs deleted, in order
     */
    private array $_deleted = [];

    private bool $_deleteFails = false;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        // Slugs read the app language.
        $this->_previousApp = \Craft::$app;
        new \yii\console\Application(['id' => 'legacy-folders-tests', 'basePath' => dirname(__DIR__, 2), 'language' => 'en']);

        $this->_folder(1, null, 'videos/', 'videos', volumeId: 1);
        $this->_folder(2, 1, 'videos/holding-reel-AbC12345/', 'holding-reel-AbC12345');
    }

    protected function tearDown(): void
    {
        \Craft::$app = $this->_previousApp;
    }

    public function testFindsOnlyFoldersNamedAfterAManifestBesideThem(): void
    {
        $this->_folder(3, 1, 'videos/summer-holidays/', 'summer-holidays');
        $this->_folder(4, 1, 'videos/summer-AbC12345/', 'summer-AbC12345');
        $this->_folder(5, 1, 'videos/holding-reel-AbC1234/', 'holding-reel-AbC1234');
        $this->_folder(6, 1, 'videos/holding-reel-AbC12345-old/', 'holding-reel-AbC12345-old');
        $this->_folder(7, 1, 'videos/holding-reel-XyZ98765/', 'holding-reel-XyZ98765', volumeId: 9);
        $this->_folder(8, 1, 'videos/media-Q1w2E3r4/', 'media-Q1w2E3r4');

        $found = $this->_service()->find([$this->_manifest(50, 1, 'Holding Reel'), $this->_manifest(51, 1, '!!!', '!!!')]);

        self::assertSame([2, 8], array_map(fn(VolumeFolder $folder) => (int)$folder->id, $found));
    }

    public function testDryRunPlansWithoutDeleting(): void
    {
        $results = $this->_service()->prune([$this->_manifest(50, 1, 'Holding Reel')], false);

        self::assertSame([['folderId' => 2, 'path' => 'videos/holding-reel-AbC12345/', 'status' => 'planned', 'message' => 'empty']], $results);
        self::assertSame([], $this->_deleted);
    }

    public function testNestedEmptyLegacyFoldersClearDeepestFirst(): void
    {
        // The nested items' manifests have been moved elsewhere or deleted.
        $this->_folder(3, 2, 'videos/holding-reel-AbC12345/b-roll-Zz9Yy8Xx/', 'b-roll-Zz9Yy8Xx');
        $this->_folder(4, 3, 'videos/holding-reel-AbC12345/b-roll-Zz9Yy8Xx/titles-Mm1Nn2Oo/', 'titles-Mm1Nn2Oo');
        $manifests = [$this->_manifest(50, 1, 'Holding Reel')];

        $planned = array_column($this->_service()->prune($manifests, false), 'status', 'folderId');
        self::assertSame([4 => 'planned', 3 => 'planned', 2 => 'planned'], $planned);

        $this->_service()->prune($manifests, true);
        self::assertSame([4, 3, 2], $this->_deleted);
    }

    public function testANestedItemsManifestKeepsTheFolderItSitsIn(): void
    {
        $this->_folder(3, 2, 'videos/holding-reel-AbC12345/b-roll-Zz9Yy8Xx/', 'b-roll-Zz9Yy8Xx');
        $manifests = [$this->_manifest(50, 1, 'Holding Reel'), $this->_manifest(51, 2, 'B-roll')];

        $results = $this->_service()->prune($manifests, true);

        self::assertSame([3], $this->_deleted);
        self::assertSame(['deleted', 'kept'], array_column($results, 'status'));
        self::assertSame("holds another item's manifest, b-roll.pmedia", $results[1]['message']);
    }

    public function testEditorFoldersInsideALegacyFolderAreNotPruned(): void
    {
        $this->_folder(3, 2, 'videos/holding-reel-AbC12345/drafts/', 'drafts');

        $results = $this->_service()->prune([$this->_manifest(50, 1, 'Holding Reel')], true);

        self::assertSame([['folderId' => 2, 'path' => 'videos/holding-reel-AbC12345/', 'status' => 'kept', 'message' => 'holds the folder videos/holding-reel-AbC12345/drafts/']], $results);
    }

    public static function reasons(): array
    {
        return [
            'another item\'s manifest' => [fn(self $t) => $t->_assets[2] = [$t->_asset(60, 'b-roll.pmedia', kind: 'polymedia')], "holds another item's manifest, b-roll.pmedia"],
            'a trashed asset' => [fn(self $t) => $t->_assets[2] = [$t->_asset(61, 'old.jpg', trashed: true)], 'holds a trashed asset, old.jpg'],
            'a kept sidecar' => [function(self $t) {
                $t->_assets[2] = [$t->_asset(62, 'poster.jpg')];
                $t->_related = [62];
            }, 'holds a kept sidecar, poster.jpg'],
            'some other asset' => [fn(self $t) => $t->_assets[2] = [$t->_asset(63, 'notes.pdf')], 'holds notes.pdf'],
            'an asset in a subfolder' => [function(self $t) {
                $t->_folder(3, 2, 'videos/holding-reel-AbC12345/drafts/', 'drafts');
                $t->_assets[3] = [$t->_asset(64, 'cut.mp4')];
            }, 'holds the folder videos/holding-reel-AbC12345/drafts/'],
            'unindexed files' => [fn(self $t) => $t->_files[2] = 'videos/holding-reel-AbC12345/stray.vtt', 'holds unindexed files on disk, such as videos/holding-reel-AbC12345/stray.vtt'],
        ];
    }

    #[DataProvider('reasons')]
    public function testKeepsAFolderThatStillHoldsSomething(callable $arrange, string $reason): void
    {
        $arrange($this);

        $results = $this->_service()->prune([$this->_manifest(50, 1, 'Holding Reel')], true);

        self::assertSame('kept', $results[0]['status']);
        self::assertSame($reason, $results[0]['message']);
        self::assertSame([], $this->_deleted);
    }

    public function testAFailedDeleteKeepsItsParent(): void
    {
        $this->_folder(3, 2, 'videos/holding-reel-AbC12345/b-roll-Zz9Yy8Xx/', 'b-roll-Zz9Yy8Xx');
        $manifests = [$this->_manifest(50, 1, 'Holding Reel')];
        $this->_deleteFails = true;

        $results = $this->_service()->prune($manifests, true);

        self::assertSame(['failed', 'kept'], array_column($results, 'status'));
        self::assertSame('holds the folder videos/holding-reel-AbC12345/b-roll-Zz9Yy8Xx/', $results[1]['message']);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the service with Craft's folder and asset lookups faked.
     */
    private function _service(): LegacyFolders
    {
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('findFolders')->willReturnCallback(fn(array $criteria) => array_values(array_filter(
            $this->_folders,
            fn(VolumeFolder $folder) => (int)$folder->parentId === (int)$criteria['parentId'],
        )));
        $assets->method('getAllDescendantFolders')->willReturnCallback(fn(VolumeFolder $parent) => array_values(array_filter(
            $this->_folders,
            fn(VolumeFolder $folder) => $folder->id !== $parent->id && str_starts_with((string)$folder->path, (string)$parent->path),
        )));
        $assets->method('deleteFoldersByIds')->willReturnCallback(function(int $id): void {
            if ($this->_deleteFails) {
                throw new \RuntimeException('Filesystem error.');
            }

            $this->_deleted[] = $id;
            $path = (string)$this->_folders[$id]->path;
            $this->_folders = array_filter($this->_folders, fn(VolumeFolder $folder) => !str_starts_with((string)$folder->path, $path));
        });

        $storage = $this->createMock(SidecarStorage::class);
        $storage->method('getVolume')->willReturn(new Volume(['id' => 9]));
        $related = $this->createMock(RelatedAssets::class);
        $related->method('isAssetRelated')->willReturnCallback(fn(int $id) => in_array($id, $this->_related, true));
        $items = $this->createMock(MediaItems::class);
        $items->method('getByAssetId')->willReturn(null);

        $service = $this->getMockBuilder(LegacyFolders::class)
            ->setConstructorArgs([$assets, $storage, $related, $items])
            ->onlyMethods(['assetsIn', 'fileOnDisk'])
            ->getMock();
        $service->method('assetsIn')->willReturnCallback(fn(array $ids) => array_merge(...array_map(fn(int $id) => $this->_assets[$id] ?? [], $ids)));
        $service->method('fileOnDisk')->willReturnCallback(fn(VolumeFolder $folder) => $this->_files[$folder->id] ?? null);

        return $service;
    }

    /**
     * Adds a folder to the fake volume.
     */
    private function _folder(int $id, ?int $parentId, string $path, string $name, int $volumeId = 1): void
    {
        $this->_folders[$id] = new VolumeFolder(['id' => $id, 'parentId' => $parentId, 'path' => $path, 'name' => $name, 'volumeId' => $volumeId]);
    }

    /**
     * Returns a `.pmedia` asset in a folder, and records it as that folder's content.
     */
    private function _manifest(int $id, int $folderId, string $title, ?string $filename = null): Asset
    {
        $asset = $this->_asset($id, ($filename ?? strtolower(str_replace(' ', '-', $title))) . '.pmedia', kind: 'polymedia');
        $asset->folderId = $folderId;
        $asset->title = $title;
        $this->_assets[$folderId][] = $asset;

        return $asset;
    }

    /**
     * Returns an asset with a filename.
     */
    private function _asset(int $id, string $filename, string $kind = 'image', bool $trashed = false): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)->disableOriginalConstructor()->onlyMethods(['getFilename'])->getMock();
        $asset->method('getFilename')->willReturn($filename);
        $asset->id = $id;
        $asset->kind = $kind;
        $asset->trashed = $trashed;

        return $asset;
    }
}
