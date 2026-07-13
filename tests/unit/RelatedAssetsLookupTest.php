<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\RelatedAssets;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for RelatedAssets lookup helpers that do not need a DB row.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
class RelatedAssetsLookupTest extends TestCase
{
    // Public Methods
    // =========================================================================

    public function testGetForItemIdsReturnsEmptyForEmptyInput(): void
    {
        $service = new RelatedAssets();

        $this->assertSame([], $service->getForItemIds([]));
        $this->assertSame([], $service->getForItemIds([0, -1]));
    }
}
