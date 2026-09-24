<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\BunnySync;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class BunnyServiceTest extends TestCase
{
    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';
    private array $history = [];

    private function service(array $responses = []): Bunny
    {
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(Middleware::history($this->history));
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

    private function video(array $changes = []): array
    {
        return array_replace([
            'guid' => self::VIDEO, 'videoLibraryId' => 133, 'status' => 4,
            'title' => 'Demo', 'length' => 12.5, 'width' => 1920, 'height' => 1080,
            'thumbnailFileName' => 'thumbnail.jpg', 'availableResolutions' => '240p,360p,720p,1080p',
            'hasMP4Fallback' => true,
        ], $changes);
    }

    public function testMappingDoesNotInventPublicPolicyOrMp4(): void
    {
        $mapped = $this->service()->mapVideo($this->video());
        self::assertSame('133:' . self::VIDEO, $mapped['playbackId']);
        self::assertSame('ready', $mapped['status']);
        self::assertSame(12.5, $mapped['duration']);
        self::assertNull($mapped['isPublic']);
        self::assertNull($mapped['playbackPolicy']);
        self::assertSame([], $mapped['mp4Renditions']);
        self::assertStringEndsWith('/' . self::VIDEO . '/thumbnail.jpg', $mapped['thumbnailUrl']);
    }

    public function testForeignLibraryRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service()->mapVideo($this->video(['videoLibraryId' => 134]));
    }

    public function testAbsentLibraryRejected(): void
    {
        $video = $this->video();
        unset($video['videoLibraryId']);
        $this->expectException(\RuntimeException::class);
        $this->service()->mapVideo($video);
    }

    public function testHostAndIdentifierBoundaries(): void
    {
        foreach (['https://vz-example.b-cdn.net', 'localhost', '127.0.0.1', 'vz-example.b-cdn.net.evil.test', 'vz-example.b-cdn.net:443', 'evil@vz-example.b-cdn.net', 'vz-example.b-cdn.net/path'] as $host) {
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

    public function testDirectUploadHasSignedTusHeadersButNoApiKey(): void
    {
        $service = $this->service([new Response(200, [], json_encode($this->video()))]);
        $upload = $service->createDirectUpload('Title');
        self::assertSame(Bunny::TUS_ENDPOINT, $upload['uploadUrl']);
        self::assertSame(self::VIDEO, $upload['uploadId']);
        self::assertSame(hash('sha256', '133server-secret' . $upload['headers']['AuthorizationExpire'] . self::VIDEO), $upload['headers']['AuthorizationSignature']);
        self::assertStringNotContainsString('server-secret', json_encode($upload));
        self::assertSame('server-secret', $this->history[0]['request']->getHeaderLine('AccessKey'));
        self::assertSame('https://video.bunnycdn.com/library/133/videos', (string)$this->history[0]['request']->getUri());
        self::assertFalse($this->history[0]['options']['allow_redirects']);
    }

    public function testListSearchUsesBoundedNativePagination(): void
    {
        $service = $this->service([new Response(200, [], json_encode(['items' => [$this->video()], 'totalItems' => 101]))]);
        $list = $service->listAssets(500, -3, ' demo ');
        self::assertSame(100, $list['limit']);
        self::assertSame(1, $list['page']);
        self::assertTrue($list['hasMore']);
        parse_str($this->history[0]['request']->getUri()->getQuery(), $query);
        self::assertSame('demo', $query['search']);
    }

    public function testApiDoesNotFollowRedirectsOrExposeRemoteErrorBody(): void
    {
        $service = $this->service([new Response(302, ['Location' => 'https://evil.test'], 'server-secret')]);
        try {
            $service->getAsset(self::VIDEO);
            self::fail('API redirects must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Bunny Stream API request failed with HTTP 302.', $e->getMessage());
            self::assertCount(1, $this->history);
            self::assertFalse($this->history[0]['options']['allow_redirects']);
        }
    }

    public function testReadyMp4RequiresAnActualPublicMp4Response(): void
    {
        $service = $this->service([
            new Response(200, [], json_encode($this->video())),
            new Response(200, [], "#EXTM3U\n#EXT-X-STREAM-INF:BANDWIDTH=1000\n720p/video.m3u8\n"),
            new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
            new Response(404), // 240
            new Response(200, ['Content-Type' => 'text/html']), // Not a video.
            new Response(200, ['Content-Type' => 'video/mp4']), // 720
            new Response(403), // 1080 exists in HLS but isn't public MP4.
        ]);
        $asset = $service->getAsset(self::VIDEO, 'vz-old.b-cdn.net');
        self::assertTrue($asset['isPublic']);
        self::assertSame('public', $asset['playbackPolicy']);
        self::assertCount(1, $asset['mp4Renditions']);
        self::assertSame([
            'url' => 'https://vz-old.b-cdn.net/' . self::VIDEO . '/play_720p.mp4',
            'status' => 'ready', 'height' => 720, 'width' => 1280,
        ], $asset['mp4Renditions'][0]);
        foreach (array_slice($this->history, 1) as $request) {
            self::assertFalse($request['request']->hasHeader('AccessKey'));
            self::assertFalse($request['options']['allow_redirects']);
            self::assertSame('vz-old.b-cdn.net', $request['request']->getUri()->getHost());
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
            $asset = $this->service([new Response(200, [], json_encode($this->video())), $response])->getAsset(self::VIDEO);
            self::assertFalse($asset['isPublic']);
            self::assertSame([], $asset['mp4Renditions']);
        }
    }

    public function testTransientPlaybackFailureThrowsInsteadOfClearingState(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->service([new Response(200, [], json_encode($this->video())), new Response(503)])->getAsset(self::VIDEO);
    }

    public function testCdnPropagationDelayKeepsUploadPolling(): void
    {
        $upload = $this->service([new Response(200, [], json_encode($this->video())), new Response(404)])->getUpload(self::VIDEO);
        self::assertFalse($upload['ready']);
        self::assertFalse($upload['failed']);
        self::assertNull($upload['playbackPolicy']);
    }

    public function testApiStatusesAreDistinctFromWebhookNotificationCodes(): void
    {
        foreach ([0, 1, 2, 3, 7] as $status) {
            self::assertSame('preparing', $this->service()->mapVideo($this->video(['status' => $status]))['status']);
        }
        foreach ([5, 6] as $status) {
            self::assertSame('errored', $this->service()->mapVideo($this->video(['status' => $status]))['status']);
        }
        foreach ([4, 8] as $status) {
            self::assertSame('ready', $this->service()->mapVideo($this->video(['status' => $status]))['status']);
        }
        foreach ([9, 10] as $status) {
            self::assertSame('unknown', $this->service()->mapVideo($this->video(['status' => $status]))['status']);
        }
    }

    public function testFailedUploadStopsPollingAndMissingVideoHasDeletedState(): void
    {
        $failed = $this->service([new Response(200, [], json_encode($this->video(['status' => 6])))])->getUpload(self::VIDEO);
        self::assertTrue($failed['failed']);
        self::assertFalse($failed['ready']);
        $missing = $this->service([new Response(404)])->getAsset(self::VIDEO);
        self::assertSame('deleted', $missing['status']);
        self::assertSame(-1, $missing['bunnyStatus']);
        self::assertSame([], $missing['mp4Renditions']);
    }

    public function testNoMp4EncodingAndUploadPollingDoNotProbeMp4Files(): void
    {
        $service = $this->service([
            new Response(200, [], json_encode($this->video(['hasMP4Fallback' => false]))),
            new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
            new Response(200, [], json_encode($this->video(['status' => 8]))),
        ]);
        self::assertSame([], $service->getAsset(self::VIDEO)['mp4Renditions']);
        self::assertTrue($service->getUpload(self::VIDEO)['ready']);
        self::assertCount(3, $this->history, 'Reuse the verified HLS probe within a request and do not HEAD MP4s during polling.');
    }

    public function testMp4ProbeFailureDoesNotBlockHlsOrEraseKnownRenditions(): void
    {
        $state = $this->service([
            new Response(200, [], json_encode($this->video(['availableResolutions' => '480p']))),
            new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
            new Response(503),
        ])->getAsset(self::VIDEO);
        self::assertTrue($state['isPublic']);
        self::assertArrayNotHasKey('mp4Renditions', $state);
        $metadata = [
            'bunnyLibraryId' => '133', 'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'mp4Renditions' => [['url' => 'https://known.test/video.mp4', 'status' => 'ready']],
        ];
        $merged = BunnySync::mergeState($metadata, 10, $state);
        self::assertSame($metadata['mp4Renditions'], $merged['metadata']['mp4Renditions']);
        self::assertSame($state['thumbnailUrl'], $merged['metadata']['thumbnail']);
    }

    public function test480pAndSingleHighResolutionMp4sAreVerified(): void
    {
        foreach ([480, 2160] as $height) {
            $state = $this->service([
                new Response(200, [], json_encode($this->video(['availableResolutions' => "{$height}p"]))),
                new Response(200, [], "#EXTM3U\n#EXTINF:10,\nsegment.ts\n"),
                new Response(200, ['Content-Type' => 'video/mp4']),
            ])->getAsset(self::VIDEO);
            self::assertCount(1, $state['mp4Renditions']);
            self::assertSame($height, $state['mp4Renditions'][0]['height']);
            self::assertStringEndsWith("/play_{$height}p.mp4", $state['mp4Renditions'][0]['url']);
        }
    }

    public function testWebhookUsesExactBodyAndStrictVersionAlgorithmAndHex(): void
    {
        $body = '{"VideoLibraryId":133,"VideoGuid":"' . self::VIDEO . '","Status":3}';
        $signature = hash_hmac('sha256', $body, 'read-only-key');
        self::assertTrue(Bunny::verifyWebhookSignature($body, $signature, 'v1', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body . "\n", $signature, 'v1', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, strtoupper($signature), 'v1', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, $signature, 'v2', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, $signature, 'v1', 'sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, $signature, 'v1', 'hmac-sha256', ''));
    }

    public function testSyncPreservesEditorMetadataAndIsIdempotent(): void
    {
        $state = $this->service()->mapVideo($this->video());
        $metadata = [
            'bunnyLibraryId' => '133', 'bunnyVideoId' => self::VIDEO,
            'bunnyCdnHostname' => 'vz-example.b-cdn.net',
            'thumbnail' => 'https://editor.test/poster.jpg',
            'title' => 'Editor title', 'custom' => ['kept' => true],
        ];
        $first = BunnySync::mergeState($metadata, null, $state);
        $second = BunnySync::mergeState($first['metadata'], $first['duration'], $state);
        self::assertSame($first, $second);
        self::assertSame(13, $first['duration']);
        self::assertSame($metadata['thumbnail'], $first['metadata']['thumbnail']);
        self::assertSame($metadata['title'], $first['metadata']['title']);
        self::assertSame($metadata['custom'], $first['metadata']['custom']);
        $state['bunnyCdnHostname'] = 'vz-new.b-cdn.net';
        $this->expectException(\RuntimeException::class);
        BunnySync::mergeState($metadata, 13, $state);
    }
}
