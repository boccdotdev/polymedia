<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\MediaItems;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for MediaItems lookup helpers that do not need a DB row.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class MediaItemsLookupTest extends TestCase
{
    // Public Methods
    // =========================================================================

    public function testGetByTypeAndProviderIdReturnsNullForEmptyArgs(): void
    {
        $service = new MediaItems();

        $this->assertNull($service->getByTypeAndProviderId('', 'abc'));
        $this->assertNull($service->getByTypeAndProviderId('mux', ''));
    }

    public function testGetByTypeAndProviderIdsReturnsEmptyForEmptyInput(): void
    {
        $service = new MediaItems();

        $this->assertSame([], $service->getByTypeAndProviderIds('mux', []));
        $this->assertSame([], $service->getByTypeAndProviderIds('', ['a']));
        $this->assertSame([], $service->getByTypeAndProviderIds('mux', ['', null]));
    }

    public function testGetByIdReturnsNullForInvalidId(): void
    {
        $service = new MediaItems();

        $this->assertNull($service->getById(0));
        $this->assertNull($service->getById(-1));
    }
}
