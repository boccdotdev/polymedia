<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\services\UrlDetector;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the UrlDetector service.
 *
 * Covers all 16 provider types with canonical, edge case, and malformed URLs.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class UrlDetectorTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var UrlDetector
     */
    private UrlDetector $_detector;

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->_detector = new UrlDetector();
    }

    // Mux
    // =========================================================================

    public function testMuxCanonical(): void
    {
        $result = $this->_detector->detect('https://stream.mux.com/DS00Spx1CV902MCtPj5WknGlR102V5HFkDe.m3u8');
        $this->assertNotNull($result);
        $this->assertSame('mux', $result->type);
        $this->assertSame('mux-video', $result->element);
        $this->assertSame('DS00Spx1CV902MCtPj5WknGlR102V5HFkDe', $result->providerId);
    }

    public function testMuxWithoutExtension(): void
    {
        $result = $this->_detector->detect('https://stream.mux.com/abc123');
        $this->assertNotNull($result);
        $this->assertSame('mux', $result->type);
        $this->assertSame('abc123', $result->providerId);
    }

    public function testMuxBeatsHls(): void
    {
        $result = $this->_detector->detect('https://stream.mux.com/abc123.m3u8');
        $this->assertSame('mux', $result->type);
    }

    // YouTube
    // =========================================================================

    public function testYouTubeWatch(): void
    {
        $result = $this->_detector->detect('https://www.youtube.com/watch?v=dQw4w9WgXcQ');
        $this->assertNotNull($result);
        $this->assertSame('youtube', $result->type);
        $this->assertSame('youtube-video', $result->element);
        $this->assertSame('dQw4w9WgXcQ', $result->providerId);
    }

    public function testYouTubeShorts(): void
    {
        $result = $this->_detector->detect('https://www.youtube.com/shorts/dQw4w9WgXcQ');
        $this->assertNotNull($result);
        $this->assertSame('youtube', $result->type);
        $this->assertSame('dQw4w9WgXcQ', $result->providerId);
    }

    public function testYouTubeShortUrl(): void
    {
        $result = $this->_detector->detect('https://youtu.be/dQw4w9WgXcQ');
        $this->assertNotNull($result);
        $this->assertSame('youtube', $result->type);
        $this->assertSame('dQw4w9WgXcQ', $result->providerId);
    }

    public function testYouTubeEmbed(): void
    {
        $result = $this->_detector->detect('https://www.youtube.com/embed/dQw4w9WgXcQ');
        $this->assertNotNull($result);
        $this->assertSame('youtube', $result->type);
        $this->assertSame('dQw4w9WgXcQ', $result->providerId);
    }

    public function testYouTubeWithExtraParams(): void
    {
        $result = $this->_detector->detect('https://www.youtube.com/watch?list=PLrAXtmErZgOeiKm4sgNOknGvNjby9efdf&v=dQw4w9WgXcQ');
        $this->assertNotNull($result);
        $this->assertSame('youtube', $result->type);
        $this->assertSame('dQw4w9WgXcQ', $result->providerId);
    }

    public function testYouTubeLive(): void
    {
        $result = $this->_detector->detect('https://www.youtube.com/live/dQw4w9WgXcQ');
        $this->assertNotNull($result);
        $this->assertSame('youtube', $result->type);
        $this->assertSame('dQw4w9WgXcQ', $result->providerId);
    }

    // Vimeo
    // =========================================================================

    public function testVimeoCanonical(): void
    {
        $result = $this->_detector->detect('https://vimeo.com/123456789');
        $this->assertNotNull($result);
        $this->assertSame('vimeo', $result->type);
        $this->assertSame('vimeo-video', $result->element);
        $this->assertSame('123456789', $result->providerId);
    }

    public function testVimeoWithHash(): void
    {
        $result = $this->_detector->detect('https://vimeo.com/123456789/abc123def');
        $this->assertNotNull($result);
        $this->assertSame('vimeo', $result->type);
        $this->assertSame('123456789', $result->providerId);
    }

    public function testVimeoChannel(): void
    {
        $result = $this->_detector->detect('https://vimeo.com/channels/staffpicks/123456789');
        $this->assertNotNull($result);
        $this->assertSame('vimeo', $result->type);
        $this->assertSame('123456789', $result->providerId);
    }

    public function testVimeoShowcase(): void
    {
        $result = $this->_detector->detect('https://vimeo.com/showcase/1234/video/123456789');
        $this->assertNotNull($result);
        $this->assertSame('vimeo', $result->type);
        $this->assertSame('123456789', $result->providerId);
    }

    // Spotify
    // =========================================================================

    public function testSpotifyTrack(): void
    {
        $result = $this->_detector->detect('https://open.spotify.com/track/6rqhFgbbKwnb9MLmUQDhG6');
        $this->assertNotNull($result);
        $this->assertSame('spotify', $result->type);
        $this->assertSame('spotify-audio', $result->element);
        $this->assertSame('6rqhFgbbKwnb9MLmUQDhG6', $result->providerId);
        $this->assertSame('track', $result->hints['subType']);
    }

    public function testSpotifyEpisode(): void
    {
        $result = $this->_detector->detect('https://open.spotify.com/episode/6rqhFgbbKwnb9MLmUQDhG6');
        $this->assertNotNull($result);
        $this->assertSame('spotify', $result->type);
        $this->assertSame('episode', $result->hints['subType']);
    }

    public function testSpotifyPlaylist(): void
    {
        $result = $this->_detector->detect('https://open.spotify.com/playlist/37i9dQZF1DXcBWIGoYBM5M');
        $this->assertNotNull($result);
        $this->assertSame('spotify', $result->type);
        $this->assertSame('playlist', $result->hints['subType']);
    }

    public function testSpotifyIntlPrefix(): void
    {
        $result = $this->_detector->detect('https://open.spotify.com/intl-de/track/6rqhFgbbKwnb9MLmUQDhG6');
        $this->assertNotNull($result);
        $this->assertSame('spotify', $result->type);
        $this->assertSame('6rqhFgbbKwnb9MLmUQDhG6', $result->providerId);
    }

    // TikTok
    // =========================================================================

    public function testTikTokVideo(): void
    {
        $result = $this->_detector->detect('https://www.tiktok.com/@username/video/7234567890123456789');
        $this->assertNotNull($result);
        $this->assertSame('tiktok', $result->type);
        $this->assertSame('tiktok-video', $result->element);
        $this->assertSame('7234567890123456789', $result->providerId);
    }

    public function testTikTokShortUrl(): void
    {
        $result = $this->_detector->detect('https://vm.tiktok.com/ZM6abc123');
        $this->assertNotNull($result);
        $this->assertSame('tiktok', $result->type);
        $this->assertSame('ZM6abc123', $result->providerId);
    }

    public function testTikTokTUrl(): void
    {
        $result = $this->_detector->detect('https://www.tiktok.com/t/ZM6abc123');
        $this->assertNotNull($result);
        $this->assertSame('tiktok', $result->type);
    }

    // Wistia
    // =========================================================================

    public function testWistiaDomain(): void
    {
        $result = $this->_detector->detect('https://home.wistia.com/medias/abc123def');
        $this->assertNotNull($result);
        $this->assertSame('wistia', $result->type);
        $this->assertSame('wistia-video', $result->element);
        $this->assertSame('abc123def', $result->providerId);
    }

    public function testWistiaEmbed(): void
    {
        $result = $this->_detector->detect('https://fast.wistia.com/embed/medias/abc123def');
        $this->assertNotNull($result);
        $this->assertSame('wistia', $result->type);
        $this->assertSame('abc123def', $result->providerId);
    }

    public function testWistiaIframe(): void
    {
        $result = $this->_detector->detect('https://fast.wistia.com/embed/iframe/abc123def');
        $this->assertNotNull($result);
        $this->assertSame('wistia', $result->type);
        $this->assertSame('abc123def', $result->providerId);
    }

    // JW Player
    // =========================================================================

    public function testJwPlayerCdn(): void
    {
        $result = $this->_detector->detect('https://cdn.jwplayer.com/players/abc123DEF');
        $this->assertNotNull($result);
        $this->assertSame('jwplayer', $result->type);
        $this->assertSame('jwplayer-video', $result->element);
        $this->assertSame('abc123DEF', $result->providerId);
    }

    public function testJwPlayerManifest(): void
    {
        $result = $this->_detector->detect('https://content.jwplatform.com/manifests/abc123DEF.m3u8');
        $this->assertNotNull($result);
        $this->assertSame('jwplayer', $result->type);
        $this->assertSame('abc123DEF', $result->providerId);
    }

    public function testJwPlayerVideos(): void
    {
        $result = $this->_detector->detect('https://cdn.jwplayer.com/videos/abc123DEF');
        $this->assertNotNull($result);
        $this->assertSame('jwplayer', $result->type);
    }

    // Twitch
    // =========================================================================

    public function testTwitchVod(): void
    {
        $result = $this->_detector->detect('https://www.twitch.tv/videos/1234567890');
        $this->assertNotNull($result);
        $this->assertSame('twitch', $result->type);
        $this->assertSame('twitch-video', $result->element);
        $this->assertSame('1234567890', $result->providerId);
    }

    public function testTwitchClip(): void
    {
        $result = $this->_detector->detect('https://clips.twitch.tv/FunnyClipName-abc123');
        $this->assertNotNull($result);
        $this->assertSame('twitch', $result->type);
        $this->assertSame('FunnyClipName-abc123', $result->providerId);
    }

    public function testTwitchChannel(): void
    {
        $result = $this->_detector->detect('https://www.twitch.tv/ninja');
        $this->assertNotNull($result);
        $this->assertSame('twitch', $result->type);
        $this->assertSame('ninja', $result->providerId);
    }

    // Cloudflare Stream
    // =========================================================================

    public function testCloudflareStream(): void
    {
        $result = $this->_detector->detect('https://videodelivery.net/5d5bc37ffcf54c9b82e996823bffbb81');
        $this->assertNotNull($result);
        $this->assertSame('cloudflare', $result->type);
        $this->assertSame('cloudflare-video', $result->element);
        $this->assertSame('5d5bc37ffcf54c9b82e996823bffbb81', $result->providerId);
    }

    public function testCloudflareCustomDomain(): void
    {
        $result = $this->_detector->detect('https://customer-abc123.cloudflarestream.com/5d5bc37ffcf54c9b82e996823bffbb81');
        $this->assertNotNull($result);
        $this->assertSame('cloudflare', $result->type);
    }

    public function testCloudflareBeatsHls(): void
    {
        $result = $this->_detector->detect('https://videodelivery.net/5d5bc37ffcf54c9b82e996823bffbb81/manifest/video.m3u8');
        $this->assertSame('cloudflare', $result->type);
    }

    // HLS
    // =========================================================================

    public function testHlsGeneric(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/stream.m3u8');
        $this->assertNotNull($result);
        $this->assertSame('hls', $result->type);
        $this->assertSame('hls-video', $result->element);
    }

    public function testHlsWithQueryParams(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/stream.m3u8?token=abc');
        $this->assertNotNull($result);
        $this->assertSame('hls', $result->type);
    }

    public function testHlsWithFragment(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/stream.m3u8#t=0');
        $this->assertNotNull($result);
        $this->assertSame('hls', $result->type);
    }

    // DASH
    // =========================================================================

    public function testDash(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/manifest.mpd');
        $this->assertNotNull($result);
        $this->assertSame('dash', $result->type);
        $this->assertSame('dash-video', $result->element);
    }

    public function testDashWithQuery(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/manifest.mpd?key=val');
        $this->assertNotNull($result);
        $this->assertSame('dash', $result->type);
    }

    public function testDashCaseInsensitive(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/manifest.MPD');
        $this->assertNotNull($result);
        $this->assertSame('dash', $result->type);
    }

    // MP4 / WebM / MOV
    // =========================================================================

    public function testMp4(): void
    {
        $result = $this->_detector->detect('https://example.com/video.mp4');
        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->type);
        $this->assertSame('video', $result->element);
    }

    public function testWebm(): void
    {
        $result = $this->_detector->detect('https://example.com/video.webm');
        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->type);
    }

    public function testMov(): void
    {
        $result = $this->_detector->detect('https://example.com/video.mov');
        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->type);
    }

    public function testMp4WithQuery(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/video.mp4?token=xyz');
        $this->assertNotNull($result);
        $this->assertSame('mp4', $result->type);
    }

    // Audio
    // =========================================================================

    public function testMp3(): void
    {
        $result = $this->_detector->detect('https://example.com/track.mp3');
        $this->assertNotNull($result);
        $this->assertSame('audio', $result->type);
        $this->assertSame('audio', $result->element);
    }

    public function testM4a(): void
    {
        $result = $this->_detector->detect('https://example.com/track.m4a');
        $this->assertNotNull($result);
        $this->assertSame('audio', $result->type);
    }

    public function testOgg(): void
    {
        $result = $this->_detector->detect('https://example.com/track.ogg');
        $this->assertNotNull($result);
        $this->assertSame('audio', $result->type);
    }

    public function testWav(): void
    {
        $result = $this->_detector->detect('https://example.com/track.wav');
        $this->assertNotNull($result);
        $this->assertSame('audio', $result->type);
    }

    public function testFlac(): void
    {
        $result = $this->_detector->detect('https://example.com/track.flac');
        $this->assertNotNull($result);
        $this->assertSame('audio', $result->type);
    }

    // Override
    // =========================================================================

    public function testOverrideShaka(): void
    {
        $result = $this->_detector->detect('https://cdn.example.com/stream.m3u8', 'shaka');
        $this->assertNotNull($result);
        $this->assertSame('shaka', $result->type);
        $this->assertSame('shaka-video', $result->element);
    }

    public function testOverrideVideojs(): void
    {
        $result = $this->_detector->detect('https://example.com/video.mp4', 'videojs');
        $this->assertNotNull($result);
        $this->assertSame('videojs', $result->type);
        $this->assertSame('videojs-video', $result->element);
    }

    public function testOverridePeertube(): void
    {
        $result = $this->_detector->detect('https://my-instance.example.com/videos/watch/abc123', 'peertube');
        $this->assertNotNull($result);
        $this->assertSame('peertube', $result->type);
        $this->assertSame('peertube-video', $result->element);
    }

    public function testOverrideInvalidType(): void
    {
        $result = $this->_detector->detect('https://example.com/video.mp4', 'nonexistent');
        $this->assertNull($result);
    }

    // Null / malformed
    // =========================================================================

    public function testEmptyString(): void
    {
        $result = $this->_detector->detect('');
        $this->assertNull($result);
    }

    public function testWhitespace(): void
    {
        $result = $this->_detector->detect('   ');
        $this->assertNull($result);
    }

    public function testPlainText(): void
    {
        $result = $this->_detector->detect('not a url at all');
        $this->assertNull($result);
    }

    public function testGenericUrl(): void
    {
        $result = $this->_detector->detect('https://example.com/some-page');
        $this->assertNull($result);
    }

    public function testHtmlPage(): void
    {
        $result = $this->_detector->detect('https://example.com/page.html');
        $this->assertNull($result);
    }

    public function testImageUrl(): void
    {
        $result = $this->_detector->detect('https://example.com/image.jpg');
        $this->assertNull($result);
    }

    // URL preserved
    // =========================================================================

    public function testOriginalUrlPreserved(): void
    {
        $url = 'https://www.youtube.com/watch?v=dQw4w9WgXcQ&list=PLrAXtmErZgOei';
        $result = $this->_detector->detect($url);
        $this->assertSame($url, $result->url);
    }

    // Provider type list
    // =========================================================================

    public function testAllProvidersCovered(): void
    {
        $types = $this->_detector->getProviderTypes();
        $this->assertContains('hls', $types);
        $this->assertContains('dash', $types);
        $this->assertContains('shaka', $types);
        $this->assertContains('mux', $types);
        $this->assertContains('youtube', $types);
        $this->assertContains('vimeo', $types);
        $this->assertContains('spotify', $types);
        $this->assertContains('tiktok', $types);
        $this->assertContains('wistia', $types);
        $this->assertContains('jwplayer', $types);
        $this->assertContains('twitch', $types);
        $this->assertContains('cloudflare', $types);
        $this->assertContains('peertube', $types);
        $this->assertContains('videojs', $types);
        $this->assertContains('mp4', $types);
        $this->assertContains('audio', $types);
        $this->assertCount(16, $types);
    }
}
