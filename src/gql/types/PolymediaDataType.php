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

use boccdotdev\polymedia\gql\loaders\RelatedAssetLoader;
use boccdotdev\polymedia\gql\PolymediaData;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\EditorContent;
use boccdotdev\polymedia\services\MediaItems;
use Craft;
use craft\gql\base\ObjectType;
use craft\gql\GqlEntityRegistry;
use craft\helpers\Json;
use GraphQL\Deferred;
use GraphQL\Type\Definition\ResolveInfo;
use GraphQL\Type\Definition\Type;

/**
 * GraphQL object type for a polymedia item's data.
 *
 * Scalar fields resolve straight off the media item record; poster, tracks,
 * and transcript resolve through {@see RelatedAssetLoader} deferreds so any
 * number of items in a query cost a fixed number of database queries. All
 * resolution is DB-only — the `.pmedia` manifest file is never read.
 *
 * @author boccdotdev
 * @since 2.1.0
 */
class PolymediaDataType extends ObjectType
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
        return 'PolymediaData';
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
            'description' => 'Polymedia data for a `.pmedia` media asset.',
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
            'type' => [
                'name' => 'type',
                'type' => Type::nonNull(Type::string()),
                'description' => 'The media type key (`mux`, `youtube`, `vimeo`, `hls`, …).',
            ],
            'url' => [
                'name' => 'url',
                'type' => Type::string(),
                'description' => 'The media URL.',
            ],
            'providerId' => [
                'name' => 'providerId',
                'type' => Type::string(),
                'description' => 'The provider-specific ID (Mux playback ID, YouTube video ID, …).',
            ],
            'element' => [
                'name' => 'element',
                'type' => Type::string(),
                'description' => 'The media element tag (`mux-video`, `youtube-video`, `video`, …).',
            ],
            'title' => [
                'name' => 'title',
                'type' => Type::string(),
                'description' => 'The media title.',
            ],
            'duration' => [
                'name' => 'duration',
                'type' => Type::int(),
                'description' => 'The duration in seconds, if known.',
            ],
            'width' => [
                'name' => 'width',
                'type' => Type::int(),
                'description' => 'The video width in pixels, if known.',
            ],
            'height' => [
                'name' => 'height',
                'type' => Type::int(),
                'description' => 'The video height in pixels, if known.',
            ],
            'poster' => [
                'name' => 'poster',
                'type' => Type::string(),
                'description' => 'The poster URL: the attached poster asset, falling back to the remote thumbnail.',
            ],
            'tracks' => [
                'name' => 'tracks',
                'type' => Type::listOf(PolymediaTrackType::getType()),
                'args' => [
                    'role' => [
                        'name' => 'role',
                        'type' => Type::listOf(Type::nonNull(Type::string())),
                        'description' => 'Track kinds to include (`captions`, `subtitles`, `descriptions`). Defaults to all.',
                    ],
                    'siteId' => [
                        'name' => 'siteId',
                        'type' => Type::int(),
                        'description' => 'Site ID to filter tracks by. Defaults to the owner asset’s site.',
                    ],
                ],
                'description' => 'The caption, subtitle, and description tracks.',
            ],
            'transcriptUrl' => [
                'name' => 'transcriptUrl',
                'type' => Type::string(),
                'description' => 'The URL of the attached transcript asset.',
            ],
            'metadata' => [
                'name' => 'metadata',
                'type' => Type::string(),
                'description' => 'The raw metadata as a JSON-encoded object (thumbnail, hints, provider extras).',
            ],
        ], self::getName());
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function resolve(mixed $source, array $arguments, mixed $context, ResolveInfo $resolveInfo): mixed
    {
        /** @var PolymediaData $source */
        $record = $source->record;

        return match ($resolveInfo->fieldName) {
            'type' => (string)$record->type,
            'url' => $this->_nullableString($record->url),
            'providerId' => $this->_nullableString($record->providerId),
            'element' => $this->_nullableString($record->element),
            'title' => $this->_nullableString($record->title),
            'duration' => $record->duration !== null ? (int)$record->duration : null,
            'width' => $record->width !== null ? (int)$record->width : null,
            'height' => $record->height !== null ? (int)$record->height : null,
            'poster' => $this->_resolvePoster($source),
            'tracks' => $this->_resolveTracks($source, $arguments),
            'transcriptUrl' => $this->_resolveTranscriptUrl($source),
            'metadata' => $this->_resolveMetadata($record),
            default => null,
        };
    }

    // Private Methods
    // =========================================================================

    /**
     * Resolves the poster URL: attached poster asset first, then the remote
     * thumbnail cached in the record's metadata. Mirrors the Twig-side
     * priority chain in `Renderer::_resolvePoster()` without touching the
     * manifest file.
     *
     * @param PolymediaData $source the resolver source
     * @return Deferred resolving to `?string`
     */
    private function _resolvePoster(PolymediaData $source): Deferred
    {
        $itemId = (int)$source->record->id;
        RelatedAssetLoader::buffer($itemId);

        return new Deferred(function() use ($source, $itemId): ?string {
            $url = $this->_firstUrlForRole($itemId, 'poster');

            if ($url !== null) {
                return $url;
            }

            $metadata = MediaItems::decodeMetadataJson($source->record->metadata);
            $thumbnail = $metadata['thumbnail'] ?? null;

            if (!is_string($thumbnail) || $thumbnail === '') {
                return null;
            }

            return $thumbnail;
        });
    }

    /**
     * Resolves the track list, filtered by role and site ID.
     *
     * @param PolymediaData $source the resolver source
     * @param array $arguments the field arguments (`role`, `siteId`)
     * @return Deferred resolving to a list of track arrays
     */
    private function _resolveTracks(PolymediaData $source, array $arguments): Deferred
    {
        $itemId = (int)$source->record->id;
        RelatedAssetLoader::buffer($itemId);

        return new Deferred(function() use ($source, $arguments, $itemId): array {
            $roles = array_keys(EditorContent::TRACK_ROLES);

            if (!empty($arguments['role'])) {
                $requested = array_map('strval', (array)$arguments['role']);
                $roles = array_values(array_intersect($roles, $requested));
            }

            $siteId = $source->siteId;

            if (isset($arguments['siteId'])) {
                $siteId = (int)$arguments['siteId'];
            }

            $tracks = [];

            foreach (RelatedAssetLoader::rowsForItem($itemId) as $row) {
                if (!in_array($row->role, $roles, true)) {
                    continue;
                }

                if ($siteId !== null && (int)$row->siteId !== $siteId) {
                    continue;
                }

                $tracks[] = [
                    'kind' => (string)$row->role,
                    'url' => RelatedAssetLoader::assetUrl((int)$row->assetId),
                    'srclang' => $this->_nullableString($row->srclang),
                    'label' => $this->_nullableString($row->label),
                    'isDefault' => (bool)$row->isDefault,
                    'siteId' => $row->siteId !== null ? (int)$row->siteId : null,
                ];
            }

            return $tracks;
        });
    }

    /**
     * Resolves the transcript asset URL.
     *
     * @param PolymediaData $source the resolver source
     * @return Deferred resolving to `?string`
     */
    private function _resolveTranscriptUrl(PolymediaData $source): Deferred
    {
        $itemId = (int)$source->record->id;
        RelatedAssetLoader::buffer($itemId);

        return new Deferred(fn(): ?string => $this->_firstUrlForRole($itemId, 'transcript'));
    }

    /**
     * Returns the JSON-encoded metadata, or null when empty.
     *
     * @param MediaItemRecord $record the media item record
     * @return ?string
     */
    private function _resolveMetadata(MediaItemRecord $record): ?string
    {
        $metadata = MediaItems::decodeMetadataJson($record->metadata);

        if ($metadata === []) {
            return null;
        }

        return Json::encode($metadata);
    }

    /**
     * Returns the first resolvable asset URL among an item's rows for a role.
     *
     * @param int $itemId the media item record ID
     * @param string $role the related asset role
     * @return ?string
     */
    private function _firstUrlForRole(int $itemId, string $role): ?string
    {
        foreach (RelatedAssetLoader::rowsForItem($itemId) as $row) {
            if ($row->role !== $role) {
                continue;
            }

            $url = RelatedAssetLoader::assetUrl((int)$row->assetId);

            if ($url !== null) {
                return $url;
            }
        }

        return null;
    }

    /**
     * Casts a record value to string, mapping empty strings to null.
     *
     * @param mixed $value the raw record value
     * @return ?string
     */
    private function _nullableString(mixed $value): ?string
    {
        $value = (string)$value;

        if ($value === '') {
            return null;
        }

        return $value;
    }
}
