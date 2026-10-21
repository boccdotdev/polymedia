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
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\PosterFetcher;
use boccdotdev\polymedia\services\RelatedAssets;
use Craft;
use craft\elements\Asset;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A Bunny poster Polymedia fetched follows thumbnail changes in Bunny; an
 * editor's poster never does.
 *
 * @author boccdotdev
 * @since 2.5.1
 */
class BunnyPosterRefreshTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';
    private const HOST = 'https://vz-example.b-cdn.net/657bb740-a71b-4529-a012-528021c31a92/';

    // Private Properties
    // =========================================================================

    private mixed $_previousApp;
    private MediaItemRecord $_record;
    private ?Asset $_poster = null;
    private array $_metadata = [];
    private ?array $_probe = null;
    private array $_queued = [];
    private int $_probes = 0;

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
        $this->_poster = $this->_asset(5);
        $this->_metadata = [
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'bunnyThumbnailUrl' => self::HOST . 'thumbnail.jpg',
            'posterSource' => [
                'assetId' => 5,
                'url' => self::HOST . 'thumbnail.jpg',
                'etag' => '"v1"',
                'lastModified' => 'Mon, 19 Oct 2026 10:00:00 GMT',
            ],
        ];

        $related = $this->createMock(RelatedAssets::class);
        $related->method('getPoster')->willReturnCallback(fn() => $this->_poster);
        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getMediaItems', 'getRelatedAssets'])->getMock();
        $plugin->method('getMediaItems')->willReturn($this->getMockBuilder(MediaItems::class)->onlyMethods(['getById'])->getMock());
        $plugin->method('getRelatedAssets')->willReturn($related);
        Craft::$app->loadedModules[Plugin::class] = $plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
    }

    public function testFirstFetchWhenThereIsNoPoster(): void
    {
        $this->_poster = null;

        self::assertSame([[0, null]], $this->_ensure());
    }

    public function testNewThumbnailFileRefreshesThePolymediaPoster(): void
    {
        $this->_metadata['bunnyThumbnailUrl'] = self::HOST . 'thumbnail_3.jpg';

        self::assertSame([[0, 5]], $this->_ensure());
        self::assertSame(0, $this->_probes, 'A changed URL needs no request.');
    }

    public function testUnchangedThumbnailIsLeftAlone(): void
    {
        self::assertSame([], $this->_ensure());
        self::assertSame(0, $this->_probes, 'Only the console sync asks the CDN.');
    }

    public static function untouchable(): array
    {
        return [
            'editor replaced the poster' => [fn(self $test) => $test->_poster = $test->_asset(9)],
            'fetched before 2.5.1' => [fn(self $test) => $test->_metadata['posterSource'] = null],
            'thumbnail on another host' => [fn(self $test) => $test->_metadata['bunnyThumbnailUrl'] = 'https://vz-other.b-cdn.net/' . self::VIDEO . '/thumbnail_3.jpg'],
            'no thumbnail yet' => [fn(self $test) => $test->_metadata['bunnyThumbnailUrl'] = null],
        ];
    }

    #[DataProvider('untouchable')]
    public function testPostersPolymediaDoesNotOwnAreNeverRefreshed(callable $arrange): void
    {
        $this->_metadata['bunnyThumbnailUrl'] = self::HOST . 'thumbnail_3.jpg';
        $arrange($this);

        self::assertSame([], $this->_ensure(checkRemote: true));
    }

    public static function remoteChecks(): array
    {
        return [
            'same ETag' => [['status' => 200, 'etag' => '"v1"', 'lastModified' => 'Wed, 21 Oct 2026 10:00:00 GMT'], false],
            'new ETag' => [['status' => 200, 'etag' => '"v2"', 'lastModified' => null], true],
            'ranged GET' => [['status' => 206, 'etag' => '"v2"', 'lastModified' => null], true],
            'only Last-Modified' => [['status' => 200, 'etag' => null, 'lastModified' => 'Wed, 21 Oct 2026 10:00:00 GMT'], true],
            'no validators' => [['status' => 200, 'etag' => null, 'lastModified' => null], false],
            'not found' => [['status' => 404, 'etag' => '"v2"', 'lastModified' => null], false],
            'network error' => [null, false],
        ];
    }

    #[DataProvider('remoteChecks')]
    public function testConsoleSyncNoticesAThumbnailReplacedInPlace(?array $probe, bool $refresh): void
    {
        $this->_probe = $probe;

        self::assertSame($refresh ? [[0, 5]] : [], $this->_ensure(checkRemote: true));
        self::assertSame(1, $this->_probes);
    }

    public function testThumbnailFollowsBunnyUntilSomeoneElseSetsIt(): void
    {
        $metadata = [
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'bunnyThumbnailUrl' => self::HOST . 'thumbnail.jpg',
            'thumbnail' => self::HOST . 'thumbnail.jpg',
        ];
        $state = [
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'thumbnailUrl' => self::HOST . 'thumbnail_3.jpg',
        ];

        $merged = MediaItems::mergeBunnyVideoState($metadata, null, $state)['metadata'];
        self::assertSame(self::HOST . 'thumbnail_3.jpg', $merged['thumbnail']);

        $metadata['thumbnail'] = 'https://example.test/chosen.jpg';
        $merged = MediaItems::mergeBunnyVideoState($metadata, null, $state)['metadata'];
        self::assertSame('https://example.test/chosen.jpg', $merged['thumbnail']);

        $state['thumbnailUrl'] = null;
        $metadata['thumbnail'] = self::HOST . 'thumbnail.jpg';
        $merged = MediaItems::mergeBunnyVideoState($metadata, null, $state)['metadata'];
        self::assertSame(self::HOST . 'thumbnail.jpg', $merged['thumbnail'], 'A missing thumbnail keeps the last one.');
    }

    // Private Methods
    // =========================================================================

    /**
     * Runs ensureBunnyPoster() and returns each queued [attempt, replacePosterId].
     */
    private function _ensure(bool $checkRemote = false): array
    {
        $this->_record->metadata = json_encode($this->_metadata);
        $fetcher = $this->getMockBuilder(PosterFetcher::class)->onlyMethods(['queueBunnyPoster', 'probeRemote'])->getMock();
        $fetcher->method('queueBunnyPoster')->willReturnCallback(function(MediaItemRecord $record, int $attempt = 0, ?int $replacePosterId = null): void {
            $this->_queued[] = [$attempt, $replacePosterId];
        });
        $fetcher->method('probeRemote')->willReturnCallback(function(string $url): ?array {
            self::assertSame(self::HOST . 'thumbnail.jpg', $url);
            $this->_probes++;

            return $this->_probe;
        });

        $fetcher->ensureBunnyPoster($this->_record, $checkRemote);

        return $this->_queued;
    }

    /**
     * Returns an asset with an ID.
     */
    private function _asset(int $id): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = $id;

        return $asset;
    }
}
