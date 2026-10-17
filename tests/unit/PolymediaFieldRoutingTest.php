<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Polymedia fields only offer native upload where MP4s are routed.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class PolymediaFieldRoutingTest extends TestCase
{
    // Private Properties
    // =========================================================================

    private mixed $_previousApp;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        $folder = $this->getMockBuilder(VolumeFolder::class)->onlyMethods(['getVolume'])->getMock();
        $folder->method('getVolume')->willReturn(new Volume(['uid' => 'videos']));
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getFolderById')->willReturn($folder);
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getAssets'])->getMock();
        $app->method('getAssets')->willReturn($assets);
        Craft::$app = $app;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public static function cases(): array
    {
        return [
            'routed volume and provider' => [true, ['videos'], true, [], true],
            'routing off' => [false, ['videos'], true, [], false],
            'other volume' => [true, ['other'], true, [], false],
            'provider unavailable' => [true, ['videos'], false, [], false],
            'provider not allowed' => [true, ['videos'], true, ['youtube'], false],
            'provider allowed' => [true, ['videos'], true, ['mux'], true],
        ];
    }

    #[DataProvider('cases')]
    public function testNativeUploadFollowsRouting(bool $routing, array $volumes, bool $enabled, array $providers, bool $expected): void
    {
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getVideoEnabled'])->getMock();
        $plugin->method('getSettings')->willReturn(new Settings([
            'autoRouteVideoUploads' => $routing,
            'videoUploadVolumeUids' => $volumes,
        ]));
        $plugin->method('getVideoEnabled')->willReturn($enabled);
        Craft::$app->loadedModules[Plugin::class] = $plugin;

        $field = $this->getMockBuilder(PolymediaField::class)->disableOriginalConstructor()
            ->onlyMethods(['resolveDynamicPathToFolderId'])->getMock();
        $field->allowedProviders = $providers;
        $field->method('resolveDynamicPathToFolderId')->willReturn(10);

        self::assertSame($expected, $field->getRoutesUploads(null));
    }
}
