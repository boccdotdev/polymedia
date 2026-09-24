<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\SidecarStorage;
use Craft;
use craft\models\VolumeFolder;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for sidecar storage rules that do not require Craft bootstrap.
 *
 * @author boccdotdev
 * @since 2.2.0
 */
class SidecarStorageTest extends TestCase
{
    public function testItemFolderUsesOnlyStableAssetUid(): void
    {
        $storage = new SidecarStorage();

        $this->assertSame(
            '3c50f9e8-15c4-4056-8934-ae776265a999/',
            $storage->itemFolderPath('/3c50f9e8-15c4-4056-8934-ae776265a999/'),
        );
    }

    public function testDefaultLocalVolumeIsSeparateFromNormalUploads(): void
    {
        $this->assertSame('@webroot/polymedia-sidecars', SidecarStorage::DEFAULT_PATH);
        $this->assertSame('@web/polymedia-sidecars', SidecarStorage::DEFAULT_URL);
    }

    public function testOnlyAnExactNonRootUidPathIdentifiesAnItemFolder(): void
    {
        $storage = new SidecarStorage();
        $uid = '3c50f9e8-15c4-4056-8934-ae776265a999';
        $folder = new VolumeFolder(['parentId' => 1, 'path' => $uid . '/']);

        $this->assertTrue($storage->isItemFolder($folder, $uid));
        $this->assertFalse($storage->isItemFolder($folder, 'another-item'));
        $this->assertFalse($storage->isItemFolder($folder, ''));
        $folder->path = 'hero-aaaaaaaa/';
        $this->assertFalse($storage->isItemFolder($folder, $uid));
        $folder->path = $uid . '/';
        $folder->parentId = null;
        $this->assertFalse($storage->isItemFolder($folder, $uid));
    }

    public function testSharingIncludesOtherItemsAndNativeCraftRelations(): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            $this->markTestSkipped('SQLite is required for the isolated relation-query test.');
        }

        require_once dirname((new \ReflectionClass(\yii\BaseYii::class))->getFileName()) . '/Yii.php';
        require_once dirname((new \ReflectionClass(VolumeFolder::class))->getFileName(), 2) . '/Craft.php';
        $previousApp = Craft::$app;
        $app = new \yii\console\Application([
            'id' => 'sidecar-relation-tests',
            'basePath' => dirname(__DIR__, 2),
            'components' => [
                'db' => ['class' => \yii\db\Connection::class, 'dsn' => 'sqlite::memory:'],
            ],
        ]);

        try {
            $db = $app->getDb();
            $db->createCommand()->createTable('polymedia_related_assets', ['assetId' => 'integer', 'itemId' => 'integer'])->execute();
            $db->createCommand()->createTable('relations', ['targetId' => 'integer', 'sourceId' => 'integer'])->execute();
            $db->createCommand()->insert('polymedia_related_assets', ['assetId' => 7, 'itemId' => 1])->execute();
            $storage = new SidecarStorage();

            $this->assertFalse($storage->isSharedAsset(7, 1));
            $db->createCommand()->insert('polymedia_related_assets', ['assetId' => 7, 'itemId' => 2])->execute();
            $this->assertTrue($storage->isSharedAsset(7, 1));
            $db->createCommand()->delete('polymedia_related_assets', ['itemId' => 2])->execute();
            $db->createCommand()->insert('relations', ['targetId' => 7, 'sourceId' => 10])->execute();
            $this->assertTrue($storage->isSharedAsset(7, 1));
            $this->assertFalse($storage->isSharedAsset(8, 1));
        } finally {
            $app->getDb()->close();
            Craft::$app = $previousApp;
        }
    }
}
