<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\engines\MediaChrome;
use boccdotdev\polymedia\engines\VideoJs;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\records\RelatedAssetRecord;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\Renderer;
use boccdotdev\polymedia\services\UrlDetector;
use Craft;
use craft\elements\Asset;
use craft\models\Site;
use PHPUnit\Framework\TestCase;

/**
 * Locks the HTML that `player()`, `element()` and `scripts()` emit.
 *
 * Each case compares against a golden file in `snapshots/renderer/`. Run with
 * `UPDATE_SNAPSHOTS=1` to rewrite them after an intentional output change.
 *
 * @author boccdotdev
 * @since 2.4.0
 */
class RendererOutputTest extends TestCase
{
    // Private Properties
    // =========================================================================

    /**
     * @var mixed
     */
    private mixed $_previousApp;

    /**
     * @var array the manifest `ManifestWriter::read()` returns
     */
    private array $_manifest = [];

    /**
     * @var ?MediaItemRecord the record `MediaItems::getByAssetId()` returns
     */
    private ?MediaItemRecord $_record = null;

    /**
     * @var ?string URL of the attached poster asset, if any
     */
    private ?string $_posterUrl = null;

    /**
     * @var array<string, array<int, array{siteId: ?int, url: string, srclang: ?string, label: ?string}>>
     */
    private array $_tracks = [];

    /**
     * @var Settings
     */
    private Settings $_settings;

    /**
     * @var string the current site's language
     */
    private string $_language = 'en-US';

    // Public Methods
    // =========================================================================

    protected function setUp(): void
    {
        parent::setUp();
        $this->_previousApp = Craft::$app;
        $this->_settings = new Settings();

        $trackAssets = [];
        $assets = $this->createMock(\craft\services\Assets::class);
        $assets->method('getAssetById')->willReturnCallback(function(int $id) use (&$trackAssets): ?Asset {
            return $trackAssets[$id] ?? null;
        });
        $sites = $this->createMock(\craft\services\Sites::class);
        $sites->method('getCurrentSite')->willReturnCallback(fn() => new Site(['id' => 1, 'language' => $this->_language]));
        $app = $this->getMockBuilder(\craft\web\Application::class)
            ->disableOriginalConstructor()->onlyMethods(['getAssets', 'getSites'])->getMock();
        $app->method('getAssets')->willReturn($assets);
        $app->method('getSites')->willReturn($sites);
        Craft::$app = $app;

        $items = $this->createMock(MediaItems::class);
        $items->method('getByAssetId')->willReturnCallback(fn() => $this->_record);
        $writer = $this->createMock(ManifestWriter::class);
        $writer->method('read')->willReturnCallback(fn() => $this->_manifest);
        $related = $this->createMock(RelatedAssets::class);
        $related->method('getPoster')->willReturnCallback(function(): ?Asset {
            return $this->_posterUrl !== null ? $this->_asset(900, $this->_posterUrl) : null;
        });
        $related->method('getTrackRecords')->willReturnCallback(
            function(int $itemId, string $role, ?int $siteId) use (&$trackAssets): array {
                $records = [];

                foreach ($this->_tracks[$role] ?? [] as $i => $track) {
                    if ($siteId !== null && $track['siteId'] !== $siteId) {
                        continue;
                    }

                    $id = 1000 + count($trackAssets);
                    $trackAssets[$id] = $this->_asset($id, $track['url']);
                    $record = $this->getMockBuilder(RelatedAssetRecord::class)
                        ->disableOriginalConstructor()->onlyMethods(['__get'])->getMock();
                    $record->method('__get')->willReturnCallback(fn(string $name) => match ($name) {
                        'assetId' => $id,
                        'srclang' => $track['srclang'],
                        'label' => $track['label'],
                        default => null,
                    });
                    $records[] = $record;
                }

                return $records;
            },
        );

        $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
            ->onlyMethods(['getMediaItems', 'getManifestWriter', 'getRelatedAssets', 'getUrlDetector', 'getSettings'])
            ->getMock();
        $plugin->method('getMediaItems')->willReturn($items);
        $plugin->method('getManifestWriter')->willReturn($writer);
        $plugin->method('getRelatedAssets')->willReturn($related);
        $plugin->method('getUrlDetector')->willReturn(new UrlDetector());
        $plugin->method('getSettings')->willReturnCallback(fn() => $this->_settings);
        Craft::$app->loadedModules[Plugin::class] = $plugin;
    }

