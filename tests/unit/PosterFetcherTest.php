<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\PosterFetcher;
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
}
