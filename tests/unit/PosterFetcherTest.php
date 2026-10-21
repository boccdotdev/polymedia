<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\PosterFetcher;
use boccdotdev\polymedia\services\ThumbnailDeriver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for PosterFetcher helpers that do not require Craft bootstrap.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class PosterFetcherTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var PosterFetcher
     */
    private PosterFetcher $_fetcher;

    /**
     * @var mixed
     */
    private mixed $_previousApp;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->_fetcher = new PosterFetcher();
        // Slugs read the app language.
        $this->_previousApp = \Craft::$app;
        new \yii\console\Application([
            'id' => 'poster-fetcher-tests',
            'basePath' => dirname(__DIR__, 2),
            'language' => 'en',
        ]);
    }

    protected function tearDown(): void
    {
        \Craft::$app = $this->_previousApp;
        parent::tearDown();
    }

    public function testBackoffSecondsGrowsThenCaps(): void
    {
        $this->assertSame(15, $this->_fetcher->backoffSeconds(0));
        $this->assertSame(30, $this->_fetcher->backoffSeconds(1));
        $this->assertSame(60, $this->_fetcher->backoffSeconds(2));
        $this->assertSame(600, $this->_fetcher->backoffSeconds(10));
    }

    public function testShouldSkipAutoFetchWhenPosterExists(): void
    {
        // Asset is final; use a simple mock without Craft bootstrap.
        $poster = $this->createMock(\craft\elements\Asset::class);

        $this->assertTrue($this->_fetcher->shouldSkipAutoFetch($poster, false));
        $this->assertFalse($this->_fetcher->shouldSkipAutoFetch($poster, true));
        $this->assertFalse($this->_fetcher->shouldSkipAutoFetch(null, false));
    }

    public function testPosterFilenameChangesWithTheImage(): void
    {
        $black = tempnam(sys_get_temp_dir(), 'pm');
        $frame = tempnam(sys_get_temp_dir(), 'pm');
        file_put_contents($black, 'black frame');
        file_put_contents($frame, 'frame at four seconds');

        try {
            $first = $this->_fetcher->posterFilename('Holding Reel', $black, 'jpg');

            $this->assertMatchesRegularExpression('/^holding-reel-poster-[0-9a-f]{8}\.jpg$/', $first);
            $this->assertSame($first, $this->_fetcher->posterFilename('Holding Reel', $black, 'jpg'));
            $this->assertNotSame($first, $this->_fetcher->posterFilename('Holding Reel', $frame, 'jpg'));
        } finally {
            @unlink($black);
            @unlink($frame);
        }
    }

    public function testPosterFilenameFallsBackWithoutTitleOrFile(): void
    {
        $this->assertSame('poster-poster.png', $this->_fetcher->posterFilename('', '/nonexistent/polymedia', 'png'));
    }

    public static function remoteUrls(): array
    {
        $mux = 'https://image.mux.com/abc123';

        return [
            'stored Mux time wins' => ['mux', 'abc123', "{$mux}/thumbnail.jpg?time=4", "{$mux}/thumbnail.jpg?time=4"],
            'old first-frame default is derived again' => ['mux', 'abc123', "{$mux}/thumbnail.jpg?time=0", "{$mux}/thumbnail.jpg"],
            'derived when nothing is stored' => ['youtube', 'dQw4w9WgXcQ', null, 'https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg'],
            'stored YouTube fallback wins' => ['youtube', 'dQw4w9WgXcQ', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg'],
            'stored when nothing derives' => ['bunny', '133:abc', 'https://vz-example.b-cdn.net/abc/thumbnail.jpg', 'https://vz-example.b-cdn.net/abc/thumbnail.jpg'],
            'nothing' => ['hls', '', null, null],
        ];
    }

    #[DataProvider('remoteUrls')]
    public function testDeriveRemoteUrlPrefersTheStoredThumbnail(string $type, string $providerId, ?string $stored, ?string $expected): void
    {
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getThumbnailDeriver'])->getMock();
        $plugin->method('getThumbnailDeriver')->willReturn(new ThumbnailDeriver());
        \Craft::$app->loadedModules[Plugin::class] = $plugin;

        $record = new class() extends MediaItemRecord {
            public $type;
            public $providerId;
            public $url = '';
            public $element = '';
            public $metadata;
        };
        $record->type = $type;
        $record->providerId = $providerId;
        $record->metadata = json_encode($stored !== null ? ['thumbnail' => $stored] : []);

        $this->assertSame($expected, $this->_fetcher->deriveRemoteUrl($record));
    }
}