    protected function tearDown(): void
    {
        Craft::$app = $this->_previousApp;
        parent::tearDown();
    }

    /**
     * @dataProvider playerCases
     */
    public function testPlayer(string $name, array $manifest, array $record, array $options, ?string $posterUrl, array $tracks): void
    {
        $this->_given($manifest, $record, $posterUrl, $tracks);
        $this->_assertSnapshot("player-{$name}", (string)(new Renderer())->player($this->_polymediaAsset(), $options));
    }

    public static function playerCases(): array
    {
        $mux = self::_manifest('mux', 'https://stream.mux.com/pb1.m3u8', 'pb1', 'mux-video', [
            'thumbnail' => 'https://image.mux.com/pb1/thumbnail.jpg?time=0',
        ]);
        $captions = [
            'captions' => [
                ['siteId' => 1, 'url' => 'https://example.test/en.vtt', 'srclang' => 'en', 'label' => 'English'],
                ['siteId' => 2, 'url' => 'https://example.test/fr.vtt', 'srclang' => 'fr', 'label' => 'Français'],
            ],
            'subtitles' => [
                ['siteId' => 1, 'url' => 'https://example.test/de.vtt', 'srclang' => 'de', 'label' => null],
            ],
            'descriptions' => [
                ['siteId' => 1, 'url' => 'https://example.test/ad.vtt', 'srclang' => null, 'label' => null],
            ],
        ];

        return [
            'mux' => ['mux', $mux, ['element' => 'mux-video'], [], null, []],
            'mux live stream type' => ['mux-stream-type', ['streamType' => 'live'] + $mux, ['element' => 'mux-video'], [], null, []],
            'hls' => ['hls', self::_manifest('hls', 'https://example.test/v.m3u8', '', 'hls-video'), ['element' => 'hls-video'], [], null, []],
            'youtube' => ['youtube', self::_manifest('youtube', 'https://www.youtube.com/watch?v=abc', 'abc', 'youtube-video'), ['element' => 'youtube-video'], [], null, []],
            'vimeo without record' => ['vimeo-no-record', self::_manifest('vimeo', 'https://vimeo.com/1', '1', 'vimeo-video'), [], [], null, []],
            'mp4' => ['mp4', self::_manifest('mp4', 'https://example.test/v.mp4', '', 'video'), ['element' => 'video'], [], null, []],
            'jwplayer' => ['jwplayer', self::_manifest('jwplayer', 'https://cdn.jwplayer.com/players/a-b.html', 'a', 'jwplayer-video'), ['element' => 'jwplayer-video'], [], null, []],
            'spotify with cover' => ['spotify', self::_manifest('spotify', 'https://open.spotify.com/track/t1', 't1', 'spotify-audio', [
                'thumbnail' => 'https://i.scdn.co/image/t1',
            ]), ['element' => 'spotify-audio'], [], null, []],
            'audio' => ['audio', self::_manifest('audio', 'https://example.test/a.mp3', '', 'audio'), ['element' => 'audio'], [], null, []],
            'audio with children' => ['audio-children', self::_manifest('audio', 'https://example.test/a.mp3', '', 'audio'), ['element' => 'audio'], [
                'children' => '<media-control-bar><media-play-button></media-play-button></media-control-bar>',
            ], null, []],
            'attached poster beats thumbnail' => ['attached-poster', $mux, ['element' => 'mux-video'], [], 'https://example.test/poster.jpg', []],
            'poster option' => ['poster-option', $mux, ['element' => 'mux-video'], ['poster' => 'https://example.test/override.jpg'], 'https://example.test/poster.jpg', []],
            'poster false' => ['poster-false', $mux, ['element' => 'mux-video'], ['poster' => false], 'https://example.test/poster.jpg', []],
            'options' => ['options', $mux, ['element' => 'mux-video'], [
                'class' => 'hero-player',
                'id' => 'hero',
                'attrs' => ['data-theme' => 'dark', 'nohotkeys' => true],
                'mediaAttrs' => ['data-index' => '2', 'disablepictureinpicture' => true],
                'children' => '<media-control-bar><media-play-button></media-play-button></media-control-bar>',
                'autoplay' => true,
                'loop' => true,
                'muted' => true,
                'controls' => false,
                'playsinline' => false,
                'preload' => 'none',
                'crossorigin' => 'anonymous',
            ], null, []],
            'record defaults' => ['record-defaults', $mux, [
                'element' => 'mux-video',
                'defaults' => '{"autoplay":true,"muted":true,"preload":"auto"}',
            ], ['autoplay' => false], null, []],
            'tracks for current site' => ['tracks', $mux, ['element' => 'mux-video'], [], null, $captions],
            'tracks all sites' => ['tracks-all', $mux, ['element' => 'mux-video'], ['tracks' => 'all'], null, $captions],
            'tracks none' => ['tracks-none', $mux, ['element' => 'mux-video'], ['tracks' => 'none'], null, $captions],
            'escaped metadata' => ['escaped', self::_manifest('spotify', 'https://open.spotify.com/track/t1?a=1&b="2"', 't1', 'spotify-audio', [
                'thumbnail' => 'https://i.scdn.co/image/t1?x=<y>',
            ], 'Tom & "Jerry" <b>'), ['element' => 'spotify-audio'], [], null, []],
        ];
    }

