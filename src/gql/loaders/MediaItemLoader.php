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

namespace boccdotdev\polymedia\gql\loaders;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;

/**
 * Query-scoped batch loader for media item records keyed by asset ID.
 *
 * GraphQL resolvers buffer asset IDs while the executor walks the tree; the
 * first {@see load()} call flushes the buffer with a single query. State is
 * reset per GraphQL query execution from the plugin's
 * `EVENT_BEFORE_EXECUTE_GQL_QUERY` handler.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
final class MediaItemLoader
{
    // Static Properties
    // =========================================================================

    /**
     * Asset IDs queued for the next flush.
     *
     * @var array<int, true>
     */
    private static array $_buffer = [];

    /**
     * Loaded records (or null for misses) keyed by asset ID.
     *
     * @var array<int, ?MediaItemRecord>
     */
    private static array $_loaded = [];

    // Public Methods
    // =========================================================================

    /**
     * Queues an asset ID for the next batch load.
     *
     * @param int $assetId the asset ID
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function buffer(int $assetId): void
    {
        if (!array_key_exists($assetId, self::$_loaded)) {
            self::$_buffer[$assetId] = true;
        }
    }

    /**
     * Returns the media item record for an asset ID, flushing the buffer first.
     *
     * @param int $assetId the asset ID
     * @return ?MediaItemRecord
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function load(int $assetId): ?MediaItemRecord
    {
        if (!array_key_exists($assetId, self::$_loaded)) {
            self::buffer($assetId);
            self::_flush();
        }

        return self::$_loaded[$assetId] ?? null;
    }

    /**
     * Clears all buffered and loaded state.
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function reset(): void
    {
        self::$_buffer = [];
        self::$_loaded = [];
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads every buffered asset ID with a single query.
     */
    private static function _flush(): void
    {
        $assetIds = array_keys(self::$_buffer);
        self::$_buffer = [];

        if ($assetIds === []) {
            return;
        }

        $records = Plugin::getInstance()->getMediaItems()->getByAssetIds($assetIds);

        foreach ($assetIds as $assetId) {
            self::$_loaded[$assetId] = $records[$assetId] ?? null;
        }
    }
}
