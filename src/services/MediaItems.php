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

use boccdotdev\polymedia\db\Table;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\db\Query;
use yii\base\Component;

/**
 * CRUD service for polymedia item records.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class MediaItems extends Component
{
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
        return MediaItemRecord::findOne(['assetId' => $assetId]);
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
        return MediaItemRecord::findOne(['assetUid' => $assetUid]);
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
        return $record->save();
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
}