    /**
     * @dataProvider elementCases
     */
    public function testElement(string $name, array $manifest, array $record, array $options): void
    {
        $this->_given($manifest, $record, null, []);
        $this->_assertSnapshot("element-{$name}", (string)(new Renderer())->element($this->_polymediaAsset(), $options));
    }

    public static function elementCases(): array
    {
        $mux = self::_manifest('mux', 'https://stream.mux.com/pb1.m3u8', 'pb1', 'mux-video', [
            'thumbnail' => 'https://image.mux.com/pb1/thumbnail.jpg?time=0',
        ]);

        return [
            'mux' => ['mux', $mux, ['element' => 'mux-video'], []],
            'youtube' => ['youtube', self::_manifest('youtube', 'https://www.youtube.com/watch?v=abc', 'abc', 'youtube-video'), ['element' => 'youtube-video'], []],
            'audio' => ['audio', self::_manifest('audio', 'https://example.test/a.mp3', '', 'audio'), ['element' => 'audio'], []],
            'options' => ['options', $mux, ['element' => 'mux-video'], [
                'controls' => false,
                'muted' => true,
                'poster' => false,
                'mediaAttrs' => ['class' => 'bg-video', 'slot' => 'media'],
            ]],
        ];
    }

    public function testEmptyOutputForMissingManifestOrNonPolymediaAsset(): void
    {
        $renderer = new Renderer();
        $this->_given([], ['element' => 'video'], null, []);
        $this->assertSame('', (string)$renderer->player($this->_polymediaAsset()));
        $this->assertSame('', (string)$renderer->element($this->_polymediaAsset()));

        $image = $this->createMock(Asset::class);
        $image->id = 70;
        $image->kind = 'image';
        $this->assertSame([], $renderer->data($image));
        $this->assertSame('', (string)$renderer->player($image));
    }

    /**
     * @dataProvider scriptCases
     */
    public function testScripts(string $name, array $settings, array $options): void
    {
        $_SERVER['POLYMEDIA_TEST_CDN_HOST'] = 'env-cdn.example.test';
        $this->_settings->setAttributes($settings, false);

        try {
            $this->_assertSnapshot("scripts-{$name}", (string)(new Renderer())->scripts($options));
        } finally {
            unset($_SERVER['POLYMEDIA_TEST_CDN_HOST']);
        }
    }

    public static function scriptCases(): array
    {
        return [
            'cdn defaults' => ['cdn-defaults', [], []],
            'cdn options' => ['cdn-options', [], ['providers' => ['mux', 'spotify', 'unknown'], 'version' => '3']],
            'cdn host setting' => ['cdn-host', ['cdnHost' => 'esm.example.test', 'defaultProviders' => ['dash']], []],
            'cdn host env var' => ['cdn-host-env', ['cdnHost' => '$POLYMEDIA_TEST_CDN_HOST', 'defaultProviders' => []], []],
            'self-host' => ['self-host', [
                'scriptLoaderMode' => 'self-host',
                'selfHostBaseUrl' => 'https://example.test/js/',
                'defaultProviders' => ['hls', 'mux', 'youtube'],
            ], []],
            'mode option overrides setting' => ['mode-option', ['scriptLoaderMode' => 'self-host'], ['mode' => 'cdn', 'providers' => []]],
            'none' => ['none', ['scriptLoaderMode' => 'none'], []],
            'peertube package' => ['cdn-peertube', [], ['providers' => ['peertube']]],
        ];
    }

