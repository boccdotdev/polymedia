<?php

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\jobs\FetchBunnyPoster;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Refresh only existing imports. Event payloads are notifications, not state.
 */
class BunnySync extends Component
{
    public function syncVideo(string $libraryId, string $videoId): ?MediaItemRecord
    {
        $id = Bunny::playbackId($libraryId, $videoId);
        $record = Plugin::getInstance()->getMediaItems()->getByTypeAndProviderId('bunny', $id);
        return $record ? $this->syncRecord($record) : null;
    }

    public function syncRecord(MediaItemRecord $record): ?MediaItemRecord
    {
        $plugin = Plugin::getInstance();
        $items = $plugin->getMediaItems();
        $snapshot = $items->withItemLock($record, function() use ($record, $plugin, $items): array {
            $fresh = $items->getById((int)$record->id);
            if (!$fresh) {
                return ['record' => null];
            }
            $metadata = $items->getMetadata($fresh);
            $libraryId = (string)($metadata['bunnyLibraryId'] ?? '');
            $videoId = (string)($metadata['bunnyVideoId'] ?? '');
            $host = (string)($metadata['bunnyCdnHostname'] ?? '');
            if ($fresh->type !== 'bunny'
                || $libraryId !== $plugin->getBunny()->getLibraryId()
                || Bunny::playbackId($libraryId, $videoId) !== $fresh->providerId
            ) {
                throw new \RuntimeException('This import belongs to a different Bunny library. Its stored identity has not been changed.');
            }
            Bunny::validateHostname($host);
            return [
                'record' => $fresh,
                'videoId' => $videoId,
                'host' => $host,
                'version' => (int)($metadata['bunnySyncVersion'] ?? 0),
            ];
        });
        if ($snapshot === null) {
            throw new \RuntimeException('Bunny media item is busy. Retry synchronization.');
        }
        if (!$snapshot['record']) {
            return null;
        }

        // Network requests never hold the editor's item lock. A version check
        // rejects overlapping stale responses so the caller can retry safely.
        $state = $plugin->getBunny()->getAsset($snapshot['videoId'], $snapshot['host']);
        if ($state['status'] === 'ready' && $state['isPublic'] === null) {
            throw new \RuntimeException('Bunny playback has not reached the CDN yet. Retry synchronization.');
        }
        $result = $items->withItemLock($record, function() use ($record, $plugin, $items, $snapshot, $state): array {
            $fresh = $items->getById((int)$record->id);
            if (!$fresh) {
                return ['record' => null];
            }
            $metadata = $items->getMetadata($fresh);
            if ((int)($metadata['bunnySyncVersion'] ?? 0) !== $snapshot['version']) {
                throw new \RuntimeException('Another Bunny synchronization completed first. Retry to fetch the current state.');
            }
            $merge = self::mergeState($metadata, $fresh->duration !== null ? (int)$fresh->duration : null, $state);
            $merge['metadata']['bunnySyncVersion'] = $snapshot['version'] + 1;
            $fresh->metadata = Json::encode($merge['metadata']);
            $fresh->duration = $merge['duration'];
            foreach (['width', 'height'] as $dimension) {
                if (($state[$dimension] ?? 0) > 0) {
                    $fresh->$dimension = (int)$state[$dimension];
                }
            }
            if (!$items->save($fresh)) {
                throw new \RuntimeException('Could not save Bunny synchronization state.');
            }
            // Manifest writes remain within the same lock as the database write.
            $asset = Craft::$app->getAssets()->getAssetById((int)$fresh->assetId);
            if ($asset) {
                $plugin->getManifestWriter()->update($asset, ['metadata' => $merge['metadata']]);
            }
            return ['record' => $fresh];
        });
        if ($result === null) {
            throw new \RuntimeException('Bunny media item is busy. Retry synchronization.');
        }
        $fresh = $result['record'];
        if ($fresh) {
            $metadata = $items->getMetadata($fresh);
            if (!empty($metadata['bunnyThumbnailUrl'])
                && ($metadata['bunnyPlaybackPolicy'] ?? null) === 'public'
                && !$plugin->getRelatedAssets()->getPoster((int)$fresh->id)
            ) {
                Craft::$app->getQueue()->push(new FetchBunnyPoster([
                    'itemId' => (int)$fresh->id,
                    'providerId' => (string)$fresh->providerId,
                ]));
            }
        }
        return $fresh;
    }

    /** Provider state cannot change identity, title, thumbnail or editor fields. */
    public static function mergeState(array $metadata, ?int $duration, array $state): array
    {
        foreach (['bunnyLibraryId', 'bunnyVideoId', 'bunnyCdnHostname'] as $key) {
            if (!isset($metadata[$key], $state[$key]) || $metadata[$key] !== $state[$key]) {
                throw new \RuntimeException('Bunny synchronization cannot retarget an imported video.');
            }
        }
        $metadata['bunnyStatus'] = $state['bunnyStatus'];
        $metadata['bunnyPlaybackPolicy'] = $state['playbackPolicy'];
        if (array_key_exists('mp4Renditions', $state)) {
            $metadata['mp4Renditions'] = $state['mp4Renditions'];
        }
        $metadata['bunnyThumbnailUrl'] = $state['thumbnailUrl'];
        if (empty($metadata['thumbnail']) && !empty($state['thumbnailUrl'])) {
            // Retain a late-arriving remote poster even without sidecar storage.
            // Never replace an editor's poster URL.
            $metadata['thumbnail'] = $state['thumbnailUrl'];
        }
        if (isset($state['duration']) && is_numeric($state['duration']) && $state['duration'] > 0) {
            $duration = (int)round((float)$state['duration']);
        }
        return ['metadata' => $metadata, 'duration' => $duration];
    }
}
