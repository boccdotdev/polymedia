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

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\models\VolumeFolder;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

/**
 * Imports Bunny Stream videos as `.pmedia` assets and refreshes imported ones.
 *
 * Every state change goes through {@see MediaItems::applyBunnyVideoState()}.
 * Sync only refreshes existing imports; it never imports or enumerates a
 * remote library, and webhook payloads are notifications, not state.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnySync extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Seconds to wait for another import of the same video.
     */
    private const IMPORT_LOCK_TIMEOUT = 15;

    // Public Methods
    // =========================================================================

    /**
     * Imports a library video into a folder, or reuses its existing `.pmedia`.
     *
     * A new import requires a ready video with anonymous native HLS playback.
     * The library ID, video ID and delivery host are pinned to the item.
     *
     * @param string $videoId the Bunny video ID
     * @param VolumeFolder $folder the destination for a new `.pmedia`
     * @param User $user the user importing
     * @param string $title optional title; Bunny's title otherwise
     * @return array{asset: Asset, reused: bool}
     * @throws ForbiddenHttpException when the user can't view the existing item
     * @throws \RuntimeException when the video isn't playable yet, is busy, or can't be saved
     * @throws \InvalidArgumentException when the video ID is invalid
     * @throws \Throwable when the manifest asset can't be saved
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function importVideo(string $videoId, VolumeFolder $folder, User $user, string $title = ''): array
    {
        $plugin = Plugin::getInstance();
        $bunny = $plugin->getBunny();
        $providerId = Bunny::playbackId($bunny->getLibraryId(), Bunny::validateVideoId($videoId));
        $lock = "polymedia:bunny-import:{$providerId}";
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($lock, self::IMPORT_LOCK_TIMEOUT)) {
            throw new \RuntimeException('This Bunny video is being imported. Retry in a moment.');
        }

        try {
            $items = $plugin->getMediaItems();
            $record = $items->getByTypeAndProviderId('bunny', $providerId);
            $asset = $record ? Craft::$app->getAssets()->getAssetById((int)$record->assetId) : null;

            if ($record && $asset) {
                if (!$asset->canView($user)) {
                    throw new ForbiddenHttpException('You cannot access the existing media item.');
                }

                if (!$this->syncRecord($record)) {
                    throw new \RuntimeException('The media item disappeared during import. Retry.');
                }

                return ['asset' => $asset, 'reused' => true];
            }

            $state = $bunny->getAsset($videoId);

            if ($state['isPublic'] === false) {
                throw new \RuntimeException(Bunny::PROTECTED_PLAYBACK_MESSAGE);
            }

            if ($state['isPublic'] !== true) {
                throw new \RuntimeException('Wait until Bunny finishes processing this video, then retry.');
            }

            $title = trim($title) ?: ((string)$state['title'] ?: "Bunny {$videoId}");
            $asset = $plugin->getManifestWriter()->create(
                volumeId: $folder->volumeId,
                folderId: (int)$folder->id,
                detection: new DetectionResult([
                    'type' => 'bunny',
                    'providerId' => $providerId,
                    'url' => $state['url'],
                    'element' => 'hls-video',
                ]),
                title: $title,
                derivedThumbnail: $state['thumbnailUrl'],
                extraMetadata: [
                    'bunnyLibraryId' => $state['bunnyLibraryId'],
                    'bunnyVideoId' => $state['bunnyVideoId'],
                    'bunnyCdnHostname' => $state['bunnyCdnHostname'],
                ],
            );
            $record = $items->getByAssetId((int)$asset->id);

            if (!$record || !$items->applyBunnyVideoState($record, $state)) {
                throw new \RuntimeException('The media item disappeared during import. Retry.');
            }

            return ['asset' => $asset, 'reused' => false];
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Refreshes the imported item for a library video, if there is one.
     *
     * @param string $libraryId the library ID
     * @param string $videoId the video ID
     * @return ?MediaItemRecord the refreshed record, or null for unknown videos
     * @throws \RuntimeException when the item can't be refreshed right now
     * @throws \InvalidArgumentException when an ID is invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function syncVideo(string $libraryId, string $videoId): ?MediaItemRecord
    {
        $record = Plugin::getInstance()->getMediaItems()->getByTypeAndProviderId('bunny', Bunny::playbackId($libraryId, $videoId));

        return $record ? $this->syncRecord($record) : null;
    }

    /**
     * Fetches an imported item's current state and applies it.
     *
     * The network request runs without the item lock. The stored sync version
     * is read first, so a response that a concurrent sync overtook is
     * rejected rather than written.
     *
     * @param MediaItemRecord $record the bunny-type media item
     * @param bool $checkRemotePoster also ask the CDN whether a thumbnail
     *                                replaced in place has changed
     * @return ?MediaItemRecord the refreshed record, or null when it no longer exists
     * @throws \RuntimeException when the item belongs to another library, the
     *                           CDN isn't ready, or the item is busy or overtaken
     * @throws \InvalidArgumentException when stored identifiers are invalid
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function syncRecord(MediaItemRecord $record, bool $checkRemotePoster = false): ?MediaItemRecord
    {
        $plugin = Plugin::getInstance();
        $items = $plugin->getMediaItems();
        $fresh = $items->getById((int)$record->id);

        if (!$fresh) {
            return null;
        }

        $metadata = $items->getMetadata($fresh);
        $libraryId = (string)($metadata['bunnyLibraryId'] ?? '');
        $videoId = (string)($metadata['bunnyVideoId'] ?? '');
        $host = Bunny::validateHostname((string)($metadata['bunnyCdnHostname'] ?? ''));

        if ($fresh->type !== 'bunny'
            || $libraryId !== $plugin->getBunny()->getLibraryId()
            || Bunny::playbackId($libraryId, $videoId) !== $fresh->providerId
        ) {
            throw new \RuntimeException('This import belongs to a different Bunny library. Its stored identity has not been changed.');
        }

        $version = (int)($metadata['bunnySyncVersion'] ?? 0);
        $state = $plugin->getBunny()->getAsset($videoId, $host);

        if ($state['status'] === 'ready' && $state['isPublic'] === null) {
            throw new \RuntimeException('Bunny playback has not reached the CDN yet. Retry synchronization.');
        }

        return $items->applyBunnyVideoState($fresh, $state, $version, $checkRemotePoster);
    }
}
