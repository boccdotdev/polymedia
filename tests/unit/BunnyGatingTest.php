<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\console\controllers\BunnyController as BunnyConsoleController;
use boccdotdev\polymedia\controllers\BunnyController;
use boccdotdev\polymedia\controllers\MuxController;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\BunnySync;
use boccdotdev\polymedia\services\EditorContent;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\Mux;
use Craft;
use PHPUnit\Framework\TestCase;
use yii\web\Response;

/**
 * Bunny credential resolution and the provider management gates.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyGatingTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var mixed
     */
    private mixed $_previousApp;

    /**
     * @var BunnyGatingPlugin
     */
    private BunnyGatingPlugin $_plugin;

    /**
     * @var Settings
     */
    private Settings $_settings;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        Craft::$app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        $i18n = $this->createMock(\craft\i18n\I18N::class);
        $i18n->method('translate')->willReturnArgument(1);
        Craft::$app->method('getI18n')->willReturn($i18n);
        $this->_settings = new Settings([
            'bunnyLibraryId' => '133',
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
        ]);
        $this->_plugin = (new \ReflectionClass(BunnyGatingPlugin::class))->newInstanceWithoutConstructor();
        $this->_plugin->testSettings = $this->_settings;
        Craft::$app->loadedModules[Plugin::class] = $this->_plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
        unset($_SERVER['POLYMEDIA_TEST_BUNNY_KEY'], $_SERVER['POLYMEDIA_TEST_BUNNY_HOST']);
    }

    public function testSecretsMustBeResolvedEnvironmentReferences(): void
    {
        $bunny = new Bunny();

        $this->_settings->bunnyApiKey = 'literal-secret';
        self::assertFalse($bunny->isConfigured());

        $this->_settings->bunnyApiKey = '$POLYMEDIA_TEST_BUNNY_KEY';
        self::assertFalse($bunny->isConfigured(), 'An unset variable is not a key.');

        $_SERVER['POLYMEDIA_TEST_BUNNY_KEY'] = 'test-key';
        self::assertTrue($bunny->isConfigured());

        $this->_settings->bunnyCdnHostname = 'http://localhost';
        self::assertFalse($bunny->isConfigured());

        $this->_settings->bunnyCdnHostname = '$POLYMEDIA_TEST_BUNNY_HOST';
        $_SERVER['POLYMEDIA_TEST_BUNNY_HOST'] = 'VZ-Env.b-cdn.net';
        self::assertTrue($bunny->isConfigured());
        self::assertSame('vz-env.b-cdn.net', $bunny->getCdnHostname());

        $this->_settings->bunnyLibraryId = '0133';
        self::assertFalse($bunny->isConfigured());

        $this->_settings->bunnyWebhookKey = 'literal-secret';
        self::assertSame('', $bunny->getWebhookKey());

        $this->_settings->bunnyWebhookKey = '$POLYMEDIA_TEST_BUNNY_KEY';
        self::assertSame('test-key', $bunny->getWebhookKey());
    }

    public function testOnlyTheSelectedConfiguredProviderAcceptsNewMedia(): void
    {
        $this->_plugin->mux = true;
        $this->_plugin->bunny = true;

        $this->_settings->videoProvider = Settings::PROVIDER_MUX;
        self::assertTrue($this->_plugin->isVideoProviderEnabled('mux'));
        self::assertFalse($this->_plugin->isVideoProviderEnabled('bunny'), 'Configured but not selected.');
        self::assertTrue($this->_plugin->getVideoEnabled());

        $this->_settings->videoProvider = Settings::PROVIDER_BUNNY;
        self::assertFalse($this->_plugin->isVideoProviderEnabled('mux'));
        self::assertTrue($this->_plugin->isVideoProviderEnabled('bunny'));

        $this->_plugin->bunny = false;
        self::assertFalse($this->_plugin->getVideoEnabled(), 'Selected but not configured.');
        self::assertFalse($this->_plugin->isVideoProviderEnabled('unknown'));
    }

    public function testBunnyActionsRequireCpJsonAndProBeforeRemoteCalls(): void
    {
        foreach (['actionLibrary' => false, 'actionImport' => true] as $action => $write) {
            $this->_bunnyController($write, 'pro_required')->$action();
        }
    }

    public function testBunnyConfigurationAndSelectionAreSeparateGates(): void
    {
        $this->_plugin->pro = true;
        $this->_bunnyController(false, 'not_configured')->actionLibrary();

        $this->_plugin->bunny = true;
        $this->_bunnyController(true, 'inactive_provider')->actionImport();
    }

    public function testMuxActionsRefuseWhenBunnyIsSelected(): void
    {
        $this->_plugin->pro = true;
        $this->_plugin->mux = true;
        $this->_settings->videoProvider = Settings::PROVIDER_BUNNY;
        $mux = $this->createMock(Mux::class);
        $mux->method('isConfigured')->willReturn(true);
        $mux->expects(self::never())->method('listAssets');
        $this->_plugin->muxService = $mux;

        $controller = $this->getMockBuilder(MuxController::class)->disableOriginalConstructor()
            ->onlyMethods(['requireCpRequest', 'requireAcceptsJson', 'asFailure'])->getMock();
        $controller->expects(self::once())->method('asFailure')
            ->with(self::isType('string'), ['code' => 'inactive_provider'])
            ->willReturn(new Response(['charset' => 'UTF-8']));
        $controller->actionLibrary();
    }

    public function testHostedImportsAreManagedVideos(): void
    {
        $editor = new EditorContent();

        foreach ([['muxAssetId' => 'a'], ['bunnyVideoId' => 'b']] as $metadata) {
            $record = $this->createMock(MediaItemRecord::class);
            $record->method('__get')->willReturnCallback(fn(string $name) => $name === 'metadata' ? json_encode($metadata) : null);
            self::assertTrue($editor->isManagedVideo($record));
        }

        $pasted = $this->createMock(MediaItemRecord::class);
        $pasted->method('__get')->willReturn(null);
        self::assertFalse($editor->isManagedVideo($pasted), 'A pasted Mux URL stays editable.');
    }

    public function testConsoleSyncRequiresProAndConfiguration(): void
    {
        $controller = $this->getMockBuilder(BunnyConsoleController::class)->disableOriginalConstructor()
            ->onlyMethods(['stdout', 'stderr'])->getMock();
        $controller->expects(self::once())->method('stderr')->willReturn(1);

        self::assertSame(\yii\console\ExitCode::CONFIG, $controller->actionSync());
    }

    public function testConsoleSyncFiltersByVideoAndCountsFailures(): void
    {
        $this->_plugin->bunny = true;
        $records = [];

        foreach (['657bb740-a71b-4529-a012-528021c31a92', '757bb740-a71b-4529-a012-528021c31a92'] as $i => $videoId) {
            $record = $this->createMock(MediaItemRecord::class);
            $record->method('__get')->willReturnCallback(fn(string $name) => match ($name) {
                'metadata' => json_encode(['bunnyVideoId' => $videoId]),
                'title' => "Video {$i}",
                default => $i,
            });
            $records[] = $record;
        }

        $items = $this->getMockBuilder(MediaItems::class)->onlyMethods(['getByType'])->getMock();
        $items->method('getByType')->with('bunny')->willReturn($records);
        $sync = $this->createMock(BunnySync::class);
        $sync->expects(self::once())->method('syncRecord')->with($records[1])
            ->willThrowException(new \RuntimeException('Bunny is down'));
        $this->_plugin->items = $items;
        $this->_plugin->sync = $sync;

        $controller = $this->getMockBuilder(BunnyConsoleController::class)->disableOriginalConstructor()
            ->onlyMethods(['stdout', 'stderr'])->getMock();
        $controller->videoId = '757bb740-a71b-4529-a012-528021c31a92';
        $controller->expects(self::once())->method('stderr')->with(self::stringContains('Bunny is down'), self::anything())->willReturn(1);
        $controller->expects(self::once())->method('stdout')->with("Synced 0 item(s), 1 failed.\n")->willReturn(1);

        self::assertSame(\yii\console\ExitCode::TEMPFAIL, $controller->actionSync());
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a Bunny controller that expects one gate failure.
     */
    private function _bunnyController(bool $write, string $code): BunnyController
    {
        $controller = $this->getMockBuilder(BunnyController::class)->disableOriginalConstructor()
            ->onlyMethods(['requireCpRequest', 'requireAcceptsJson', 'requirePostRequest', 'asFailure'])->getMock();
        $controller->expects(self::once())->method('requireCpRequest');
        $controller->expects(self::once())->method('requireAcceptsJson');
        $controller->expects($write ? self::once() : self::never())->method('requirePostRequest');
        $controller->expects(self::once())->method('asFailure')
            ->with(self::isType('string'), self::callback(fn(array $data) => $data['code'] === $code))
            ->willReturn(new Response(['charset' => 'UTF-8']));

        return $controller;
    }
}

