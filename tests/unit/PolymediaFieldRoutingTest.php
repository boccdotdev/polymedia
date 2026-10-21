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
        $volumes = $this->createMock(\craft\services\Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(
            fn(string $uid) => in_array($uid, ['videos', 'images'], true) ? new Volume(['uid' => $uid]) : null,
        );
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getVolumes'])->getMock();
        $app->method('getVolumes')->willReturn($volumes);
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
        $field->defaultUploadLocationSource = 'volume:videos';
        // Resolving the dynamic subpath creates folders, so rendering must not.
        $field->expects(self::never())->method('resolveDynamicPathToFolderId');

        self::assertSame($expected, $field->getRoutesUploads(null));
    }

    public static function locations(): array
    {
        return [
            'restricted to a routed volume' => [true, 'volume:videos', 'volume:images', true],
            'restricted to another volume' => [true, 'volume:images', 'volume:videos', false],
            'default upload location' => [false, 'volume:images', 'volume:videos', true],
            'unknown volume' => [false, null, 'volume:missing', false],
            'no upload location' => [false, null, null, false],
        ];
    }

    #[DataProvider('locations')]
    public function testUploadVolumeComesFromTheLocationSource(bool $restrict, ?string $restricted, ?string $default, bool $expected): void
    {
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getVideoEnabled'])->getMock();
        $plugin->method('getSettings')->willReturn(new Settings([
            'autoRouteVideoUploads' => true,
            'videoUploadVolumeUids' => ['videos'],
        ]));
        $plugin->method('getVideoEnabled')->willReturn(true);
        Craft::$app->loadedModules[Plugin::class] = $plugin;

        $field = $this->getMockBuilder(PolymediaField::class)->disableOriginalConstructor()
            ->onlyMethods(['resolveDynamicPathToFolderId'])->getMock();
        $field->restrictLocation = $restrict;
        $field->restrictedLocationSource = $restricted;
        $field->defaultUploadLocationSource = $default;
        $field->expects(self::never())->method('resolveDynamicPathToFolderId');

        self::assertSame($expected, $field->getRoutesUploads(null));
    }
}