    /**
     * @dataProvider videojsPlayerCases
     */
    public function testVideojsPlayer(string $name, array $manifest, array $options, ?string $posterUrl, array $tracks, string $language = 'en-US'): void
    {
        $this->_settings->playerEngine = Settings::ENGINE_VIDEOJS;
        $this->_language = $language;
        $this->_given($manifest, ['element' => $manifest['element']], $posterUrl, $tracks);
        $this->_assertSnapshot("videojs-player-{$name}", (string)(new Renderer())->player($this->_polymediaAsset(), $options));
    }

    public static function videojsPlayerCases(): array
    {
        $mux = self::_manifest('mux', 'https://stream.mux.com/pb1.m3u8', 'pb1', 'mux-video', [
            'thumbnail' => 'https://image.mux.com/pb1/thumbnail.jpg?time=0',
        ]);
        $vertical = self::_manifest('mux', 'https://stream.mux.com/pb2.m3u8', 'pb2', 'mux-video', [
            'thumbnail' => 'https://image.mux.com/pb2/thumbnail.jpg?time=0',
            'aspectRatio' => '9:16',
        ]);
        $captions = [
            'captions' => [
                ['siteId' => 1, 'url' => 'https://example.test/en.vtt', 'srclang' => 'en', 'label' => 'English'],
            ],
        ];
        $controls = '<media-controls><media-play-button></media-play-button></media-controls>';

        return [
            'mux' => ['mux', $mux, [], null, []],
            'stored ratio and attached poster' => ['vertical', $vertical, [], 'https://example.test/poster.jpg', []],
            'hls uses hls.js element' => ['hls', self::_manifest('hls', 'https://example.test/v.m3u8', '', 'hls-video'), [], null, []],
            'youtube' => ['youtube', self::_manifest('youtube', 'https://www.youtube.com/watch?v=abc', 'abc', 'youtube-video'), [], null, []],
            'mp4 ratio from dimensions' => ['mp4-dimensions', ['width' => 1080, 'height' => 1350] + self::_manifest('mp4', 'https://example.test/v.mp4', '', 'video'), [], null, []],
            'audio' => ['audio', self::_manifest('audio', 'https://example.test/a.mp3', '', 'audio', [
                'thumbnail' => 'https://example.test/cover.jpg',
            ]), [], null, []],
            'spotify standalone' => ['spotify', self::_manifest('spotify', 'https://open.spotify.com/track/t1', 't1', 'spotify-audio'), [], null, []],
            'jwplayer standalone' => ['jwplayer', self::_manifest('jwplayer', 'https://cdn.jwplayer.com/players/a-b.html', 'a', 'jwplayer-video', [
                'thumbnail' => 'https://example.test/jw.jpg',
            ]), ['class' => 'embed'], null, []],
            'standalone ratio option' => ['peertube-ratio', self::_manifest('peertube', 'https://framatube.org/w/abc', 'abc', 'peertube-video', [
                'aspectRatio' => '9:16',
            ]), ['aspectRatio' => '4 / 3'], null, []],
            'children make a custom player' => ['custom', $mux, ['children' => $controls], null, []],
            'skin option' => ['skin-neutral', $mux, ['skin' => 'neutral'], null, []],
            'unknown skin falls back' => ['skin-unknown', $mux, ['skin' => 'glass'], null, []],
            'options' => ['options', $mux, [
                'class' => 'hero-player',
                'id' => 'hero',
                'attrs' => ['data-theme' => 'dark', 'style' => 'max-width: 40rem'],
                'mediaAttrs' => ['data-index' => '2'],
                'autoplay' => true,
                'loop' => true,
                'muted' => true,
                'controls' => true,
                'playsinline' => false,
                'preload' => 'none',
                'crossorigin' => 'use-credentials',
            ], null, []],
            'aspect ratio off' => ['ratio-off', $vertical, ['aspectRatio' => false], null, []],
            'aspect ratio option' => ['ratio-option', $vertical, ['aspectRatio' => '4 / 3'], null, []],
            'template style ratio wins' => ['ratio-style', $vertical, ['attrs' => ['style' => ['aspect-ratio' => '1']]], null, []],
            'tracks' => ['tracks', $mux, [], null, $captions],
            'site locale' => ['locale-site', $mux, [], null, [], 'fr-FR'],
            'locale off' => ['locale-off', $mux, ['locale' => false], null, [], 'fr-FR'],
            'locale option' => ['locale-option', $mux, ['locale' => 'pt-br'], null, []],
            'unsupported locale' => ['locale-unsupported', $mux, [], null, [], 'xh-ZA'],
            'standalone skips locale' => ['locale-standalone', self::_manifest('spotify', 'https://open.spotify.com/track/t1', 't1', 'spotify-audio'), [], null, [], 'fr-FR'],
            'audio with children' => ['audio-children', self::_manifest('audio', 'https://example.test/a.mp3', '', 'audio'), ['children' => $controls], null, []],
            'escaped values' => ['escaped', self::_manifest('mux', 'https://stream.mux.com/x.m3u8', 'pb"><script>1</script>', 'mux-video', [
                'thumbnail' => 'https://example.test/p.jpg?a=1&b="2"',
                'aspectRatio' => '16:9',
            ]), ['class' => 'a" onclick="x', 'aspectRatio' => '1; background: url(x)'], null, []],
        ];
    }

