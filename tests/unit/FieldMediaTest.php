<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\FieldMediaController;
use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\AssetFieldSettings;
use boccdotdev\polymedia\services\FieldMedia;
use boccdotdev\polymedia\services\MediaItems;
use Craft;
use craft\elements\Asset;
use craft\elements\conditions\assets\AssetCondition;
use craft\elements\Entry;
use craft\elements\User;
use craft\fields\Assets as AssetsField;
use craft\models\FieldLayout;
use craft\models\Volume;
use craft\models\VolumeFolder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Field destinations and selection checks behind the Add video or audio
 * menu, without a database.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class FieldMediaTest extends TestCase
{
    // Protected Properties
    // =========================================================================

    protected mixed $previousApp;
    protected Settings $settings;
    protected FieldMedia $service;
    protected array $body = [];
    protected array $folders = [];
    protected array $fields = [];
    protected array $owners = [];
    protected array $assets = [];
    protected array $writable = ['videos', 'ordinary', 'sidecar', 'temp'];
    protected bool $videoEnabled = false;
    protected array $nativeProviders = [];
    protected ?MediaItemRecord $record = null;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;
        $this->settings = new Settings(['videoProvider' => 'bunny', 'sidecarVolumeUid' => 'sidecar']);
        $this->service = new FieldMedia();

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getVideoEnabled', 'getAssetFieldSettings', 'getMediaItems', 'getFieldMedia'])->getMock();
        $plugin->method('getSettings')->willReturn($this->settings);
        $plugin->method('getVideoEnabled')->willReturnCallback(fn() => $this->videoEnabled);
        $plugin->method('getFieldMedia')->willReturn($this->service);
        $fieldSettings = $this->createMock(AssetFieldSettings::class);
        $fieldSettings->method('getAllowedProviders')->willReturnCallback(fn() => $this->nativeProviders);
        $plugin->method('getAssetFieldSettings')->willReturn($fieldSettings);
        $items = $this->createMock(MediaItems::class);
        $items->method('getByAssetId')->willReturnCallback(fn() => $this->record);
        $plugin->method('getMediaItems')->willReturn($items);
        $app->loadedModules[Plugin::class] = $plugin;

        $identity = $this->getMockBuilder(User::class)->disableOriginalConstructor()->onlyMethods(['can'])->getMock();
        $identity->method('can')->willReturnCallback(
            fn(string $permission) => in_array(substr($permission, strlen('saveAssets:')), $this->writable, true),
        );
        $user = $this->createMock(\craft\web\User::class);
        $user->method('getIdentity')->willReturn($identity);
        $app->method('getUser')->willReturn($user);
        $request = $this->createMock(\craft\web\Request::class);
        $request->method('getBodyParam')->willReturnCallback(fn($key, $default = null) => $this->body[$key] ?? $default);
        $request->method('getRequiredBodyParam')->willReturnCallback(fn($key) => $this->body[$key]);
        $app->method('getRequest')->willReturn($request);

        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getFolderById')->willReturnCallback(fn($id) => $this->folders[$id] ?? null);
        $assets->method('getAssetById')->willReturnCallback(fn($id) => $this->assets[$id] ?? null);
        $app->method('getAssets')->willReturn($assets);
        $fields = $this->createMock(\craft\services\Fields::class);
        $fields->method('getFieldById')->willReturnCallback(fn($id) => $this->fields[$id] ?? null);
        $app->method('getFields')->willReturn($fields);
        $elements = $this->createMock(\craft\services\Elements::class);
        $elements->method('getElementById')->willReturnCallback(fn($id) => $this->owners[$id] ?? null);
        $app->method('getElements')->willReturn($elements);
        $volumes = $this->createMock(\craft\services\Volumes::class);
        $volumes->method('getVolumeByUid')->willReturnCallback(
            fn(string $uid) => in_array($uid, ['videos', 'ordinary', 'sidecar'], true) ? new Volume(['uid' => $uid]) : null,
        );
        $app->method('getVolumes')->willReturn($volumes);

        foreach ([10 => 'videos', 20 => 'ordinary', 30 => 'sidecar', 40 => 'temp'] as $id => $uid) {
            $folder = $this->getMockBuilder(VolumeFolder::class)->onlyMethods(['getVolume'])->getMock();
            $folder->id = $id;
            // The temporary upload folder has no volume ID.
            $folder->volumeId = $uid === 'temp' ? null : $id;
            $folder->method('getVolume')->willReturn(new Volume(['id' => $id, 'uid' => $uid]));
            $this->folders[$id] = $folder;
        }
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
        parent::tearDown();
    }

    public static function kinds(): array
    {
        return [
            'polymedia' => [true, ['polymedia'], true],
            'polymedia and images' => [true, ['image', 'polymedia'], true],
            'images only' => [true, ['image'], false],
            'unrestricted' => [false, [], false],
        ];
    }

    #[DataProvider('kinds')]
    public function testOnlyFieldsExplicitlyAllowingPolymediaOfferMedia(bool $restrict, array $kinds, bool $expected): void
    {
        $field = $this->field(native: true);
        $field->restrictFiles = $restrict;
        $field->allowedKinds = $kinds;

        self::assertSame($expected, FieldMedia::offersMedia($field));
        self::assertSame($expected, $this->service->getMenu($field) !== null);
    }

    public static function menus(): array
    {
        return [
            'lite, any provider' => [false, [], ['url', 'existing']],
            'pro, any provider' => [true, [], ['url', 'existing', 'library', 'upload']],
            'url providers only' => [true, ['youtube', 'vimeo'], ['url']],
            'mp4' => [true, ['mp4'], ['url', 'existing']],
            'the hosting provider only' => [true, ['bunny'], ['library', 'upload']],
            'another hosting provider' => [true, ['mux'], ['url']],
            'hosting provider unavailable' => [false, ['bunny'], []],
        ];
    }

    #[DataProvider('menus')]
    public function testMenuItemsFollowTheFieldsProvidersAndEdition(bool $videoEnabled, array $providers, array $expected): void
    {
        $this->videoEnabled = $videoEnabled;

        foreach ([false, true] as $native) {
            $field = $this->field(native: $native);
            $this->nativeProviders = $providers;

            if ($field instanceof PolymediaField) {
                $field->allowedProviders = $providers;
            }

            self::assertSame($expected, $this->service->getMenuItems($field));
        }
    }

    public static function addPermissions(): array
    {
        return [
            'writable' => ['volume:videos', ['videos'], true],
            'not writable' => ['volume:videos', ['ordinary'], false],
            'sidecar' => ['volume:sidecar', ['sidecar'], false],
            'unknown volume' => ['volume:missing', ['missing'], false],
            'no location' => [null, ['videos'], false],
        ];
    }

    #[DataProvider('addPermissions')]
    public function testTheButtonNeedsPermissionToSaveToTheUploadVolume(?string $source, array $writable, bool $expected): void
    {
        $this->writable = $writable;
        $field = $this->field();
        $field->defaultUploadLocationSource = $source;
        // Rendering never resolves the dynamic subpath, which creates folders.
        $field->expects(self::never())->method('resolveDynamicPathToFolderId');

        self::assertSame($expected, $this->service->getMenu($field)['canAdd']);
    }

    public function testDestinationIsTheFieldsUploadFolderForTheOwner(): void
    {
        $this->owner(8);
        $field = $this->field(20);
        $field->expects(self::once())->method('resolveDynamicPathToFolderId')
            ->with($this->owners[8])->willReturn(20);

        self::assertSame(20, (int)$this->service->getDestination(['fieldId' => 7, 'elementId' => 8, 'siteId' => 1])->id);
    }

    public static function destinationFailures(): array
    {
        return [
            'images only' => ['kind'],
            'not writable' => ['writable'],
            'temporary folder' => ['temp'],
            'sidecar' => ['sidecar'],
            'invalid subpath' => ['subpath'],
            'cannot save owner' => ['owner'],
            'foreign field' => ['layout'],
        ];
    }

    #[DataProvider('destinationFailures')]
    public function testDestinationRefusesWhatTheFieldOrUserDoesNotAllow(string $failure): void
    {
        $field = $this->field(match ($failure) {
            'temp' => 40,
            'sidecar' => 30,
            default => 10,
        });
        $this->owner(8, canSave: $failure !== 'owner', inLayout: $failure !== 'layout');

        match ($failure) {
            'kind' => $field->allowedKinds = ['image'],
            'writable' => $this->writable = [],
            'subpath' => $this->fields[7] = $this->field(subpathFails: true),
            default => null,
        };

        $this->expectException(ForbiddenHttpException::class);
        $this->service->getDestination(['fieldId' => 7, 'elementId' => 8, 'siteId' => 1]);
    }

    public function testDestinationNeedsAnAssetsField(): void
    {
        $this->expectException(BadRequestHttpException::class);
        $this->service->getDestination(['fieldId' => 99]);
    }

    public function testMatchingAssetIsSelectable(): void
    {
        $this->field()->allowedProviders = ['vimeo'];
        $this->record = $this->record('vimeo');

        self::assertTrue($this->service->isSelectable(['fieldId' => 7], $this->asset()));
    }

    public static function selectionFailures(): array
    {
        return [['view'], ['kind'], ['source'], ['location'], ['provider'], ['missing record'], ['condition']];
    }

    #[DataProvider('selectionFailures')]
    public function testSelectionChecksWhatTheFieldEnforces(string $failure): void
    {
        $field = $this->field(rootFolderId: 10);
        $field->allowedProviders = ['vimeo'];
        $this->record = $this->record('vimeo');
        $asset = $this->asset(canView: $failure !== 'view', folderId: in_array($failure, ['source', 'location'], true) ? 20 : 10);

        match ($failure) {
            'kind' => $asset->kind = 'video',
            'location' => $field->restrictLocation = true,
            'provider' => $this->record = $this->record('youtube'),
            'missing record' => $this->record = null,
            'condition' => $field->method('getSelectionCondition')->willReturn($this->condition(false)),
            default => null,
        };

        self::assertFalse($this->service->isSelectable(['fieldId' => 7], $asset));
    }

    public function testSelectionConditionUsesTheOwnerAsReference(): void
    {
        $owner = $this->owner(8);
        $condition = $this->condition(true);
        $this->field()->method('getSelectionCondition')->willReturn($condition);

        self::assertTrue($this->service->isSelectable(['fieldId' => 7, 'elementId' => 8], $this->asset()));
        self::assertSame($owner, $condition->referenceElement);
    }

    public function testDestinationActionReturnsTheFolderOrTheRefusal(): void
    {
        $this->field(20);
        $this->body = ['fieldId' => 7];

        self::assertSame(['folderId' => 20], $this->controller(success: true)->actionDestination()->data);

        $this->writable = [];
        self::assertSame('You cannot add media to this field’s upload location.', $this->controller(success: false)->actionDestination()->data);
    }

    public function testCheckActionIsFalseForInvalidFieldsAndMissingAssets(): void
    {
        $this->assets[42] = $this->asset();
        $this->field();

        foreach ([[7, 42, true], [7, 43, false], [99, 42, false]] as [$fieldId, $assetId, $expected]) {
            $this->body = ['fieldId' => $fieldId, 'assetId' => $assetId];
            self::assertSame(['selectable' => $expected], $this->controller()->actionCheck()->data);
        }
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns a mocked Polymedia (or native Assets) field allowing the `videos` volume.
     */
    protected function field(int $rootFolderId = 10, bool $native = false, bool $subpathFails = false): AssetsField
    {
        $field = $this->getMockBuilder($native ? AssetsField::class : PolymediaField::class)->disableOriginalConstructor()
            ->onlyMethods(['resolveDynamicPathToFolderId', 'getSelectionCondition'])->getMock();
        $field->id = 7;
        $field->uid = 'field-7';
        $field->restrictFiles = true;
        $field->allowedKinds = ['polymedia'];
        $field->restrictLocation = false;
        $field->allowSubfolders = false;
        $field->sources = ['volume:videos'];
        $field->defaultUploadLocationSource = 'volume:videos';

        if ($field instanceof PolymediaField) {
            $field->allowedProviders = [];
        }

        if ($subpathFails) {
            $field->method('resolveDynamicPathToFolderId')->willThrowException(new \craft\errors\InvalidSubpathException('{slug}'));
        } else {
            $field->method('resolveDynamicPathToFolderId')->willReturn($rootFolderId);
        }

        $this->fields[7] = $field;

        return $field;
    }

    /**
     * Registers an owner element for ID `$id`.
     */
    protected function owner(int $id, bool $canSave = true, bool $inLayout = true): Entry
    {
        $owner = $this->getMockBuilder(Entry::class)->disableOriginalConstructor()->onlyMethods(['canSave', 'getFieldLayout'])->getMock();
        $owner->method('canSave')->willReturn($canSave);
        $layout = $this->createMock(FieldLayout::class);
        $layout->method('getFieldById')->willReturnCallback(fn($fieldId) => $inLayout ? ($this->fields[$fieldId] ?? null) : null);
        $owner->method('getFieldLayout')->willReturn($layout);
        $this->owners[$id] = $owner;

        return $owner;
    }

    /**
     * Returns a mocked `.pmedia` asset.
     */
    protected function asset(bool $canView = true, int $folderId = 10): Asset
    {
        $asset = $this->getMockBuilder(Asset::class)->disableOriginalConstructor()->onlyMethods(['canView', 'getFolder'])->getMock();
        $asset->id = 42;
        $asset->kind = 'polymedia';
        $asset->method('canView')->willReturn($canView);
        $asset->method('getFolder')->willReturn($this->folders[$folderId]);

        return $asset;
    }

    /**
     * Returns a media item record of a provider type.
     */
    protected function record(string $type): MediaItemRecord
    {
        $record = new class() extends MediaItemRecord {
            public $type;
        };
        $record->type = $type;

        return $record;
    }

    /**
     * Returns a selection condition that does or doesn't match.
     */
    protected function condition(bool $matches): AssetCondition
    {
        $condition = $this->getMockBuilder(AssetCondition::class)->disableOriginalConstructor()->onlyMethods(['matchElement'])->getMock();
        $condition->method('matchElement')->willReturn($matches);

        return $condition;
    }

    /**
     * Returns the controller with its request checks stubbed and responses captured.
     */
    protected function controller(bool $success = true): FieldMediaController
    {
        $controller = $this->getMockBuilder(FieldMediaController::class)->disableOriginalConstructor()
            ->onlyMethods(['requireCpRequest', 'requirePostRequest', 'requireAcceptsJson', 'asSuccess', 'asFailure', 'asJson'])->getMock();
        $controller->request = Craft::$app->getRequest();

        foreach (['requireCpRequest', 'requirePostRequest', 'requireAcceptsJson'] as $method) {
            $controller->expects(self::once())->method($method);
        }

        $respond = static fn($data) => new Response(['charset' => 'UTF-8', 'data' => $data]);
        $controller->expects($success ? self::any() : self::never())->method('asSuccess')
            ->willReturnCallback(fn($message = null, $data = []) => $respond($data));
        $controller->expects($success ? self::never() : self::once())->method('asFailure')
            ->willReturnCallback(fn($message = null) => $respond($message));
        $controller->method('asJson')->willReturnCallback($respond);

        return $controller;
    }
}
