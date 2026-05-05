<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the signed-URL warning regex patterns.
 *
 * Tests the same patterns used in MediaItemsController to detect
 * signed/tokenized URLs that may be inappropriate for public volumes.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class SignedUrlWarningTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var string[]
     */
    private array $_patterns = [
        '~[?&](token|sig|signature|Policy|Signature|KeyPair-Id|Key-Pair-Id)=~i',
        '~[?&]token=eyJ~',
    ];

    // Public Methods
    // =========================================================================

    /**
     * @dataProvider signedUrlProvider
     */
    public function testSignedUrlDetected(string $url): void
    {
        $this->assertTrue($this->_isSignedUrl($url), "Expected signed URL: {$url}");
    }

    /**
     * @dataProvider cleanUrlProvider
     */
    public function testCleanUrlNotDetected(string $url): void
    {
        $this->assertFalse($this->_isSignedUrl($url), "Expected clean URL: {$url}");
    }

    public static function signedUrlProvider(): array
    {
        return [
            'token param' => ['https://stream.mux.com/abc.m3u8?token=abc123'],
            'sig param' => ['https://cdn.example.com/video.mp4?sig=deadbeef'],
            'signature param' => ['https://cdn.example.com/video.mp4?signature=abc'],
            'Policy param (CloudFront)' => ['https://d1.cloudfront.net/video.mp4?Policy=eyJTdGF0ZW1lbnQ'],
            'Signature param (CloudFront)' => ['https://d1.cloudfront.net/video.mp4?Signature=abc'],
            'KeyPair-Id param' => ['https://d1.cloudfront.net/video.mp4?KeyPair-Id=APKA123'],
            'Key-Pair-Id param' => ['https://d1.cloudfront.net/video.mp4?Key-Pair-Id=APKA123'],
            'JWT token' => ['https://stream.mux.com/abc.m3u8?token=eyJhbGciOiJSUzI1NiJ9.xxx'],
            'token with other params' => ['https://example.com/stream.m3u8?v=1&token=secret&t=0'],
            'sig with other params' => ['https://cdn.example.com/video.mp4?format=mp4&sig=abc123'],
        ];
    }

    public static function cleanUrlProvider(): array
    {
        return [
            'plain YouTube' => ['https://www.youtube.com/watch?v=dQw4w9WgXcQ'],
            'plain Vimeo' => ['https://vimeo.com/123456789'],
            'plain HLS' => ['https://cdn.example.com/stream.m3u8'],
            'plain MP4' => ['https://cdn.example.com/video.mp4'],
            'query with non-sensitive params' => ['https://cdn.example.com/video.mp4?quality=720&format=mp4'],
            'fragment only' => ['https://cdn.example.com/stream.m3u8#t=10'],
            'Mux without token' => ['https://stream.mux.com/abc123.m3u8'],
            'Spotify' => ['https://open.spotify.com/track/4iV5W9uYEdYUVa79Axb7Rh'],
            'token in path not query' => ['https://cdn.example.com/token/abc123/video.mp4'],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $url the URL to check
     * @return bool
     */
    private function _isSignedUrl(string $url): bool
    {
        foreach ($this->_patterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }
}