    public function testVideojsUnknownSkinFallsBackToConfiguredSkin(): void
    {
        $this->_settings->playerEngine = Settings::ENGINE_VIDEOJS;
        $this->_settings->videojsSkin = 'neutral';
        $this->_given(self::_manifest('mp4', 'https://example.test/v.mp4', '', 'video'), ['element' => 'video'], null, []);

        foreach (['glass', ''] as $skin) {
            $html = (string)(new Renderer())->player($this->_polymediaAsset(), ['skin' => $skin]);
            $this->assertStringContainsString('<video-neutral-skin ', $html, "skin '{$skin}'");
        }
    }

    public function testVideojsSkinHandlesMatchSettings(): void
    {
        $this->assertSame(Settings::VIDEOJS_SKINS, array_keys(VideoJs::SKINS));
    }

    public function testEngineFollowsEnvironmentVariable(): void
    {
        $this->_given(self::_manifest('mp4', 'https://example.test/v.mp4', '', 'video'), ['element' => 'video'], null, []);
        $this->_settings->playerEngine = '$POLYMEDIA_TEST_ENGINE';
        $_SERVER['POLYMEDIA_TEST_ENGINE'] = 'videojs';

        try {
            $this->assertStringStartsWith('<video-player>', (string)(new Renderer())->player($this->_polymediaAsset()));
        } finally {
            unset($_SERVER['POLYMEDIA_TEST_ENGINE']);
        }

        // Unset falls back to Media Chrome.
        $this->assertStringStartsWith('<media-controller>', (string)(new Renderer())->player($this->_polymediaAsset()));
    }

    /**
     * @dataProvider providerTypes
     */
    public function testEveryProviderRendersOnBothEngines(string $type): void
    {
        $element = (new UrlDetector())->getElementMap()[$type];
        $this->_given(self::_manifest($type, "https://example.test/{$type}", 'id1', $element), ['element' => $element], null, []);

        $this->assertStringContainsString("<{$element} ", (string)(new Renderer())->player($this->_polymediaAsset()));
        $this->assertTrue(
            in_array($type, ['mp4', 'audio'], true) || isset(MediaChrome::SCRIPT_MAP[$type]),
            "Media Chrome has no script for {$type}.",
        );

        $this->_settings->playerEngine = Settings::ENGINE_VIDEOJS;
        $tag = VideoJs::MEDIA[$type][0] ?? $element;
        $this->assertStringContainsString("<{$tag} ", (string)(new Renderer())->player($this->_polymediaAsset()));
        $this->assertTrue(
            in_array($type, ['mp4', 'audio'], true)
                || isset(VideoJs::MEDIA[$type])
                || isset(VideoJs::STANDALONE_PACKAGES[$type]),
            "Video.js has no script for {$type}.",
        );
    }

    public static function providerTypes(): array
    {
        $types = [];

        foreach ((new UrlDetector())->getProviderTypes() as $type) {
            $types[$type] = [$type];
        }

        return $types;
    }

