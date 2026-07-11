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
}
