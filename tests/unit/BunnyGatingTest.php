<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\BunnyController;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\Bunny;
use Craft;
use PHPUnit\Framework\TestCase;
use yii\web\Response;

class BunnyGatingTest extends TestCase
{
    private mixed $previousApp;
    private BunnyGatingPlugin $plugin;
    private Settings $settings;

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        Craft::$app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        $this->settings = new class() extends Settings {
            public ?string $bunnyLibraryId = '133';
            public ?string $bunnyApiKey = null;
            public ?string $bunnyCdnHostname = 'vz-example.b-cdn.net';
            public ?string $bunnyWebhookKey = null;
        };
        $this->plugin = (new \ReflectionClass(BunnyGatingPlugin::class))->newInstanceWithoutConstructor();
        $this->plugin->testSettings = $this->settings;
        Craft::$app->loadedModules[Plugin::class] = $this->plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
        putenv('POLYMEDIA_TEST_BUNNY_KEY');
    }

    public function testSecretsMustBeResolvedEnvironmentReferences(): void
    {
        $bunny = new Bunny();
        $this->settings->bunnyApiKey = 'literal-secret';
        self::assertFalse($bunny->isConfigured());
        $this->settings->bunnyApiKey = '$POLYMEDIA_TEST_BUNNY_KEY';
        self::assertFalse($bunny->isConfigured());
        putenv('POLYMEDIA_TEST_BUNNY_KEY=test-key');
        self::assertTrue($bunny->isConfigured());
        $this->settings->bunnyWebhookKey = 'literal-secret';
        self::assertSame('', $bunny->getWebhookKey());
        $this->settings->bunnyWebhookKey = '$POLYMEDIA_TEST_BUNNY_KEY';
        self::assertSame('test-key', $bunny->getWebhookKey());
        $this->settings->bunnyCdnHostname = 'http://localhost';
        self::assertFalse($bunny->isConfigured());
    }

    public function testManagementActionsRequireCpJsonAndProBeforeRemoteCalls(): void
    {
        foreach ([
            'actionLibrary' => false, 'actionUploadStatus' => false,
            'actionImport' => true, 'actionCreateUpload' => true, 'actionCompleteUpload' => true,
        ] as $action => $write) {
            $controller = $this->controller($write, 'pro_required');
            $controller->$action();
        }
    }

    public function testConfigurationAndActiveProviderAreSeparateGates(): void
    {
        $this->plugin->pro = true;
        $this->controller(false, 'not_configured')->actionLibrary();
        $this->plugin->enabled = true;
        $this->controller(true, 'inactive_provider')->actionCreateUpload();
    }

    private function controller(bool $write, string $code): BunnyController
    {
        $controller = $this->getMockBuilder(BunnyController::class)->disableOriginalConstructor()
            ->onlyMethods(['requireCpRequest', 'requireAcceptsJson', 'requirePostRequest', 'asFailure'])->getMock();
        $controller->expects(self::once())->method('requireCpRequest');
        $controller->expects(self::once())->method('requireAcceptsJson');
        $controller->expects($write ? self::once() : self::never())->method('requirePostRequest');
        $controller->expects(self::once())->method('asFailure')
            ->with(self::isType('string'), ['code' => $code])
            ->willReturn(new Response(['charset' => 'UTF-8']));
        return $controller;
    }
}

/** Integration contract fixture also works before the parent wires Plugin. */
class BunnyGatingPlugin extends Plugin
{
    public bool $pro = false;
    public bool $enabled = false;
    public bool $active = false;
    public Settings $testSettings;

    public function getSettings(): Settings
    {
        return $this->testSettings;
    }

    public function getIsPro(): bool
    {
        return $this->pro;
    }

    public function isBunnyEnabled(): bool
    {
        return $this->enabled;
    }

    public function isVideoProviderEnabled(string $provider): bool
    {
        return $provider === 'bunny' && $this->active;
    }
}
