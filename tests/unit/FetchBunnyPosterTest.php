<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\jobs\FetchBunnyPoster;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\PosterFetcher;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\SidecarStorage;
use Craft;
use craft\elements\Asset;
use craft\models\Volume;
use PHPUnit\Framework\TestCase;

/**
 * The Bunny poster job fetches only the item's own thumbnail and retries.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class FetchBunnyPosterTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';
    private const THUMB = 'https://vz-example.b-cdn.net/657bb740-a71b-4529-a012-528021c31a92/thumbnail.jpg';

    // Private Properties
    // =========================================================================

    private mixed $_previousApp;
    private PosterFetcher $_posters;
    private MediaItemRecord $_record;
    private ?Asset $_existingPoster = null;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        $this->_previousApp = Craft::$app;
        Craft::$app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();

        $this->_record = new class() extends MediaItemRecord {
            public $id = 7;
            public $type = 'bunny';
            public $providerId = '133:657bb740-a71b-4529-a012-528021c31a92';
            public $metadata;
        };
        $this->_metadata(self::THUMB);

        $items = $this->getMockBuilder(MediaItems::class)->onlyMethods(['getById'])->getMock();
        $items->method('getById')->willReturnCallback(fn() => $this->_record);
        $related = $this->createMock(RelatedAssets::class);
        $related->method('getPoster')->willReturnCallback(fn() => $this->_existingPoster);
        $sidecars = $this->createMock(SidecarStorage::class);
        $sidecars->method('getVolume')->willReturn($this->createMock(Volume::class));
        $this->_posters = $this->createMock(PosterFetcher::class);

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getMediaItems', 'getRelatedAssets', 'getSidecarStorage', 'getPosterFetcher'])->getMock();
        $plugin->method('getMediaItems')->willReturn($items);
        $plugin->method('getRelatedAssets')->willReturn($related);
        $plugin->method('getSidecarStorage')->willReturn($sidecars);
        $plugin->method('getPosterFetcher')->willReturn($this->_posters);
        Craft::$app->loadedModules[Plugin::class] = $plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public function testFetchesTheThumbnailIntoSidecarStorage(): void
    {
        $this->_posters->expects(self::once())->method('fetchForItem')
            ->with($this->_record, self::THUMB, false, null)->willReturn($this->createMock(Asset::class));
        $this->_posters->expects(self::never())->method('queueBunnyPoster');

        $this->_job()->execute($this->createMock(\craft\queue\Queue::class));
    }

    public function testRetriesWithTheNextAttempt(): void
    {
        $this->_posters->method('fetchForItem')->willReturn(null);
        $this->_posters->expects(self::once())->method('queueBunnyPoster')->with($this->_record, 3, null);

        $this->_job(2)->execute($this->createMock(\craft\queue\Queue::class));
    }

    public function testGivesUpAfterTheLastAttempt(): void
    {
        $this->_posters->method('fetchForItem')->willReturn(null);
        $this->_posters->expects(self::never())->method('queueBunnyPoster');

        $this->_job(7)->execute($this->createMock(\craft\queue\Queue::class));
    }

    public function testSkipsForeignUrlsChangedItemsAndExistingPosters(): void
    {
        $this->_posters->expects(self::never())->method('fetchForItem');

        $this->_metadata('https://evil.test/' . self::VIDEO . '/thumbnail.jpg');
        $this->_job()->execute($this->createMock(\craft\queue\Queue::class));

        $this->_metadata(self::THUMB);
        $job = $this->_job();
        $job->providerId = '133:other';
        $job->execute($this->createMock(\craft\queue\Queue::class));

        $this->_existingPoster = $this->createMock(Asset::class);
        $this->_job()->execute($this->createMock(\craft\queue\Queue::class));
    }

    public function testRefreshReplacesThePosterItWasQueuedFor(): void
    {
        $this->_existingPoster = $this->_poster(5);
        $this->_posters->expects(self::once())->method('fetchForItem')
            ->with($this->_record, self::THUMB, true, 5)->willReturn($this->_poster(6));

        $this->_job(replacePosterId: 5)->execute($this->createMock(\craft\queue\Queue::class));
    }

    public function testRefreshSkipsAPosterThatChangedOrWentAway(): void
    {
        $this->_posters->expects(self::never())->method('fetchForItem');

        $this->_existingPoster = $this->_poster(9);
        $this->_job(replacePosterId: 5)->execute($this->createMock(\craft\queue\Queue::class));

        $this->_existingPoster = null;
        $this->_job(replacePosterId: 5)->execute($this->createMock(\craft\queue\Queue::class));
    }

    public function testRefreshRetriesForTheSamePoster(): void
    {
        $this->_existingPoster = $this->_poster(5);
        $this->_posters->method('fetchForItem')->willReturn(null);
        $this->_posters->expects(self::once())->method('queueBunnyPoster')->with($this->_record, 1, 5);

        $this->_job(replacePosterId: 5)->execute($this->createMock(\craft\queue\Queue::class));
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a job for the fixture item.
     */
    private function _job(int $attempt = 0, ?int $replacePosterId = null): FetchBunnyPoster
    {
        return new FetchBunnyPoster([
            'itemId' => 7,
            'providerId' => '133:' . self::VIDEO,
            'attempt' => $attempt,
            'replacePosterId' => $replacePosterId,
        ]);
    }

    /**
     * Returns a poster asset with an ID.
     */
    private function _poster(int $id): Asset
    {
        $poster = $this->createMock(Asset::class);
        $poster->id = $id;

        return $poster;
    }

    /**
     * Stores the fixture metadata with a thumbnail URL.
     */
    private function _metadata(string $thumbnail): void
    {
        $this->_record->metadata = json_encode([
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'bunnyThumbnailUrl' => $thumbnail,
        ]);
    }
}
