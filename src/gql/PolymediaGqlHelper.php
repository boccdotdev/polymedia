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

use craft\helpers\Gql;
use craft\models\GqlSchema;

/**
 * GraphQL helper for checking polymedia schema components.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
class PolymediaGqlHelper extends Gql
{
    // Public Methods
    // =========================================================================

    /**
     * Returns whether the schema allows querying polymedia data.
     *
     * @param ?GqlSchema $schema the schema to check; defaults to the active schema
     * @return bool
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function canQueryPolymedia(?GqlSchema $schema = null): bool
    {
        return isset(self::extractAllowedEntitiesFromSchema('read', $schema)['polymedia']);
    }
}
