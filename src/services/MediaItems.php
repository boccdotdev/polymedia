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

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
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
    // Const Properties
    // =========================================================================

    /**
     * Seconds to wait for a media item's write lock before giving up.
     *
     * Writers that lose the wait skip their write (and log); upstream sources
     * re-deliver or re-sync, so skipping beats corrupting a concurrent write.
     */
    private const LOCK_TIMEOUT = 15;

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
     * @param array<int, string|null> $providerIds provider ids to look up
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
     * Returns the mux-type media item whose metadata holds this Mux asset id.
     *
     * The playback id lives in the indexed `providerId` column; the Mux asset
     * id only exists inside the metadata JSON, so this matches on the encoded
     * key/value pair. Prefer {@see getByTypeAndProviderId()} when a playback
     * id is available.
     *
     * @param string $muxAssetId the Mux asset id
     * @return ?MediaItemRecord
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function getByMuxAssetId(string $muxAssetId): ?MediaItemRecord
    {
        if ($muxAssetId === '') {
            return null;
        }

        /** @var ?MediaItemRecord $record */
        $record = MediaItemRecord::find()
            ->where(['type' => 'mux'])
            ->andWhere(['like', 'metadata', '%"muxAssetId":' . Json::encode($muxAssetId) . '%', false])
            ->one();

        if ($record) {
            $this->_byAssetId[(int)$record->assetId] = $record;
        }

        return $record;
    }

    /**
     * Runs `$fn` while holding the media item's write lock.
     *
     * Media item writes are read-modify-write on the `metadata` JSON column
     * and can run concurrently (CP request, queue job, console sync, webhook
     * delivery). The lock serializes them per item. When the lock cannot be
     * acquired within {@see self::LOCK_TIMEOUT}, the write is skipped: a
     * warning is logged and `null` is returned without calling `$fn`.
     *
     * Locks are not re-entrant — never call this from inside `$fn` for the
     * same item.
     *
     * @param MediaItemRecord|int $item the media item (or its record id)
     * @param callable $fn the guarded write
     * @return mixed `$fn`'s return value, or `null` when the lock was not acquired
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function withItemLock(MediaItemRecord|int $item, callable $fn): mixed
    {
        $id = $item instanceof MediaItemRecord ? (int)$item->id : (int)$item;
        $key = "polymedia:item:{$id}";
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, self::LOCK_TIMEOUT)) {
            Craft::warning("Could not acquire {$key} within " . self::LOCK_TIMEOUT . 's; write skipped.', __METHOD__);

            return null;
        }

        try {
            return $fn();
        } finally {
            $mutex->release($key);
        }
    }

    /**
     * Merges keys into a media item's metadata under its write lock.
     *
     * Re-reads the row inside the lock so a concurrent writer's keys survive
     * the merge, then saves. The passed record instance is refreshed with the
     * saved metadata on success.
     *
     * @param MediaItemRecord $record the media item
     * @param array<string, mixed> $patch metadata keys to set
     * @return ?array<string, mixed> the merged metadata, or `null` when the
     *                               row vanished or the lock was not acquired
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function patchMetadata(MediaItemRecord $record, array $patch): ?array
    {
        if ($patch === []) {
            return $this->getMetadata($record);
        }

        return $this->withItemLock($record, function() use ($record, $patch): ?array {
            /** @var ?MediaItemRecord $fresh */
            $fresh = MediaItemRecord::findOne(['id' => $record->id]);

            if (!$fresh) {
                return null;
            }

            $metadata = array_merge(self::decodeMetadataJson($fresh->metadata), $patch);
            $fresh->metadata = Json::encode($metadata);

            if (!$this->save($fresh)) {
                return null;
            }

            $record->metadata = $fresh->metadata;

            return $metadata;
        });
    }

    /**
     * Applies current Mux asset state to the matching media item.
     *
     * The one idempotent, lock-guarded transition shared by the CP
     * import/upload flow, console sync command, and webhook endpoint: stores
     * `muxStatus`/`muxAssetId` metadata, updates the duration, and kicks the
     * poster fetcher once the asset is ready.
     *
     * @param string $muxAssetId the Mux asset id
     * @param array $state mapped asset state ({@see Mux::mapAsset()}): uses
     *                     `status`, `duration`, `playbackId`
     * @param ?MediaItemRecord $record the media item, when the caller already
     *                                 has it; otherwise looked up by playback
     *                                 id, then by Mux asset id
     * @return ?MediaItemRecord the updated record, or `null` when no media
     *                          item matches this Mux asset
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function applyMuxAssetState(string $muxAssetId, array $state, ?MediaItemRecord $record = null): ?MediaItemRecord
    {
        $playbackId = isset($state['playbackId']) ? (string)$state['playbackId'] : '';

        $record ??= ($playbackId !== '' ? $this->getByTypeAndProviderId('mux', $playbackId) : null)
            ?? $this->getByMuxAssetId($muxAssetId);

        if (!$record) {
            return null;
        }

        $this->withItemLock($record, function() use ($record, $muxAssetId, $state): void {
            /** @var ?MediaItemRecord $fresh */
            $fresh = MediaItemRecord::findOne(['id' => $record->id]);

            if (!$fresh) {
                return;
            }

            $merge = self::mergeMuxAssetState(
                self::decodeMetadataJson($fresh->metadata),
                $fresh->duration !== null ? (int)$fresh->duration : null,
                $muxAssetId,
                $state,
            );

            if (!$merge['changed']) {
                return;
            }

            $fresh->metadata = Json::encode($merge['metadata']);
            $fresh->duration = $merge['duration'];

            if ($this->save($fresh)) {
                $record->metadata = $fresh->metadata;
                $record->duration = $fresh->duration;
            }
        });

        // Outside the lock: poster ensure is internally idempotent (no-ops
        // when a poster is attached, probes the CDN, queues retries while the
        // still isn't ready) and may do HTTP — keep it off the critical path.
        // Skipped for errored/deleted assets, which will never produce a frame.
        if (!in_array($state['status'] ?? null, ['errored', 'deleted'], true)) {
            Plugin::getInstance()->getPosterFetcher()->ensureMuxPoster($record, $playbackId ?: null);
        }

        return $record;
    }

    /**
     * Pure merge of incoming Mux asset state into metadata + duration.
     *
     * @param array<string, mixed> $metadata current metadata
     * @param ?int $duration current duration in seconds
     * @param string $muxAssetId the Mux asset id
     * @param array $state incoming state (`status`, `duration`)
     * @return array{metadata: array<string, mixed>, duration: ?int, changed: bool}
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public static function mergeMuxAssetState(array $metadata, ?int $duration, string $muxAssetId, array $state): array
    {
        $changed = false;

        if ($muxAssetId !== '' && ($metadata['muxAssetId'] ?? null) !== $muxAssetId) {
            $metadata['muxAssetId'] = $muxAssetId;
            $changed = true;
        }

        $status = isset($state['status']) ? (string)$state['status'] : '';

        if ($status !== '' && ($metadata['muxStatus'] ?? null) !== $status) {
            $metadata['muxStatus'] = $status;
            $changed = true;
        }

        if (isset($state['duration']) && is_numeric($state['duration'])) {
            $incoming = (int)round((float)$state['duration']);

            if ($incoming > 0 && $incoming !== $duration) {
                $duration = $incoming;
                $changed = true;
            }
        }

        return ['metadata' => $metadata, 'duration' => $duration, 'changed' => $changed];
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
