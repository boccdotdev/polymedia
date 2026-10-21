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
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\SidecarStorage;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\TestCase;

/**
 * Replacing or removing a poster deletes the old one only when it's a file in
 * the item's own sidecar folder that nothing else uses.
 *
 * @author boccdotdev
 * @since 2.6.0
 */
class PosterReplacementTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const ITEM_FOLDER = 3;
    private const LIBRARY_FOLDER = 4;

    // Private Properties
    // =========================================================================

    private mixed $_previousApp;
    private RelatedAssets $_related;
    private MediaItemRecord $_record;
    private ?Asset $_poster = null;
    private bool $_shared = false;

    /**
     * @var int[] deleted asset IDs
     */
    private array $_deleted = [];

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        $this->_record = new class() extends MediaItemRecord {
            public $id = 7;
            public $assetUid = 'item-uid';
        };

        $folders = [
            self::ITEM_FOLDER => new VolumeFolder(['id' => self::ITEM_FOLDER, 'volumeId' => 2, 'parentId' => 1, 'path' => 'item-uid/']),
            self::LIBRARY_FOLDER => new VolumeFolder(['id' => self::LIBRARY_FOLDER, 'volumeId' => 1, 'parentId' => 1, 'path' => 'images/']),
        ];
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getFolderById')->willReturnCallback(fn(int $id) => $folders[$id] ?? null);
        $assets->method('getAssetById')->willReturnCallback(fn(int $id) => $this->_asset($id, self::LIBRARY_FOLDER, $id === 99 ? 'pdf' : 'image'));
        $elements = $this->createMock(\craft\services\Elements::class);
        $elements->method('deleteElement')->willReturnCallback(function(Asset $asset, bool $hard): bool {
            self::assertTrue($hard);
            $this->_deleted[] = (int)$asset->id;

            return true;
        });
        $identity = $this->createMock(User::class);
        $identity->method('can')->willReturn(true);
        $user = $this->createMock(\craft\web\User::class);
        $user->method('getIdentity')->willReturn($identity);
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getAssets', 'getElements', 'getUser'])->getMock();
        $app->method('getAssets')->willReturn($assets);
        $app->method('getElements')->willReturn($elements);
        $app->method('getUser')->willReturn($user);
        Craft::$app = $app;

        $items = $this->getMockBuilder(MediaItems::class)->onlyMethods(['withItemLock', 'getById'])->getMock();
        $items->method('withItemLock')->willReturnCallback(fn(MediaItemRecord $record, callable $callback) => $callback());
        $items->method('getById')->willReturnCallback(fn() => $this->_record);
        $storage = $this->getMockBuilder(SidecarStorage::class)->onlyMethods(['getVolume', 'isSharedAsset'])->getMock();
        $storage->method('getVolume')->willReturn(new Volume(['id' => 2]));
        $storage->method('isSharedAsset')->willReturnCallback(fn() => $this->_shared);
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getMediaItems', 'getSidecarStorage'])->getMock();
        $plugin->method('getMediaItems')->willReturn($items);
        $plugin->method('getSidecarStorage')->willReturn($storage);
        Craft::$app->loadedModules[Plugin::class] = $plugin;

        $this->_related = $this->getMockBuilder(RelatedAssets::class)->onlyMethods(['getPoster', 'attach', 'clearPoster'])->getMock();
        $this->_related->method('getPoster')->willReturnCallback(fn() => $this->_poster);
        $this->_related->method('attach')->willReturnCallback(function(int $itemId, int $assetId): \boccdotdev\polymedia\records\RelatedAssetRecord {
            $this->_poster = $this->_asset($assetId, self::LIBRARY_FOLDER);

            return new \boccdotdev\polymedia\records\RelatedAssetRecord();
        });
        $this->_related->method('clearPoster')->willReturnCallback(function(): int {
            $this->_poster = null;

            return 1;
        });
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public function testReplacingAManagedPosterDeletesIt(): void
    {
        $this->_poster = $this->_asset(50, self::ITEM_FOLDER);

        $this->_related->savePoster($this->_record, ['60']);

        self::assertSame(60, (int)$this->_poster?->id);
        self::assertSame([50], $this->_deleted);
    }

    public function testRemovingAManagedPosterDeletesIt(): void
    {
        $this->_poster = $this->_asset(50, self::ITEM_FOLDER);

        $this->_related->savePoster($this->_record, '');

        self::assertNull($this->_poster);
        self::assertSame([50], $this->_deleted);
    }

    public function testPostersPolymediaDoesNotManageStay(): void
    {
        // A library image the editor chose earlier.
        $this->_poster = $this->_asset(50, self::LIBRARY_FOLDER);
        $this->_related->savePoster($this->_record, ['60']);

        // A sidecar file another item or a Craft field still uses.
        $this->_poster = $this->_asset(51, self::ITEM_FOLDER);
        $this->_shared = true;
        $this->_related->savePoster($this->_record, ['60']);

        self::assertSame([], $this->_deleted);
    }

    public function testResavingOrRejectingAPosterKeepsTheCurrentOne(): void
    {
        $this->_poster = $this->_asset(50, self::ITEM_FOLDER);
        $this->_related->savePoster($this->_record, ['50']);

        // Not an image, so nothing changes.
        $this->_related->savePoster($this->_record, ['99']);

        self::assertSame(50, (int)$this->_poster?->id);
        self::assertSame([], $this->_deleted);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns an asset in a folder.
     */
    private function _asset(int $id, int $folderId, string $kind = 'image'): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)->disableOriginalConstructor()->onlyMethods(['getVolume'])->getMock();
        $asset->method('getVolume')->willReturn(new Volume(['id' => 1, 'uid' => 'library']));
        $asset->id = $id;
        $asset->folderId = $folderId;
        $asset->kind = $kind;

        return $asset;
    }
}
