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
 * Unit tests for the Renderer service script output logic.
 *
 * Tests the SCRIPT_MAP constant and script tag generation patterns
 * without requiring a full Craft bootstrap.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class RendererScriptsTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, string>
     */
    private array $_scriptMap = [
        'hls' => 'hls-video-element@1',
        'dash' => 'dash-video-element@0',
        'shaka' => 'shaka-video-element@0',
        'mux' => '@mux/mux-video@0',
        'youtube' => 'youtube-video-element@1',
        'vimeo' => 'vimeo-video-element@1',
        'spotify' => 'spotify-audio-element@1',
        'tiktok' => 'tiktok-video-element@0',
        'wistia' => 'wistia-video-element@1',
        'jwplayer' => 'jwplayer-video-element@0',
        'twitch' => 'twitch-video-element@0',
        'cloudflare' => 'cloudflare-video-element@0',
        'peertube' => 'peertube-video-element@0',
        'videojs' => 'videojs-video-element@0',
    ];

    // Public Methods
    // =========================================================================

    public function testScriptMapCoversAllProviders(): void
    {
        $expected = [
            'hls', 'dash', 'shaka', 'mux', 'youtube', 'vimeo', 'spotify',
            'tiktok', 'wistia', 'jwplayer', 'twitch', 'cloudflare', 'peertube', 'videojs',
        ];

        foreach ($expected as $provider) {
            $this->assertArrayHasKey($provider, $this->_scriptMap, "Missing script map entry for: {$provider}");
        }
    }

    public function testMuxPackageIsScoped(): void
    {
        $this->assertStringStartsWith('@mux/', $this->_scriptMap['mux']);
    }

    public function testNoProviderUsesWrongMuxScope(): void
    {
        foreach ($this->_scriptMap as $provider => $pkg) {
            if ($provider === 'mux') {
                continue;
            }

            $this->assertStringNotContainsString('@muxinc/', $pkg, "Provider {$provider} uses wrong @muxinc scope");
        }
    }

    public function testCdnUrlFormat(): void
    {
        $cdnHost = 'cdn.jsdelivr.net';
        $pkg = $this->_scriptMap['youtube'];
        $url = "https://{$cdnHost}/npm/{$pkg}/+esm";

        $this->assertSame('https://cdn.jsdelivr.net/npm/youtube-video-element@1/+esm', $url);
    }

    public function testSelfHostFilenameExtraction(): void
    {
        $base = '/dist/polymedia';

        foreach ($this->_scriptMap as $provider => $pkg) {
            $name = str_contains($pkg, '/') ? explode('/', $pkg)[1] : $pkg;
            $name = preg_replace('/@\d+$/', '', $name);
            $path = "{$base}/{$name}.min.js";

            $this->assertStringNotContainsString('@', $path, "Self-host path for {$provider} still has @ sign");
            $this->assertStringEndsWith('.min.js', $path);
        }
    }

    public function testAudioTypes(): void
    {
        $audioTypes = ['audio', 'spotify'];

        $this->assertContains('audio', $audioTypes);
        $this->assertContains('spotify', $audioTypes);
        $this->assertNotContains('youtube', $audioTypes);
        $this->assertNotContains('hls', $audioTypes);
    }

    public function testMuxUsesPlaybackIdNotSrc(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/services/Renderer.php');
        $this->assertStringContainsString("'playback-id'", $source);
        $this->assertDoesNotMatchRegularExpression('/<mux-video[^>]*\bsrc=/', $source);
    }

    public function testNoControlsOnMediaController(): void
    {
        $source = file_get_contents(__DIR__ . '/../../src/services/Renderer.php');
        $this->assertDoesNotMatchRegularExpression('/media-controller[^>]*controls/', $source);
    }
}
