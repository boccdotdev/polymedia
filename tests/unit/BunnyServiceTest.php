<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\MediaItems;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * Bunny Stream API client against a mocked transport. Never hits Bunny.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyServiceTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';

    // Private Properties
    // =========================================================================

    /**
     * @var array Guzzle request history
     */
    private array $_history = [];

    // Public Methods
    // =========================================================================

    public function testMappingDoesNotInventPublicPolicyOrMp4(): void
    {
        $mapped = $this->_service()->mapVideo($this->_video());

        self::assertSame('133:' . self::VIDEO, $mapped['playbackId']);
        self::assertSame('ready', $mapped['status']);
        self::assertSame(12.5, $mapped['duration']);
        self::assertSame(1920, $mapped['width']);
        self::assertNull($mapped['isPublic']);
        self::assertNull($mapped['playbackPolicy']);
        self::assertSame([], $mapped['mp4Renditions']);
        self::assertSame('https://vz-example.b-cdn.net/' . self::VIDEO . '/playlist.m3u8', $mapped['url']);
        self::assertStringEndsWith('/' . self::VIDEO . '/thumbnail.jpg', $mapped['thumbnailUrl']);
    }

    public function testForeignOrMissingLibraryRejected(): void
    {
        foreach ([['videoLibraryId' => 134], ['videoLibraryId' => null]] as $changes) {
            try {
                $this->_service()->mapVideo(array_filter($this->_video($changes), static fn($v) => $v !== null));
                self::fail('Accepted a video from another library.');
            } catch (\RuntimeException) {
                self::assertTrue(true);
            }
        }
    }

    public function testHostAndIdentifierBoundaries(): void
    {
        foreach (['https://vz-example.b-cdn.net', 'localhost', '127.0.0.1', 'vz-example.b-cdn.net.evil.test', 'vz-example.b-cdn.net:443', 'evil@vz-example.b-cdn.net', 'vz-example.b-cdn.net/path', "vz-example.b-cdn.net\n"] as $host) {
            try {
                Bunny::validateHostname($host);
                self::fail("Accepted invalid host {$host}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        foreach (['0', '-1', '00133', '133/../1', '133?key=x'] as $id) {
            try {
                Bunny::validateLibraryId($id);
                self::fail("Accepted invalid library {$id}");
            } catch (\InvalidArgumentException) {
                self::assertTrue(true);
            }
        }

        self::assertNull(Bunny::thumbnailUrl('vz-example.b-cdn.net', self::VIDEO, '../thumbnail.jpg'));
        $this->expectException(\InvalidArgumentException::class);
        Bunny::validateVideoId(self::VIDEO . '?key=x');
    }

    public function testListSearchUsesBoundedNativePagination(): void
    {
        $service = $this->_service([new Response(200, [], json_encode(['items' => [$this->_video()], 'totalItems' => 101]))]);
        $list = $service->listAssets(500, -3, ' demo ');

        self::assertSame(100, $list['limit']);
        self::assertSame(1, $list['page']);
        self::assertTrue($list['hasMore']);
        self::assertSame('server-secret', $this->_history[0]['request']->getHeaderLine('AccessKey'));
        self::assertSame('video.bunnycdn.com', $this->_history[0]['request']->getUri()->getHost());
        self::assertSame('/library/133/videos', $this->_history[0]['request']->getUri()->getPath());
        parse_str($this->_history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('demo', $query['search']);
    }

    public function testApiDoesNotFollowRedirectsOrExposeRemoteErrorBody(): void
    {
        $service = $this->_service([new Response(302, ['Location' => 'https://evil.test'], 'server-secret')]);

        try {
            $service->getAsset(self::VIDEO);
            self::fail('API redirects must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Bunny Stream API request failed with HTTP 302.', $e->getMessage());
            self::assertCount(1, $this->_history);
            self::assertFalse($this->_history[0]['options']['allow_redirects']);
        }
    }

    public function testReadyMp4RequiresAnActualPublicMp4Response(): void
    {
        $service = $this->_service([
            new Response(200, [], json_encode($this->_video())),
            new Response(200, [], "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000\n720p/video.m3u8\n"),
            new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
            new Response(404), // 240
            new Response(200, ['Content-Type' => 'text/html']), // 360: not a video
            new Response(200, ['Content-Type' => 'video/mp4']), // 720
            new Response(403), // 1080: in HLS but not a public MP4
        ]);
        $asset = $service->getAsset(self::VIDEO, 'vz-old.b-cdn.net');

        self::assertTrue($asset['isPublic']);
        self::assertSame('public', $asset['playbackPolicy']);
        self::assertSame([[
            'url' => 'https://vz-old.b-cdn.net/' . self::VIDEO . '/play_720p.mp4',
            'status' => 'ready',
            'height' => 720,
            'width' => 1280,
        ]], $asset['mp4Renditions']);

        foreach (array_slice($this->_history, 1) as $request) {
            self::assertFalse($request['request']->hasHeader('AccessKey'));
            self::assertFalse($request['options']['allow_redirects']);
            self::assertSame('vz-old.b-cdn.net', $request['request']->getUri()->getHost(), 'Probe the stored origin.');
        }
    }

    public function testProtectedOrRedirectedHlsIsNotPublic(): void
    {
        foreach ([
            new Response(403),
            new Response(302, ['Location' => 'http://127.0.0.1/']),
            new Response(200, [], "#EXTM3U\n#EXT-X-KEY:METHOD=SAMPLE-AES,URI=\"key\"\n#EXTINF:10,\nsegment.ts\n"),
            new Response(200, [], "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000\nhttps://evil.test/video.m3u8\n"),
        ] as $response) {
            $asset = $this->_service([new Response(200, [], json_encode($this->_video())), $response])->getAsset(self::VIDEO);
            self::assertFalse($asset['isPublic']);
            self::assertSame('unsupported', $asset['playbackPolicy']);
            self::assertSame([], $asset['mp4Renditions']);
        }
    }

    public function testTransientPlaybackFailureThrowsInsteadOfClearingState(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->_service([new Response(200, [], json_encode($this->_video())), new Response(503)])->getAsset(self::VIDEO);
    }

    public function testCdnPropagationDelayLeavesPolicyUnknown(): void
    {
        $asset = $this->_service([new Response(200, [], json_encode($this->_video())), new Response(404)])->getAsset(self::VIDEO);

        self::assertNull($asset['isPublic']);
        self::assertNull($asset['playbackPolicy']);
    }

    public function testApiStatusesAreDistinctFromWebhookNotificationCodes(): void
    {
        $expected = [0 => 'preparing', 1 => 'preparing', 2 => 'preparing', 3 => 'preparing', 7 => 'preparing',
            5 => 'errored', 6 => 'errored', 4 => 'ready', 8 => 'ready', 9 => 'unknown', 10 => 'unknown', ];

        foreach ($expected as $status => $mapped) {
            self::assertSame($mapped, $this->_service()->mapVideo($this->_video(['status' => $status]))['status']);
        }
    }

    public function testMissingVideoMapsToDeleted(): void
    {
        $missing = $this->_service([new Response(404)])->getAsset(self::VIDEO);

        self::assertSame('deleted', $missing['status']);
        self::assertSame(-1, $missing['bunnyStatus']);
        self::assertSame([], $missing['mp4Renditions']);
    }

    public function testNoMp4FallbackSkipsFileChecksAndReusesTheProbe(): void
    {
        $service = $this->_service([
            new Response(200, [], json_encode($this->_video(['hasMP4Fallback' => false]))),
            new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
            new Response(200, [], json_encode($this->_video(['status' => 8]))),
        ]);

        self::assertSame([], $service->getAsset(self::VIDEO)['mp4Renditions']);
        self::assertArrayNotHasKey('mp4Renditions', $service->getAsset(self::VIDEO, discoverMp4: false));
        self::assertCount(3, $this->_history, 'Reuse the verified HLS probe within a request.');
    }

    public function testMp4ProbeFailureDoesNotBlockHlsOrEraseKnownRenditions(): void
    {
        $state = $this->_service([
            new Response(200, [], json_encode($this->_video(['availableResolutions' => '480p']))),
            new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
            new Response(503),
        ])->getAsset(self::VIDEO);

        self::assertTrue($state['isPublic']);
        self::assertArrayNotHasKey('mp4Renditions', $state);

        $metadata = [
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'mp4Renditions' => [['url' => 'https://known.test/video.mp4', 'status' => 'ready']],
        ];
        $merged = MediaItems::mergeBunnyVideoState($metadata, 10, $state);

        self::assertSame($metadata['mp4Renditions'], $merged['metadata']['mp4Renditions']);
        self::assertSame($state['thumbnailUrl'], $merged['metadata']['thumbnail']);
    }

    public function testSingleResolutionMp4sAreVerified(): void
    {
        foreach ([480, 2160] as $height) {
            $state = $this->_service([
                new Response(200, [], json_encode($this->_video(['availableResolutions' => "{$height}p"]))),
                new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
                new Response(200, ['Content-Type' => 'video/mp4']),
            ])->getAsset(self::VIDEO);

            self::assertCount(1, $state['mp4Renditions']);
            self::assertSame($height, $state['mp4Renditions'][0]['height']);
            self::assertStringEndsWith("/play_{$height}p.mp4", $state['mp4Renditions'][0]['url']);
        }
    }

    public function testMergePreservesEditorMetadataAndIsIdempotent(): void
    {
        $state = $this->_service()->mapVideo($this->_video());
        $metadata = [
            'bunnyLibraryId' => '133',
            'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'thumbnail' => 'https://editor.test/poster.jpg',
            'title' => 'Editor title',
            'custom' => ['kept' => true],
        ];
        $first = MediaItems::mergeBunnyVideoState($metadata, null, $state);
        $second = MediaItems::mergeBunnyVideoState($first['metadata'], $first['duration'], $state);

        self::assertTrue($first['changed']);
        self::assertFalse($second['changed']);
        self::assertSame($first['metadata'], $second['metadata']);
        self::assertSame(13, $first['duration']);
        self::assertSame($metadata['thumbnail'], $first['metadata']['thumbnail']);
        self::assertSame($metadata['title'], $first['metadata']['title']);
        self::assertSame($metadata['custom'], $first['metadata']['custom']);

        $state['bunnyCdnHostname'] = 'vz-new.b-cdn.net';
        $this->expectException(\RuntimeException::class);
        MediaItems::mergeBunnyVideoState($metadata, 13, $state);
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a configured service over a mocked transport.
     */
    private function _service(array $responses = []): Bunny
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->_history));

        return new class(['client' => new Client(['handler' => $stack])]) extends Bunny {
            public function getLibraryId(): string
            {
                return '133';
            }

            public function getCdnHostname(): string
            {
                return 'vz-example.b-cdn.net';
            }

            protected function getApiKey(): string
            {
                return 'server-secret';
            }
        };
    }

    /**
     * Returns an API video object.
     */
    private function _video(array $changes = []): array
    {
        return array_replace([
            'guid' => self::VIDEO,
            'videoLibraryId' => 133,
            'status' => 4,
            'title' => 'Demo',
            'length' => 12.5,
            'width' => 1920,
            'height' => 1080,
            'thumbnailFileName' => 'thumbnail.jpg',
            'availableResolutions' => '240p,360p,720p,1080p',
            'hasMP4Fallback' => true,
        ], $changes);
    }
}
