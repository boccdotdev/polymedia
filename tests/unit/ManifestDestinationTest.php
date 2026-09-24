<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\services\ManifestWriter;
use Craft;
use craft\elements\User;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\Assets;
use craft\services\UserPermissions;
use craft\services\Volumes;
use PHPUnit\Framework\TestCase;

class ManifestDestinationTest extends TestCase
{
    private mixed $previousApp;
    private User $user;
    private Settings $settings;
    private ManifestWriter $writer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousApp = Craft::$app;
        $this->user = (new \ReflectionClass(User::class))->newInstanceWithoutConstructor();
        $this->user->admin = true;
        $this->settings = new Settings(['sidecarVolumeUid' => 'sidecar']);
        $this->writer = new ManifestWriter();
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->previousApp;
        parent::tearDown();
    }

    public function testFallbackSkipsSidecarEvenWhenItIsTheFirstWritableVolume(): void
    {
        $this->volumes(['sidecar', 'uploads']);

        $folder = $this->writer->resolveFolder(null, $this->user, $this->settings);

        $this->assertSame(2, $folder->volumeId);
    }

    public function testExplicitSidecarFolderIsRejectedInsteadOfSilentlyRedirected(): void
    {
        $this->volumes(['sidecar', 'uploads']);

        $this->assertNull($this->writer->resolveFolder(10, $this->user, $this->settings));
    }

    public function testOnlySidecarAvailableReturnsNoDestination(): void
    {
        $this->volumes(['sidecar']);

        $this->assertNull($this->writer->resolveFolder(null, $this->user, $this->settings));
    }

    public function testDefaultAndExplicitLibraryDestinationsArePreserved(): void
    {
        $this->volumes(['sidecar', 'uploads', 'videos']);
        $this->settings->defaultVolumeUid = 'videos';

        $this->assertSame(3, $this->writer->resolveFolder(null, $this->user, $this->settings)->volumeId);
        $this->assertSame(2, $this->writer->resolveFolder(20, $this->user, $this->settings)->volumeId);
        $this->assertNull($this->writer->resolveFolder(20, null, $this->settings));
    }

    public function testNonAdminCannotUseDeniedCurrentOrDefaultVolume(): void
    {
        $this->user->admin = false;
        $this->user->id = 42;
        $permissions = $this->createMock(UserPermissions::class);
        $permissions->method('doesUserHavePermission')->willReturnCallback(
            static fn(int $userId, string $permission): bool => $userId === 42 && $permission === 'saveAssets:videos',
        );
        $this->volumes(['sidecar', 'uploads', 'videos'], $permissions);
        $this->settings->defaultVolumeUid = 'uploads';

        $this->assertNull($this->writer->resolveFolder(20, $this->user, $this->settings));
        $this->assertNull($this->writer->resolveFolder(999, $this->user, $this->settings));
        $this->assertSame(3, $this->writer->resolveFolder(null, $this->user, $this->settings)->volumeId);
    }

    private function volumes(array $uids, ?UserPermissions $permissions = null): void
    {
        $volumes = [];
        $folders = [];

        foreach ($uids as $index => $uid) {
            $id = $index + 1;
            $volumes[] = new Volume(['id' => $id, 'uid' => $uid]);
            $folders[$id] = new VolumeFolder(['id' => $id * 10, 'volumeId' => $id]);
        }

        $assets = $this->createMock(Assets::class);
        $assets->method('getFolderById')->willReturnCallback(static fn(int $id) => $folders[$id / 10] ?? null);
        $assets->method('getRootFolderByVolumeId')->willReturnCallback(static fn(int $id) => $folders[$id] ?? null);
        $volumeService = $this->createMock(Volumes::class);
        $volumeService->method('getAllVolumes')->willReturn($volumes);

        new class($assets, $volumeService, $permissions) extends \yii\console\Application {
            public \craft\enums\CmsEdition $edition = \craft\enums\CmsEdition::Pro;

            public function __construct(
                private Assets $assets,
                private Volumes $volumes,
                private ?UserPermissions $permissions,
            ) {
                parent::__construct(['id' => 'manifest-destination-tests', 'basePath' => dirname(__DIR__, 2)]);
            }

            public function getAssets(): Assets
            {
                return $this->assets;
            }

            public function getVolumes(): Volumes
            {
                return $this->volumes;
            }

            public function getUserPermissions(): UserPermissions
            {
                return $this->permissions;
            }
        };
    }
}
