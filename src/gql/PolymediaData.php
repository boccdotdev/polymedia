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

namespace boccdotdev\polymedia\gql;

use boccdotdev\polymedia\records\MediaItemRecord;

/**
 * Value object passed as the GraphQL source for the `PolymediaData` type.
 *
 * Carries the media item record plus the owner asset's site ID so track
 * resolution can default to the site the asset was queried in.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
final class PolymediaData
{
    // Public Methods
    // =========================================================================

    /**
     * @param MediaItemRecord $record the media item record
     * @param ?int $siteId the owner asset's site ID
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public function __construct(
        public readonly MediaItemRecord $record,
        public readonly ?int $siteId = null,
    ) {
    }
}
