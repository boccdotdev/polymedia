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

namespace boccdotdev\polymedia\gql\resolvers;

use boccdotdev\polymedia\gql\loaders\MediaItemLoader;
use boccdotdev\polymedia\gql\PolymediaData;
use craft\elements\Asset;
use GraphQL\Deferred;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Resolves the `polymedia` field on the Asset GraphQL interface.
 *
 * Non-`.pmedia` assets resolve to null without a database query; `.pmedia`
 * assets buffer into {@see MediaItemLoader} so any number of assets in a
 * query resolve with a single batch lookup.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
class PolymediaResolver
{
    // Public Methods
    // =========================================================================

    /**
     * Resolves an asset to its polymedia data, or null.
     *
     * @param mixed $source the parent data source (the asset element)
     * @param array $arguments arguments for resolving this field
     * @param mixed $context the context shared between all resolvers
     * @param ResolveInfo $resolveInfo the resolve information
     * @return mixed
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        if (!$source instanceof Asset || $source->kind !== 'polymedia' || !$source->id) {
            return null;
        }

        $assetId = (int)$source->id;
        $siteId = $source->siteId !== null ? (int)$source->siteId : null;

        MediaItemLoader::buffer($assetId);

        return new Deferred(function() use ($assetId, $siteId): ?PolymediaData {
            $record = MediaItemLoader::load($assetId);

            if ($record === null) {
                return null;
            }

            return new PolymediaData($record, $siteId);
        });
    }
}