/**
 * Plugin fixture with stubbed settings and per-provider credential gates.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyGatingPlugin extends Plugin
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool whether the edition is Pro
     */
    public bool $pro = false;

    /**
     * @var ?Mux
     */
    public ?Mux $muxService = null;

    /**
     * @var bool whether Mux is enabled
     */
    public bool $mux = false;

    /**
     * @var bool whether Bunny is enabled
     */
    public bool $bunny = false;

    /**
     * @var Settings
     */
    public Settings $testSettings;

    /**
     * @var ?MediaItems
     */
    public ?MediaItems $items = null;

    /**
     * @var ?BunnySync
     */
    public ?BunnySync $sync = null;

    // Public Methods
    // =========================================================================

    public function getSettings(): Settings
    {
        return $this->testSettings;
    }

    public function getIsPro(): bool
    {
        return $this->pro;
    }

    public function getMux(): Mux
    {
        return $this->muxService ?? parent::getMux();
    }

    public function getMuxEnabled(): bool
    {
        return $this->mux;
    }

    public function getBunnyEnabled(): bool
    {
        return $this->bunny;
    }

    public function getMediaItems(): MediaItems
    {
        return $this->items ?? parent::getMediaItems();
    }

    public function getBunnySync(): BunnySync
    {
        return $this->sync ?? parent::getBunnySync();
    }
}
