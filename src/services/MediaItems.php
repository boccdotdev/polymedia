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

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\records\MediaItemRecord;
use craft\helpers\Json;
use yii\base\Component;

/**
 * CRUD service for polymedia item records.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class MediaItems extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * Per-request cache of media item records keyed by asset ID.
     *
     * @var array<int, MediaItemRecord|null>
     */
    private array $_byAssetId = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the media item record for a given asset ID.
     *
     * @param int $assetId the asset ID
     * @return ?MediaItemRecord
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getByAssetId(int $assetId): ?MediaItemRecord
    {
        if (array_key_exists($assetId, $this->_byAssetId)) {
            return $this->_byAssetId[$assetId];
        }

        $record = MediaItemRecord::findOne(['assetId' => $assetId]);
        $this->_byAssetId[$assetId] = $record;

        return $record;
    }

    /**
     * Returns media item records for a batch of asset IDs, keyed by asset ID.
     *
     * Primes the per-request cache, including negative entries for asset IDs
     * with no record, so follow-up {@see getByAssetId()} calls skip the query.
     *
     * @param int[] $assetIds the asset IDs to look up
     * @return array<int, MediaItemRecord> keyed by asset ID; misses are omitted
     *
     * @author boccdotdev
     * @since 2.1.0
     */
    public function getByAssetIds(array $assetIds): array
    {
        $assetIds = array_values(array_unique(array_filter(
            array_map('intval', $assetIds),
            static fn(int $id) => $id > 0,
        )));

        if ($assetIds === []) {
            return [];
        }

        $map = [];
        $missing = [];

        foreach ($assetIds as $assetId) {
            if (!array_key_exists($assetId, $this->_byAssetId)) {
                $missing[] = $assetId;
                continue;
            }

            if ($this->_byAssetId[$assetId] !== null) {
                $map[$assetId] = $this->_byAssetId[$assetId];
            }
        }

        if ($missing === []) {
            return $map;
        }

        /** @var MediaItemRecord[] $records */
        $records = MediaItemRecord::find()
            ->where(['assetId' => $missing])
            ->all();

        foreach ($missing as $assetId) {
            $this->_byAssetId[$assetId] = null;
        }

        foreach ($records as $record) {
            $assetId = (int)$record->assetId;
            $this->_byAssetId[$assetId] = $record;
            $map[$assetId] = $record;
        }

        return $map;
    }

    /**
     * Returns the media item record by primary key.
     *
     * @param int $id the media item record ID
     * @return ?MediaItemRecord
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getById(int $id): ?MediaItemRecord
    {
        if ($id <= 0) {
            return null;
        }

        $record = MediaItemRecord::findOne(['id' => $id]);

        if ($record) {
            $this->_byAssetId[(int)$record->assetId] = $record;
        }

        return $record;
    }

    /**
     * Returns the media item record for a given asset UID.
     *
     * @param string $assetUid the asset UID
     * @return ?MediaItemRecord
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getByAssetUid(string $assetUid): ?MediaItemRecord
    {
        $record = MediaItemRecord::findOne(['assetUid' => $assetUid]);

        if ($record) {
            $this->_byAssetId[(int)$record->assetId] = $record;
        }

        return $record;
    }

    /**
     * Saves a media item record.
     *
     * @param MediaItemRecord $record the record to save
     * @return bool whether the save succeeded
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function save(MediaItemRecord $record): bool
    {
        $saved = $record->save();

        if ($saved && $record->assetId) {
            $this->_byAssetId[(int)$record->assetId] = $record;
        }

        return $saved;
    }

    /**
     * Deletes the media item record for a given asset ID.
     *
     * @param int $assetId the asset ID
     * @return int the number of rows deleted
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function deleteByAssetId(int $assetId): int
    {
        unset($this->_byAssetId[$assetId]);

        return MediaItemRecord::deleteAll(['assetId' => $assetId]);
    }

    /**
     * Returns whether a media item record exists for a given asset ID.
     *
     * @param int $assetId the asset ID
     * @return bool
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function existsForAsset(int $assetId): bool
    {
        if (array_key_exists($assetId, $this->_byAssetId)) {
            return $this->_byAssetId[$assetId] !== null;
        }

        return MediaItemRecord::find()->where(['assetId' => $assetId])->exists();
    }

    /**
     * Returns all media item records matching the given type.
     *
     * @param string $type the media type key
     * @return MediaItemRecord[]
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getByType(string $type): array
    {
        return MediaItemRecord::findAll(['type' => $type]);
    }

    /**
     * Returns the media item for a provider type + provider id (e.g. mux playback id).
     *
     * Used to reuse an existing `.pmedia` when re-importing from the Mux library
     * or completing an upload that already has a Craft asset.
     *
     * @param string $type media type key (`mux`, `youtube`, …)
     * @param string $providerId provider-specific id (Mux playback ID, etc.)
     * @return ?MediaItemRecord
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getByTypeAndProviderId(string $type, string $providerId): ?MediaItemRecord
    {
        if ($type === '' || $providerId === '') {
            return null;
        }

        $record = MediaItemRecord::findOne([
            'type' => $type,
            'providerId' => $providerId,
        ]);

        if ($record) {
            $this->_byAssetId[(int)$record->assetId] = $record;
        }

        return $record;
    }

    /**
     * Returns media item records keyed by providerId for a batch of ids (one type).
     *
     * @param string $type media type key
     * @param string[] $providerIds provider ids to look up
     * @return array<string, MediaItemRecord> keyed by providerId
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getByTypeAndProviderIds(string $type, array $providerIds): array
    {
        $providerIds = array_values(array_unique(array_filter($providerIds, static fn($id) => $id !== null && $id !== '')));

        if ($type === '' || $providerIds === []) {
            return [];
        }

        /** @var MediaItemRecord[] $records */
        $records = MediaItemRecord::find()
            ->where(['type' => $type, 'providerId' => $providerIds])
            ->all();

        $map = [];

        foreach ($records as $record) {
            $map[(string)$record->providerId] = $record;
            $this->_byAssetId[(int)$record->assetId] = $record;
        }

        return $map;
    }

    /**
     * Decodes a media item’s JSON `metadata` column to an array.
     *
     * @param MediaItemRecord $record
     * @return array<string, mixed>
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getMetadata(MediaItemRecord $record): array
    {
        return self::decodeMetadataJson($record->metadata);
    }

    /**
     * Decodes a raw metadata JSON string (or empty/null) to an array.
     *
     * @param mixed $raw value from the `metadata` column
     * @return array<string, mixed>
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public static function decodeMetadataJson(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (!is_string($raw)) {
            return [];
        }

        $decoded = Json::decodeIfJson($raw);

        return is_array($decoded) ? $decoded : [];
    }
}
