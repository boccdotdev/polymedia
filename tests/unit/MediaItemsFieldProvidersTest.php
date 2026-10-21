<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\MediaItemsController;
use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\FieldMedia;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\ThumbnailDeriver;
use boccdotdev\polymedia\services\UrlDetector;
use Craft;
use craft\elements\User;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\web\Response;

/**
 * From URL for a field refuses providers the field doesn't allow, before
 * creating anything.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class MediaItemsFieldProvidersTest extends TestCase
{
    // Protected Properties
    // =========================================================================

    protected mixed $previousApp;
    protected array $body = [];
    protected array $fields = [];
    protected ManifestWriter $writer;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;

        $folder = $this->getMockBuilder(VolumeFolder::class)->onlyMethods(['getVolume'])->getMock();
        $folder->id = 10;
        $folder->volumeId = 1;
        $folder->method('getVolume')->willReturn(new Volume(['id' => 1, 'uid' => 'videos']));
        $this->writer = $this->createMock(ManifestWriter::class);
        $this->writer->method('resolveFolder')->willReturn($folder);
        $deriver = $this->createMock(ThumbnailDeriver::class);

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getManifestWriter', 'getUrlDetector', 'getThumbnailDeriver', 'getFieldMedia'])->getMock();
        $plugin->method('getSettings')->willReturn(new Settings(['warnOnSignedUrlInPublicVolume' => false]));
        $plugin->method('getManifestWriter')->willReturn($this->writer);
        $plugin->method('getUrlDetector')->willReturn(new UrlDetector());
        $plugin->method('getThumbnailDeriver')->willReturn($deriver);
        $plugin->method('getFieldMedia')->willReturn(new FieldMedia());
        $app->loadedModules[Plugin::class] = $plugin;

        $request = $this->createMock(\craft\web\Request::class);
        $request->method('getBodyParam')->willReturnCallback(fn($key, $default = null) => $this->body[$key] ?? $default);
        $request->method('getRequiredBodyParam')->willReturnCallback(fn($key) => $this->body[$key]);
        $app->method('getRequest')->willReturn($request);
        $user = $this->createMock(\craft\web\User::class);
        $user->method('getIdentity')->willReturn($this->getMockBuilder(User::class)->disableOriginalConstructor()->getMock());
        $app->method('getUser')->willReturn($user);
        $fields = $this->createMock(\craft\services\Fields::class);
        $fields->method('getFieldById')->willReturnCallback(fn($id) => $this->fields[$id] ?? null);
        $app->method('getFields')->willReturn($fields);
        $i18n = $this->createMock(\craft\i18n\I18N::class);
        $i18n->method('translate')->willReturnCallback(
            fn($category, $message, $params) => strtr($message, array_combine(
                array_map(fn($key) => '{' . $key . '}', array_keys($params)),
                $params,
            )),
        );
        $app->method('getI18n')->willReturn($i18n);

        $field = $this->getMockBuilder(PolymediaField::class)->disableOriginalConstructor()->onlyMethods([])->getMock();
        $field->allowedProviders = ['youtube'];
        $this->fields[7] = $field;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
        parent::tearDown();
    }

    public function testDisallowedProviderIsRefusedBeforeCreating(): void
    {
        $this->body = ['url' => 'https://vimeo.com/123456789', 'title' => 'Film', 'fieldId' => 7];
        $this->writer->expects(self::never())->method('create');
        $controller = $this->controller();
        $controller->expects(self::once())->method('asFailure')
            ->with('This field doesn’t allow Vimeo media.')
            ->willReturn(new Response());

        $controller->actionCreate();
    }

    public static function unrestricted(): array
    {
        return [
            'allowed provider' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ', 7],
            'no field' => ['https://vimeo.com/123456789', null],
            'not an Assets field' => ['https://vimeo.com/123456789', 99],
        ];
    }

    #[DataProvider('unrestricted')]
    public function testOtherUrlsReachCreation(string $url, ?int $fieldId): void
    {
        $this->body = ['url' => $url, 'title' => 'Film', 'fieldId' => $fieldId];
        $this->fields[99] = new \craft\fields\PlainText();
        // Creation is past the provider check; stop there.
        $this->writer->expects(self::once())->method('create')->willThrowException(new \LogicException('created'));
        $controller = $this->controller();
        $controller->expects(self::never())->method('asFailure');

        $this->expectExceptionMessage('created');
        $controller->actionCreate();
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns the controller with its request checks stubbed.
     */
    protected function controller(): MediaItemsController
    {
        return $this->getMockBuilder(MediaItemsController::class)->disableOriginalConstructor()
            ->onlyMethods(['requirePostRequest', 'requireCpRequest', 'asFailure'])->getMock();
    }
}
