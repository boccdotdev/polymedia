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

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->_fetcher = new PosterFetcher();
    }

    public function testBackoffSecondsGrowsThenCaps(): void
    {
        $this->assertSame(15, $this->_fetcher->backoffSeconds(0));
        $this->assertSame(30, $this->_fetcher->backoffSeconds(1));
        $this->assertSame(60, $this->_fetcher->backoffSeconds(2));
        $this->assertSame(600, $this->_fetcher->backoffSeconds(10));
    }
}
