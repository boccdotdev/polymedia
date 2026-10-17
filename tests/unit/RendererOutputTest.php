<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

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
        $sites->method('getCurrentSite')->willReturn(new Site(['id' => 1]));
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
