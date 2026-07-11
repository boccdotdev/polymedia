<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\services\ThumbnailDeriver;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the ThumbnailDeriver service.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class ThumbnailDeriverTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var ThumbnailDeriver
     */
    private ThumbnailDeriver $_deriver;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->_deriver = new ThumbnailDeriver();
    }

    public function testMux(): void
    {
        $detection = $this->_makeDetection('mux', 'abc123');
        $this->assertSame(
            'https://image.mux.com/abc123/thumbnail.jpg?time=0',
            $this->_deriver->derive($detection),
        );
    }

    public function testYouTube(): void
    {
        $detection = $this->_makeDetection('youtube', 'dQw4w9WgXcQ');
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/maxresdefault.jpg', $this->_deriver->derive($detection));
    }

    public function testYouTubeFallback(): void
    {
        $this->assertSame('https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $this->_deriver->getYouTubeFallback('dQw4w9WgXcQ'));
    }

    public function testVimeo(): void
    {
        $detection = $this->_makeDetection('vimeo', '123456789');
        $this->assertSame('https://vumbnail.com/123456789.jpg', $this->_deriver->derive($detection));
    }

    public function testCloudflare(): void
    {
        $detection = $this->_makeDetection('cloudflare', '5d5bc37ffcf54c9b82e996823bffbb81');
        $this->assertSame(
            'https://videodelivery.net/5d5bc37ffcf54c9b82e996823bffbb81/thumbnails/thumbnail.jpg',
            $this->_deriver->derive($detection),
        );
    }

    public function testWistia(): void
    {
        $detection = $this->_makeDetection('wistia', 'abc123def');
        $this->assertSame('https://embed-ssl.wistia.com/deliveries/abc123def.jpg', $this->_deriver->derive($detection));
    }

    public function testHlsReturnsNull(): void
    {
        $detection = $this->_makeDetection('hls', '');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testDashReturnsNull(): void
    {
        $detection = $this->_makeDetection('dash', '');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testMp4ReturnsNull(): void
    {
        $detection = $this->_makeDetection('mp4', '');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testAudioReturnsNull(): void
    {
        $detection = $this->_makeDetection('audio', '');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testSpotifyReturnsNull(): void
    {
        $detection = $this->_makeDetection('spotify', 'abc123');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testTikTokReturnsNull(): void
    {
        $detection = $this->_makeDetection('tiktok', '7234567890123456789');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testTwitchReturnsNull(): void
    {
        $detection = $this->_makeDetection('twitch', '1234567890');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testJwPlayerReturnsNull(): void
    {
        $detection = $this->_makeDetection('jwplayer', 'abc123');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testEmptyProviderIdReturnsNull(): void
    {
        $detection = $this->_makeDetection('mux', '');
        $this->assertNull($this->_deriver->derive($detection));
    }

    public function testNoGuzzleImport(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/services/ThumbnailDeriver.php');
        $this->assertStringNotContainsString('Guzzle', $source);
        $this->assertStringNotContainsString('use GuzzleHttp', $source);
        $this->assertDoesNotMatchRegularExpression('/oembed\.json|\/oembed\?|youtube\.com\/oembed/', $source);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $type the media type
     * @param string $providerId the provider ID
     * @return DetectionResult
     */
    private function _makeDetection(string $type, string $providerId): DetectionResult
    {
        $result = new DetectionResult();
        $result->type = $type;
        $result->providerId = $providerId;
        $result->url = 'https://example.com/test';
        $result->element = 'video';

        return $result;
    }
}