    public function testMp4PreferenceRendersTheRenditionOnBothEngines(): void
    {
        $manifest = self::_manifest('mux', 'https://stream.mux.com/pb1.m3u8', 'pb1', 'mux-video', [
            'mp4Renditions' => [['url' => 'https://stream.mux.com/pb1/highest.mp4', 'status' => 'ready', 'width' => 1920, 'height' => 1080]],
        ]);
        $this->_given($manifest, ['element' => 'mux-video'], null, []);
        $options = ['poster' => false, 'mediaAttrs' => ['SRC' => 'bad', 'playback-id' => 'bad', 'type' => 'application/x-mpegURL', 'data-x' => '1']];

        // HLS stays the default and keeps the canonical stream.
        $hls = (string)(new Renderer())->element($this->_polymediaAsset(), $options);
        $this->assertStringContainsString('<mux-video playback-id="pb1"', $hls);
        $this->assertStringNotContainsString('bad', $hls);
        $this->assertStringContainsString('data-x="1"', $hls);

        $mediaChrome = (string)(new Renderer())->player($this->_polymediaAsset(), $options + ['playbackFormat' => 'mp4']);
        $this->assertStringContainsString('<media-controller><video src="https://stream.mux.com/pb1/highest.mp4" slot="media"', $mediaChrome);
        $this->assertStringNotContainsString('bad', $mediaChrome);
        $this->assertStringNotContainsString('application/x-mpegURL', $mediaChrome);

        $this->_settings->muxDefaultPlaybackFormat = Settings::FORMAT_MP4;
        $this->_settings->playerEngine = Settings::ENGINE_VIDEOJS;
        $videojs = (string)(new Renderer())->player($this->_polymediaAsset(), ['poster' => false]);
        $this->assertStringContainsString('<video src="https://stream.mux.com/pb1/highest.mp4"', $videojs);
        $this->assertStringNotContainsString('mux-video', $videojs);
        $this->assertStringNotContainsString('crossorigin', $videojs);

        // A template can still ask for HLS.
        $stream = (string)(new Renderer())->element($this->_polymediaAsset(), ['playbackFormat' => 'hls']);
        $this->assertStringContainsString('<mux-video src="https://stream.mux.com/pb1.m3u8"', $stream);
    }

    public function testMp4PreferenceFallsBackToHlsWithoutAReadyRendition(): void
    {
        $this->_settings->muxDefaultPlaybackFormat = Settings::FORMAT_MP4;
        $this->_given(self::_manifest('mux', 'https://stream.mux.com/pb1.m3u8', 'pb1', 'mux-video', [
            'mp4Renditions' => [['url' => 'https://stream.mux.com/pb1/highest.mp4', 'status' => 'preparing']],
        ]), ['element' => 'mux-video'], null, []);

        $this->assertStringContainsString('<mux-video slot="media" playback-id="pb1"', (string)(new Renderer())->player($this->_polymediaAsset()));
    }

    public function testScriptsAcceptAProviderList(): void
    {
        $this->assertSame(
            (string)(new Renderer())->scripts(['providers' => ['mux']]),
            (string)(new Renderer())->scripts(['mux']),
        );
    }

    /**
     * @dataProvider videojsElementCases
     */
    public function testVideojsElement(string $name, array $manifest, array $options): void
    {
        $this->_settings->playerEngine = Settings::ENGINE_VIDEOJS;
        $this->_given($manifest, ['element' => $manifest['element']], null, []);
        $this->_assertSnapshot("videojs-element-{$name}", (string)(new Renderer())->element($this->_polymediaAsset(), $options));
    }

    public static function videojsElementCases(): array
    {
        $mux = self::_manifest('mux', 'https://stream.mux.com/pb1.m3u8', 'pb1', 'mux-video', [
            'thumbnail' => 'https://image.mux.com/pb1/thumbnail.jpg?time=0',
        ]);

        return [
            'mux' => ['mux', $mux, []],
            'hls' => ['hls', self::_manifest('hls', 'https://example.test/v.m3u8', '', 'hls-video'), ['controls' => false, 'muted' => true]],
            'audio' => ['audio', self::_manifest('audio', 'https://example.test/a.mp3', '', 'audio'), []],
        ];
    }

