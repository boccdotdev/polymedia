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
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\MediaItems;
use Craft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Remote Bunny deletion is opt-in, Pro-only and bound to the video's library.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyDeletionTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';

    // Private Properties
    // =========================================================================

    private mixed $_previousApp;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        Craft::$app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public static function cases(): array
    {
        return [
            'deletes with matching library' => [true, true, 'bunny', '133', true],
            'setting off' => [false, true, 'bunny', '133', false],
            'lite or unconfigured' => [true, false, 'bunny', '133', false],
            'another library' => [true, true, 'bunny', '134', false],
            'not a bunny item' => [true, true, 'mux', '133', false],
        ];
    }

    #[DataProvider('cases')]
    public function testHardDeleteDecision(bool $setting, bool $enabled, string $type, string $library, bool $deletes): void
    {
        $bunny = $this->createMock(Bunny::class);
        $bunny->method('getLibraryId')->willReturn('133');
        $bunny->expects($deletes ? self::once() : self::never())->method('deleteAsset')->with(self::VIDEO);

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getBunny', 'getBunnyEnabled', 'getMediaItems'])->getMock();
        $plugin->method('getSettings')->willReturn(new Settings(['deleteBunnyAssetOnDelete' => $setting]));
        $plugin->method('getBunny')->willReturn($bunny);
        $plugin->method('getBunnyEnabled')->willReturn($enabled);
        $plugin->method('getMediaItems')->willReturn(new MediaItems());

        $record = $this->createMock(MediaItemRecord::class);
        $record->method('__get')->willReturnCallback(fn(string $name) => match ($name) {
            'type' => $type,
            'assetId' => 70,
            'metadata' => json_encode(['bunnyLibraryId' => $library, 'bunnyVideoId' => self::VIDEO]),
            default => null,
        });

        (new \ReflectionMethod(Plugin::class, '_maybeDeleteBunnyAsset'))->invoke($plugin, $record);
    }

    public function testRemoteFailureDoesNotBlockLocalCleanup(): void
    {
        $bunny = $this->createMock(Bunny::class);
        $bunny->method('getLibraryId')->willReturn('133');
        $bunny->method('deleteAsset')->willThrowException(new \RuntimeException('Bunny is down'));

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getSettings', 'getBunny', 'getBunnyEnabled', 'getMediaItems'])->getMock();
        $plugin->method('getSettings')->willReturn(new Settings(['deleteBunnyAssetOnDelete' => true]));
        $plugin->method('getBunny')->willReturn($bunny);
        $plugin->method('getBunnyEnabled')->willReturn(true);
        $plugin->method('getMediaItems')->willReturn(new MediaItems());

        $record = $this->createMock(MediaItemRecord::class);
        $record->method('__get')->willReturnCallback(fn(string $name) => match ($name) {
            'type' => 'bunny',
            'metadata' => json_encode(['bunnyLibraryId' => '133', 'bunnyVideoId' => self::VIDEO]),
            default => 70,
        });

        (new \ReflectionMethod(Plugin::class, '_maybeDeleteBunnyAsset'))->invoke($plugin, $record);
        self::assertTrue(true, 'No exception escaped.');
    }

    public function testDeleteRequestTargetsTheVideo(): void
    {
        $history = [];
        $stack = \GuzzleHttp\HandlerStack::create(new \GuzzleHttp\Handler\MockHandler([new \GuzzleHttp\Psr7\Response(200)]));
        $stack->push(\GuzzleHttp\Middleware::history($history));
        $service = new class(['client' => new \GuzzleHttp\Client(['handler' => $stack])]) extends Bunny {
            public function isConfigured(): bool
            {
                return true;
            }

            public function getLibraryId(): string
            {
                return '133';
            }

            protected function getApiKey(): string
            {
                return 'server-secret';
            }
        };

        $service->deleteAsset(self::VIDEO);
        self::assertSame('DELETE', $history[0]['request']->getMethod());
        self::assertSame('/library/133/videos/' . self::VIDEO, $history[0]['request']->getUri()->getPath());
    }
}
