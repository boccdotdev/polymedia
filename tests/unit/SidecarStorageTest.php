<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\SidecarStorage;
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
}