    /**
     * @dataProvider videojsScriptCases
     */
    public function testVideojsScripts(string $name, array $settings, array $options, string $language = 'en-US'): void
    {
        $this->_settings->playerEngine = Settings::ENGINE_VIDEOJS;
        $this->_settings->setAttributes($settings, false);
        $this->_language = $language;
        $this->_assertSnapshot("videojs-scripts-{$name}", (string)(new Renderer())->scripts($options));
    }

    public static function videojsScriptCases(): array
    {
        return [
            'cdn defaults' => ['cdn-defaults', [], []],
            'providers' => ['providers', [], ['providers' => ['mux', 'audio', 'spotify', 'jwplayer', 'peertube', 'videojs', 'mp4', 'unknown']]],
            'skins and version options' => ['skins', ['videojsSkin' => 'compat'], ['skins' => ['default', 'neutral'], 'version' => '10.2.0', 'providers' => ['audio']]],
            'skins add to the setting' => ['skins-additive', [], ['skins' => 'neutral', 'providers' => []]],
            'skin setting' => ['skin-setting', ['videojsSkin' => 'neutral'], ['providers' => []]],
            'self-host' => ['self-host', [
                'scriptLoaderMode' => 'self-host',
                'selfHostBaseUrl' => 'https://example.test/videojs/',
            ], ['providers' => ['mux', 'jwplayer']]],
            'site locale' => ['locale-site', [], ['providers' => []], 'de-AT'],
            'locale off' => ['locale-off', [], ['providers' => [], 'locale' => false], 'de-AT'],
            'locale option' => ['locale-option', [], ['providers' => [], 'locale' => 'zh-tw']],
            'none' => ['none', ['scriptLoaderMode' => 'none'], []],
            'unknown mode' => ['unknown-mode', [], ['mode' => 'bundle']],
        ];
    }

    // Private Methods
    // =========================================================================

    /**
     * Builds a manifest in the shape `ManifestWriter::read()` returns.
     */
    private static function _manifest(
        string $type,
        string $url,
        string $providerId,
        string $element,
        array $metadata = [],
        string $title = 'Example',
    ): array {
        $manifest = [
            'polymedia' => '1.0',
            'type' => $type,
            'url' => $url,
            'title' => $title,
            'providerId' => $providerId,
            'element' => $element,
        ];

        if ($metadata !== []) {
            $manifest['metadata'] = $metadata;
        }

        return $manifest;
    }

    /**
     * Sets the state the mocked services return.
     */
    private function _given(array $manifest, array $record, ?string $posterUrl, array $tracks): void
    {
        $this->_manifest = $manifest;
        $this->_posterUrl = $posterUrl;
        $this->_tracks = $tracks;
        $this->_record = null;

        if ($record === []) {
            return;
        }

        $this->_record = new class() extends MediaItemRecord {
            public $id;
            public $element;
            public $defaults;

            public function attributes(): array
            {
                return ['id', 'element', 'defaults'];
            }
        };
        $this->_record->setAttributes($record + ['id' => 7, 'defaults' => null], false);
    }

    /**
     * Returns a mock `.pmedia` asset.
     */
    private function _polymediaAsset(): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = 70;
        $asset->kind = 'polymedia';

        return $asset;
    }

    /**
     * Returns a mock asset with a URL.
     */
    private function _asset(int $id, string $url): Asset
    {
        $asset = $this->createMock(Asset::class);
        $asset->id = $id;
        $asset->method('getUrl')->willReturn($url);

        return $asset;
    }

    /**
     * Compares output with its golden file, or rewrites it when `UPDATE_SNAPSHOTS` is set.
     */
    private function _assertSnapshot(string $name, string $html): void
    {
        $path = __DIR__ . '/snapshots/renderer/' . preg_replace('/[^a-z0-9-]+/', '-', strtolower($name)) . '.html';

        if (getenv('UPDATE_SNAPSHOTS')) {
            if (!is_dir(dirname($path))) {
                mkdir(dirname($path), 0777, true);
            }

            file_put_contents($path, $html . "\n");
        }

        $this->assertFileExists($path, "Missing snapshot {$name}. Run with UPDATE_SNAPSHOTS=1 to create it.");
        $this->assertSame(file_get_contents($path), $html . "\n", "Output changed for snapshot {$name}.");
    }
}
