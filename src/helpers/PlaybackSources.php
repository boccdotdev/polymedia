<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * Universal media field for Craft CMS — HLS, YouTube, Vimeo, Spotify, MP4
 * and audio as first-class assets, with Media Chrome compatible player rendering.
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\helpers;

use boccdotdev\polymedia\models\Settings;

/**
 * Picks what a player loads for an item: its stream, or an MP4 rendition.
 *
 * Works from stored state only, so rendering never calls a provider API, and
 * never changes the item's canonical (HLS) URL.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class PlaybackSources
{
    // Const Properties
    // =========================================================================

    /**
     * @var array<string, string> Hosted providers to their HLS element.
     */
    public const HOSTED_ELEMENTS = [
        Settings::PROVIDER_MUX => 'mux-video',
        Settings::PROVIDER_BUNNY => 'hls-video',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Resolves the playback source for a manifest.
     *
     * Mux and Bunny items follow the `playbackFormat` option, else the
     * provider's default. MP4 picks the highest ready rendition and falls
     * back to HLS when none is ready. Other providers play their URL as is.
     *
     * @param array $manifest the manifest from `Renderer::data()`
     * @param ?Settings $settings the plugin settings, for provider defaults
     * @param array $options template options (`playbackFormat`: `hls` or `mp4`)
     * @return array{url: string, element: ?string, format: ?string} `url` is
     *         empty when the item can't play; `element` is null when the
     *         stored element applies; `format` is `hls`, `mp4` or null for
     *         providers that aren't a stream or a file
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function resolve(array $manifest, ?Settings $settings = null, array $options = []): array
    {
        $type = (string)($manifest['type'] ?? '');
        $url = is_string($manifest['url'] ?? null) ? $manifest['url'] : '';
        $metadata = is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [];

        if ($type === 'mp4') {
            return ['url' => $url, 'element' => 'video', 'format' => Settings::FORMAT_MP4];
        }

        if (!isset(self::HOSTED_ELEMENTS[$type])) {
            return ['url' => $url, 'element' => null, 'format' => $type === 'hls' ? Settings::FORMAT_HLS : null];
        }

        // A deleted or protected Bunny video must not fall back to stale URLs.
        if ($type === Settings::PROVIDER_BUNNY
            && (($metadata['bunnyStatus'] ?? null) === -1 || ($metadata['bunnyPlaybackPolicy'] ?? null) === 'unsupported')
        ) {
            return ['url' => '', 'element' => self::HOSTED_ELEMENTS[$type], 'format' => Settings::FORMAT_HLS];
        }

        $stream = ['url' => $url, 'element' => self::HOSTED_ELEMENTS[$type], 'format' => Settings::FORMAT_HLS];
        $option = $options['playbackFormat'] ?? null;
        $format = in_array($option, Settings::PLAYBACK_FORMATS, true)
            ? $option
            : ($settings ?? new Settings())->getDefaultPlaybackFormat($type);

        if ($format !== Settings::FORMAT_MP4) {
            return $stream;
        }

        $rendition = self::highestReadyRendition((array)($metadata['mp4Renditions'] ?? []));

        return $rendition === null
            ? $stream
            : ['url' => $rendition, 'element' => 'video', 'format' => Settings::FORMAT_MP4];
    }

    /**
     * Returns the URL of the tallest (then widest) ready MP4 rendition.
     *
     * @param array $renditions stored renditions (`url`, `status`, `width`, `height`)
     * @return ?string null when none is ready with an absolute HTTP(S) URL
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function highestReadyRendition(array $renditions): ?string
    {
        $best = null;
        $bestSize = [-1, -1];

        foreach ($renditions as $rendition) {
            if (!is_array($rendition)
                || ($rendition['status'] ?? null) !== 'ready'
                || !is_string($rendition['url'] ?? null)
                || !preg_match('~^https?://[^/\s]+(?:/|$)~i', $rendition['url'])
            ) {
                continue;
            }

            $size = [max(0, (int)($rendition['height'] ?? 0)), max(0, (int)($rendition['width'] ?? 0))];

            if ($size > $bestSize) {
                $best = $rendition['url'];
                $bestSize = $size;
            }
        }

        return $best;
    }
}
