<?php

namespace boccdotdev\polymedia\services;

/**
 * Selects playback from persisted state only. Canonical manifests are never changed.
 */
final class PlaybackSources
{
    public static function resolve(array $manifest, array|object $settings = [], array $options = [], ?callable $sourceAssetUrl = null): array
    {
        $provider = $manifest['type'] ?? '';
        $url = $manifest['url'] ?? '';
        $metadata = is_array($manifest['metadata'] ?? null) ? $manifest['metadata'] : [];

        if ($provider === 'bunny' && (($metadata['bunnyStatus'] ?? null) === -1
            || ($metadata['bunnyPlaybackPolicy'] ?? null) === 'unsupported')) {
            return ['url' => '', 'element' => 'hls-video', 'format' => 'hls'];
        }

        if (SourceAssets::isReference($manifest)) {
            // A missing/deleted source must not fall back to a stale copied URL.
            $uid = $metadata['sourceAssetUid'] ?? null;
            $url = $provider === 'mp4' && is_string($uid) && $uid !== '' && $sourceAssetUrl
                ? ($sourceAssetUrl($uid) ?? '') : '';
            return ['url' => $url, 'element' => 'video', 'format' => 'mp4'];
        }

        $element = match ($provider) {
            'mux' => 'mux-video',
            'bunny' => 'hls-video',
            'mp4' => 'video',
            default => null,
        };
        $result = ['url' => $url, 'element' => $element, 'format' => $provider === 'mp4' ? 'mp4' : 'hls'];

        if (!in_array($provider, ['mux', 'bunny'], true)) {
            return $result;
        }

        $key = $provider . 'DefaultPlaybackFormat';
        $preference = is_array($settings) ? ($settings[$key] ?? 'hls') : ($settings->$key ?? 'hls');
        $format = in_array($options['playbackFormat'] ?? null, ['hls', 'mp4'], true) ? $options['playbackFormat'] : $preference;

        if ($format !== 'mp4') {
            return $result;
        }

        $best = null;
        $bestSize = [-1, -1];
        foreach ((array)($metadata['mp4Renditions'] ?? []) as $rendition) {
            if (!is_array($rendition) || ($rendition['status'] ?? null) !== 'ready'
                || !is_string($rendition['url'] ?? null)
                || !preg_match('~^https?://[^/\s]+(?:/|$)~i', $rendition['url'])) {
                continue;
            }
            $size = [max(0, (int)($rendition['height'] ?? 0)), max(0, (int)($rendition['width'] ?? 0))];
            if ($size > $bestSize) {
                $best = $rendition['url'];
                $bestSize = $size;
            }
        }

        return $best === null ? $result : ['url' => $best, 'element' => 'video', 'format' => 'mp4'];
    }
}
