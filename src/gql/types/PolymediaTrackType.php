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

namespace boccdotdev\polymedia\gql\types;

use Craft;
use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL object type for a caption, subtitle, or description track.
 *
 * Sources are associative arrays assembled by
 * {@see PolymediaDataType::resolve()}, so the base array-key resolver applies.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
class PolymediaTrackType extends ObjectType
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the GraphQL type name.
     *
     * @return string
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function getName(): string
    {
        return 'PolymediaTrack';
    }

    /**
     * Returns the registered type instance, creating it on first use.
     *
     * @return Type
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function getType(): Type
    {
        return GqlEntityRegistry::getOrCreate(self::getName(), fn() => new self([
            'name' => self::getName(),
            'fields' => self::class . '::getFieldDefinitions',
            'description' => 'A caption, subtitle, or description track attached to a polymedia item.',
        ]));
    }

    /**
     * Returns the field definitions, prepared so other plugins can extend them.
     *
     * @return array
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public static function getFieldDefinitions(): array
    {
        return Craft::$app->getGql()->prepareFieldDefinitions([
            'kind' => [
                'name' => 'kind',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The track kind (`captions`, `subtitles`, or `descriptions`).',
            ],
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The URL of the track’s VTT asset.',
            ],
            'srclang' => [
                'name' => 'srclang',
                'type' => Type::string(),
                'description' => 'The track language code.',
            ],
            'label' => [
                'name' => 'label',
                'type' => Type::string(),
                'description' => 'The human-readable track label.',
            ],
            'isDefault' => [
                'name' => 'isDefault',
                'type' => Type::nonNull(Type::boolean()),
                'description' => 'Whether this is the default track for its kind.',
            ],
            'siteId' => [
                'name' => 'siteId',
                'type' => Type::int(),
                'description' => 'The site ID the track is scoped to.',
            ],
        ], self::getName());
    }
}
