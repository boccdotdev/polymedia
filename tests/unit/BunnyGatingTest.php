<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\Bunny;
use Craft;
use PHPUnit\Framework\TestCase;

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

    // Public Methods
    // =========================================================================

    public function getSettings(): Settings
    {
        return $this->testSettings;
    }

    public function getMuxEnabled(): bool
    {
        return $this->mux;
    }

    public function getBunnyEnabled(): bool
    {
        return $this->bunny;
    }
}
