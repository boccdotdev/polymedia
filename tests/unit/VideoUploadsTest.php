<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\VideoUploadsController;
use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\Mux;
use boccdotdev\polymedia\services\VideoUploads;
use Craft;
use craft\elements\Asset;
use craft\elements\Entry;
use craft\elements\User;
use craft\models\FieldLayout;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Upload authorization and signing, without a database or remote provider.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class VideoUploadsTest extends TestCase
{
    // Protected Properties
    // =========================================================================

    protected mixed $previousApp;
    protected Settings $settings;
    protected VideoUploads $uploads;
    protected array $body = [];
    protected array $folders = [];
    protected array $fields = [];
    protected array $owners = [];
    protected int $userId = 42;
    protected bool $writable = true;
    protected bool $pro = true;
    protected bool $configured = true;
    protected \craft\services\Security $security;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;
        $this->settings = new Settings([
            'videoProvider' => 'mux',
            'sidecarVolumeUid' => 'sidecar',
        ]);
        $this->uploads = new VideoUploads();

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getIsPro', 'getMux', 'getManifestWriter', 'getVideoUploads'])->getMock();
        $plugin->method('getSettings')->willReturn($this->settings);
        $plugin->method('getIsPro')->willReturnCallback(fn() => $this->pro);
        $mux = $this->createMock(Mux::class);
        $mux->method('isConfigured')->willReturnCallback(fn() => $this->configured);
        $plugin->method('getMux')->willReturn($mux);
        $plugin->method('getVideoUploads')->willReturn($this->uploads);
        $writer = $this->createMock(ManifestWriter::class);
        // Explicit invalid destinations must never resolve a fallback.
        $writer->expects(self::never())->method('resolveFolder');
        $plugin->method('getManifestWriter')->willReturn($writer);
        $app->loadedModules[Plugin::class] = $plugin;

        $identity = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['can'])->getMock();
        $identity->method('can')->willReturnCallback(fn($permission) => $this->writable && str_starts_with($permission, 'saveAssets:'));
        $user = $this->createMock(\craft\web\User::class);
        $user->method('getIdentity')->willReturn($identity);
        $user->method('getId')->willReturnCallback(fn() => $this->userId);
        $app->method('getUser')->willReturn($user);
        $request = $this->createMock(\craft\web\Request::class);
        $request->method('getBodyParam')->willReturnCallback(fn($key, $default = null) => $this->body[$key] ?? $default);
        $request->method('getBodyParams')->willReturnCallback(fn() => $this->body);
        $app->method('getRequest')->willReturn($request);

        // Yii's real authenticated-data implementation with a test-only key.
        $signer = new \yii\base\Security();
        $this->security = $this->createMock(\craft\services\Security::class);
        $this->security->method('hashData')->willReturnCallback(fn($data) => $signer->hashData($data, 'video-upload-test-key'));
        $this->security->method('validateData')->willReturnCallback(fn($data) => $signer->validateData($data, 'video-upload-test-key'));
        $app->method('getSecurity')->willReturn($this->security);
        $app->method('getCache')->willReturn(new \yii\caching\ArrayCache());
        $mutex = $this->createMock(\yii\mutex\Mutex::class);
        $mutex->method('acquire')->willReturn(true);
        $mutex->method('release')->willReturn(true);
        $app->method('getMutex')->willReturn($mutex);
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getFolderById')->willReturnCallback(fn($id) => $this->folders[$id] ?? null);
        $app->method('getAssets')->willReturn($assets);
        $fields = $this->createMock(\craft\services\Fields::class);
        $fields->method('getFieldById')->willReturnCallback(fn($id) => $this->fields[$id] ?? null);
        $app->method('getFields')->willReturn($fields);
        $elements = $this->createMock(\craft\services\Elements::class);
        $elements->method('getElementById')->willReturnCallback(fn($id) => $this->owners[$id] ?? null);
        $app->method('getElements')->willReturn($elements);

        foreach ([10 => 'videos', 20 => 'ordinary', 30 => 'sidecar'] as $id => $uid) {
            $folder = $this->getMockBuilder(VolumeFolder::class)->onlyMethods(['getVolume'])->getMock();
            $folder->id = $id;
            $folder->method('getVolume')->willReturn(new Volume(['id' => $id, 'uid' => $uid]));
            $this->folders[$id] = $folder;
        }
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
        parent::tearDown();
    }

    public static function providerFailures(): array
    {
        return [
            'lite' => [false, true, 'mux'],
            'unconfigured' => [true, false, 'mux'],
            'unknown provider' => [true, true, 'other'],
        ];
    }

    #[DataProvider('providerFailures')]
    public function testPreflightRequiresProAndConfiguredSelectedProvider(bool $pro, bool $configured, string $provider): void
    {
        $this->pro = $pro;
        $this->configured = $configured;
        $this->settings->videoProvider = $provider;
        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->preflight(['folderId' => 10]);
    }

    public function testPreflightSignsTheDestination(): void
    {
        $result = $this->uploads->preflight(['folderId' => 20, 'filename' => 'film.mov']);

        self::assertSame('mux', $result['provider']);
        self::assertSame(20, $result['folderId']);
        self::assertNotFalse($this->security->validateData(base64_decode($result['token'])));
    }

    public static function destinationFailures(): array
    {
        return [
            'unknown folder' => [999, true],
            'not writable' => [20, false],
            'sidecar' => [30, true],
        ];
    }

    #[DataProvider('destinationFailures')]
    public function testInvalidExplicitFolderNeverUsesAnotherDestination(int $folder, bool $writable): void
    {
        $this->writable = $writable;
        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->preflight(['folderId' => $folder]);
    }

    public function testSignedCreateIsIdempotentAndCompletionBindsUploadId(): void
    {
        $this->start();
        $calls = 0;
        $create = static function() use (&$calls): array {
            $calls++;

            return ['uploadId' => 'remote-1', 'url' => 'https://upload.example.test/1'];
        };
        $first = $this->uploads->createUpload('mux', $create);

        self::assertSame($first, $this->uploads->createUpload('mux', $create));
        self::assertSame(1, $calls);
        self::assertSame(10, $first['folderId']);
        self::assertSame(42, $this->uploads->completeUpload('mux', 'remote-1')['userId']);

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->completeUpload('mux', 'remote-2');
    }

    public static function invalidContexts(): array
    {
        return array_map(static fn($case) => [$case], ['tamper', 'expiry', 'user', 'provider', 'folder', 'missing', 'invalid base64']);
    }

    #[DataProvider('invalidContexts')]
    public function testInvalidSignedContextCannotCreateRemoteUpload(string $case): void
    {
        $this->start();
        $provider = 'mux';

        match ($case) {
            'tamper' => $this->body['uploadContext'] = base64_encode(base64_decode($this->body['uploadContext']) . 'x'),
            'expiry' => $this->body['uploadContext'] = $this->_resign(['expires' => time() - 1]),
            'user' => $this->userId = 99,
            'provider' => $provider = 'bunny',
            'folder' => $this->body['folderId'] = 20,
            'missing' => $this->body = [],
            'invalid base64' => $this->body['uploadContext'] = '!invalid',
        };

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->createUpload($provider, static function(): array {
            self::fail('Authorization must fail before contacting a provider.');
        });
    }

    public function testCompletionRejectsTokenThatNeverCreatedAnUpload(): void
    {
        $this->start();
        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->completeUpload('mux', 'remote-1');
    }

    public static function revokedAccess(): array
    {
        return [['permission'], ['provider'], ['edition']];
    }

    #[DataProvider('revokedAccess')]
    public function testCompletionRechecksAccessAfterStart(string $change): void
    {
        $this->start();
        $this->uploads->createUpload('mux', static fn() => ['uploadId' => 'remote-1']);

        match ($change) {
            'permission' => $this->writable = false,
            'provider' => $this->settings->videoProvider = 'bunny',
            'edition' => $this->pro = false,
        };

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->completeUpload('mux', 'remote-1');
    }

    public static function fieldFailures(): array
    {
        return [['kind'], ['provider'], ['source'], ['location']];
    }

    #[DataProvider('fieldFailures')]
    public function testFieldConstraintsAreCheckedBeforeSigning(string $failure): void
    {
        $field = $this->field();

        match ($failure) {
            'kind' => $field->allowedKinds = ['video'],
            'provider' => $field->allowedProviders = ['bunny'],
            'source' => $field->sources = ['volume:ordinary'],
            'location' => $field->restrictLocation = true,
        };

        $this->expectException(ForbiddenHttpException::class);
        $this->start(['fieldId' => 7]);
    }

    public function testFieldAllowingPolymediaAndProviderCanCreateAndComplete(): void
    {
        $this->field()->allowUploads = false;
        $this->start(['fieldId' => 7]);
        $this->uploads->createUpload('mux', static fn() => ['uploadId' => 'remote-1']);

        self::assertSame(7, $this->uploads->completeUpload('mux', 'remote-1')['fieldId']);
    }

    public static function ownerFailures(): array
    {
        return ['missing' => [false, false], 'cannot save' => [true, false], 'foreign field' => [true, true]];
    }

    #[DataProvider('ownerFailures')]
    public function testOwnerMustExistBeEditableAndContainTheField(bool $exists, bool $canSave): void
    {
        $this->field();

        if ($exists) {
            $owner = $this->getMockBuilder(Entry::class)->disableOriginalConstructor()->onlyMethods(['canSave', 'getFieldLayout'])->getMock();
            $owner->method('canSave')->willReturn($canSave);
            $layout = $this->createMock(FieldLayout::class);
            $layout->method('getFieldById')->willReturn(null);
            $owner->method('getFieldLayout')->willReturn($layout);
            $this->owners[8] = $owner;
        }

        $this->expectException(ForbiddenHttpException::class);
        $this->start(['fieldId' => 7, 'elementId' => 8, 'siteId' => 1]);
    }

    public function testControllerRequiresCpPostJsonAndReturnsSignedPreflight(): void
    {
        $this->body = ['folderId' => 10];
        $controller = $this->getMockBuilder(VideoUploadsController::class)->disableOriginalConstructor()
            ->onlyMethods(['requireCpRequest', 'requirePostRequest', 'requireAcceptsJson', 'asSuccess'])->getMock();

        foreach (['requireCpRequest', 'requirePostRequest', 'requireAcceptsJson'] as $method) {
            $controller->expects(self::once())->method($method);
        }

        $response = new Response(['charset' => 'UTF-8']);
        $controller->expects(self::once())->method('asSuccess')->willReturnCallback(
            function($message = null, $data = [], $redirect = null) use ($response) {
                self::assertSame(10, $data['folderId']);
                self::assertNotFalse($this->security->validateData(base64_decode($data['token'])));

                return $response;
            },
        );

        self::assertSame($response, $controller->actionPreflight());
    }

    public function testCompletionChecksTheActualAssetSelectionCondition(): void
    {
        $field = $this->field();
        $condition = $this->getMockBuilder(\craft\elements\conditions\assets\AssetCondition::class)
            ->disableOriginalConstructor()->onlyMethods(['matchElement'])->getMock();
        $asset = $this->getMockBuilder(Asset::class)->disableOriginalConstructor()->getMock();
        $condition->expects(self::once())->method('matchElement')->with($asset)->willReturn(false);
        $field->method('getSelectionCondition')->willReturn($condition);
        $this->start(['fieldId' => 7]);
        $this->uploads->createUpload('mux', static fn() => ['uploadId' => 'remote-1']);
        $context = $this->uploads->completeUpload('mux', 'remote-1');

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->assertSelectable($context, $asset);
    }

    public function testDifferentSignedSessionsCannotCompleteEachOthersUploads(): void
    {
        $this->start();
        $this->uploads->createUpload('mux', static fn() => ['uploadId' => 'remote-1']);
        $this->start();
        $this->uploads->createUpload('mux', static fn() => ['uploadId' => 'remote-2']);

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->completeUpload('mux', 'remote-1');
    }

    public function testExplicitUploadDoesNotNeedRoutingOrAnMp4(): void
    {
        $result = $this->uploads->preflight(['folderId' => 20, 'filename' => 'film.mov']);

        self::assertTrue($result['route']);
        self::assertArrayHasKey('token', $result);
    }

    public function testRoutingSignsSelectedVolumesAndLeavesOthersNative(): void
    {
        $this->_enableRouting();

        self::assertTrue($this->start(['routing' => true])['route']);
        self::assertSame(['provider' => 'mux', 'folderId' => 20, 'route' => false], $this->uploads->preflight([
            'routing' => true, 'folderId' => 20, 'filename' => 'FILM.MP4',
        ]));
    }

    public static function routingFailures(): array
    {
        return [
            'opted out' => [10, 'film.mp4', false, true],
            'not an mp4' => [10, 'film.mov', true, true],
            'unknown folder' => [999, 'film.mp4', true, true],
            'no folder' => [0, 'film.mp4', true, true],
            'not writable' => [20, 'film.mp4', true, false],
            'sidecar' => [30, 'film.mp4', true, true],
        ];
    }

    #[DataProvider('routingFailures')]
    public function testInvalidRoutingNeverFallsBackToNative(int $folder, string $filename, bool $enabled, bool $writable): void
    {
        $this->_enableRouting();
        $this->settings->autoRouteVideoUploads = $enabled;
        $this->writable = $writable;

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->preflight(['routing' => true, 'folderId' => $folder, 'filename' => $filename]);
    }

    public static function revokedRouting(): array
    {
        return [['opt in'], ['volumes']];
    }

    #[DataProvider('revokedRouting')]
    public function testRoutedCompletionRechecksRouting(string $change): void
    {
        $this->_enableRouting();
        $this->start(['routing' => true]);
        $this->uploads->createUpload('mux', static fn() => ['uploadId' => 'remote-1']);

        match ($change) {
            'opt in' => $this->settings->autoRouteVideoUploads = false,
            'volumes' => $this->settings->videoUploadVolumeUids = [],
        };

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->completeUpload('mux', 'remote-1');
    }

    public static function nativeDestinationFailures(): array
    {
        return ['uploads disabled' => ['uploads'], 'volume excluded' => ['source'], 'outside root' => ['location']];
    }

    #[DataProvider('nativeDestinationFailures')]
    public function testNativeDestinationStillRequiresFieldAuthorization(string $failure): void
    {
        $this->_enableRouting();
        $field = $this->field(10, native: $failure === 'uploads');
        $field->sources = ['volume:ordinary'];

        match ($failure) {
            'uploads' => $field->allowUploads = false,
            'source' => $field->sources = ['volume:videos'],
            'location' => $field->restrictLocation = true,
        };

        $this->expectException(ForbiddenHttpException::class);
        $this->uploads->preflight(['routing' => true, 'filename' => 'film.mp4', 'folderId' => 20, 'fieldId' => 7]);
    }

    public function testNativeOnlyFieldUploadsNativelyWithoutPolymediaKind(): void
    {
        $this->_enableRouting();
        $field = $this->field(native: true);
        $field->sources = ['volume:ordinary'];
        $field->allowedKinds = ['video'];

        self::assertSame(['provider' => 'mux', 'folderId' => 20, 'route' => false], $this->uploads->preflight([
            'routing' => true, 'filename' => 'film.mp4', 'folderId' => 20, 'fieldId' => 7,
        ]));
    }

    public function testRoutedUploadNeedsANativeFieldThatAllowsUploads(): void
    {
        $this->_enableRouting();
        $this->field(native: true)->allowUploads = false;

        $this->expectException(ForbiddenHttpException::class);
        $this->start(['routing' => true, 'fieldId' => 7]);
    }

    // Protected Methods
    // =========================================================================

    /**
     * Runs preflight and posts its context, as the browser would.
     */
    protected function start(array $extra = []): array
    {
        $result = $this->uploads->preflight($extra + ['filename' => 'film.mp4', 'folderId' => 10]);
        $this->body = ['uploadContext' => $result['token'], 'folderId' => $result['folderId']];

        return $result;
    }

    /**
     * Returns a mocked Polymedia (or native Assets) field.
     */
    protected function field(int $rootFolderId = 20, bool $native = false): \craft\fields\Assets
    {
        $field = $this->getMockBuilder($native ? \craft\fields\Assets::class : PolymediaField::class)->disableOriginalConstructor()
            ->onlyMethods(['resolveDynamicPathToFolderId', 'getSelectionCondition'])->getMock();
        $field->id = 7;
        $field->uid = 'field-7';
        $field->restrictFiles = true;
        $field->allowedKinds = ['polymedia'];

        if ($field instanceof PolymediaField) {
            $field->allowedProviders = ['mux'];
        }

        $field->allowUploads = true;
        $field->restrictLocation = false;
        $field->sources = ['volume:videos'];
        $field->method('resolveDynamicPathToFolderId')->willReturn($rootFolderId);
        $this->fields[7] = $field;

        return $field;
    }

    // Private Methods
    // =========================================================================

    /**
     * Turns on routing for the `videos` volume.
     */
    private function _enableRouting(): void
    {
        $this->settings->autoRouteVideoUploads = true;
        $this->settings->videoUploadVolumeUids = ['videos'];
    }

    /**
     * Re-signs the posted context with changes.
     */
    private function _resign(array $changes): string
    {
        $context = json_decode($this->security->validateData(base64_decode($this->body['uploadContext'])), true);

        return base64_encode($this->security->hashData(json_encode($changes + $context)));
    }
}
