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
use boccdotdev\polymedia\records\RelatedAssetRecord;
use craft\elements\Asset;

/**
 * Query-scoped batch loader for related asset rows and their asset URLs,
 * keyed by media item ID.
 *
 * GraphQL resolvers buffer item IDs while the executor walks the tree; the
 * first {@see rowsForItem()} call flushes the buffer with one query for the
 * rows and one for the referenced assets. State is reset per GraphQL query
 * execution from the plugin's `EVENT_BEFORE_EXECUTE_GQL_QUERY` handler.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
final class RelatedAssetLoader
{
    // Static Properties
    // =========================================================================

    /**
     * Item IDs queued for the next flush.
     *
     * @var array<int, true>
     */
    private static array $_buffer = [];

    /**
     * Loaded related asset rows keyed by item ID.
     *
     * @var array<int, RelatedAssetRecord[]>
     */
    private static array $_rows = [];

    /**
     * Resolved asset URLs (or null for misses) keyed by asset ID.
     *
     * @var array<int, ?string>
     */
    private static array $_assetUrls = [];

    // Public Methods
    // =========================================================================

    /**
     * Queues a media item ID for the next batch load.
     *
     * @param int $itemId the media item record ID
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function buffer(int $itemId): void
    {
        if (!array_key_exists($itemId, self::$_rows)) {
            self::$_buffer[$itemId] = true;
        }
    }

    /**
     * Returns the related asset rows for a media item, flushing the buffer first.
     *
     * @param int $itemId the media item record ID
     * @return RelatedAssetRecord[] ordered by sortOrder
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function rowsForItem(int $itemId): array
    {
        if (!array_key_exists($itemId, self::$_rows)) {
            self::buffer($itemId);
            self::_flush();
        }

        return self::$_rows[$itemId] ?? [];
    }

    /**
     * Returns the URL for an asset referenced by a loaded row.
     *
     * Only asset IDs seen during a {@see rowsForItem()} flush are available;
     * unknown IDs and assets without a URL both return null.
     *
     * @param int $assetId the asset ID
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function assetUrl(int $assetId): ?string
    {
        return self::$_assetUrls[$assetId] ?? null;
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
        self::$_rows = [];
        self::$_assetUrls = [];
    }

    // Private Methods
    // =========================================================================

    /**
     * Loads rows for every buffered item ID, then the referenced asset URLs.
     */
    private static function _flush(): void
    {
        $itemIds = array_keys(self::$_buffer);
        self::$_buffer = [];

        if ($itemIds === []) {
            return;
        }

        $rowsByItem = Plugin::getInstance()->getRelatedAssets()->getForItemIds($itemIds);
        $missingAssetIds = [];

        foreach ($itemIds as $itemId) {
            self::$_rows[$itemId] = $rowsByItem[$itemId] ?? [];

            foreach (self::$_rows[$itemId] as $row) {
                $assetId = (int)$row->assetId;

                if (!array_key_exists($assetId, self::$_assetUrls)) {
                    $missingAssetIds[$assetId] = true;
                }
            }
        }

        if ($missingAssetIds === []) {
            return;
        }

        $missingAssetIds = array_keys($missingAssetIds);

        foreach ($missingAssetIds as $assetId) {
            self::$_assetUrls[$assetId] = null;
        }

        /** @var Asset[] $assets */
        $assets = Asset::find()
            ->id($missingAssetIds)
            ->all();

        foreach ($assets as $asset) {
            self::$_assetUrls[(int)$asset->id] = $asset->getUrl();
        }
    }
}
