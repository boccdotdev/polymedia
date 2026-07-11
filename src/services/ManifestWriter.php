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
use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\elements\Asset;
use craft\helpers\Assets;
use craft\helpers\Json;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Manifest writer service.
 *
 * Writes `.pmedia` JSON manifest files into Craft volumes and manages
 * the corresponding `MediaItemRecord` rows.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class ManifestWriter extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Creates a new `.pmedia` manifest asset in the given volume folder.
     *
     * @param int $volumeId the target volume ID
     * @param int $folderId the target folder ID
     * @param DetectionResult $detection the detection result
     * @param string $title the user-supplied title
     * @param ?string $derivedThumbnail the derived thumbnail URL (from ThumbnailDeriver)
     * @return Asset the saved asset element
     * @throws \Throwable if the asset could not be saved
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function create(
        int $volumeId,
        int $folderId,
        DetectionResult $detection,
        string $title,
        ?string $derivedThumbnail = null,
    ): Asset {
        $manifest = $this->_buildManifest($detection, $title, $derivedThumbnail);
        $json = Json::encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $slug = StringHelper::slugify($title);
        if ($slug === '') {
            $slug = 'media';
        }
        $folderName = $this->itemFolderName($title);
        $filename = "{$slug}.pmedia";

        $itemFolderId = $this->_ensureItemFolderId($volumeId, $folderId, $folderName);

        $tmp = Assets::tempFilePath('pmedia');
        file_put_contents($tmp, $json);

        $asset = new Asset();
        $asset->tempFilePath = $tmp;
        $asset->filename = $filename;
        $asset->newFolderId = $itemFolderId;
        $asset->volumeId = $volumeId;
        $asset->title = $title;
        $asset->setScenario(Asset::SCENARIO_CREATE);

        if (!Craft::$app->getElements()->saveElement($asset)) {
            throw new InvalidArgumentException(
                'Could not save media asset: ' . implode(', ', $asset->getFirstErrors()),
            );
        }

        $this->_createRecord($asset, $detection, $title, $derivedThumbnail);

        return $asset;
    }

    /**
     * Returns manifest data for a `.pmedia` asset.
     *
     * Prefers the DB record (including `metadata`) so S3/cloud volumes avoid a
     * filesystem round-trip per render. Falls back to reading the file, then
     * backfills the record metadata when possible.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return array the manifest data
     * @throws \Throwable if neither the DB record nor the filesystem has data
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function read(Asset $asset): array
    {
        $mediaItems = Plugin::getInstance()->getMediaItems();
        $record = $mediaItems->getByAssetId((int)$asset->id);

        if ($record) {
            // Lazy-backfill metadata for rows created before the column existed.
            if ($record->metadata === null || $record->metadata === '') {
                $fromFs = $this->_readFilesystemManifest($asset);

                if ($fromFs !== null) {
                    $this->backfillRecordFromManifest($record, $fromFs);

                    return $this->_recordToManifest($record);
                }
            }

            return $this->_recordToManifest($record);
        }

        $fromFs = $this->_readFilesystemManifest($asset);

        if ($fromFs !== null) {
            return $fromFs;
        }

        throw new InvalidArgumentException("No manifest data found for asset #{$asset->id}");
    }

    /**
     * Reads and decodes the `.pmedia` file from the asset volume, or null on failure.
     *
     * @param Asset $asset the polymedia asset
     * @return array|null
     */
    private function _readFilesystemManifest(Asset $asset): ?array
    {
        try {
            $volume = $asset->getVolume();
            $fs = $volume->getFs();
            $contents = $fs->read($asset->getPath());
            $data = Json::decodeIfJson($contents);

            if (is_array($data) && isset($data['polymedia'])) {
                return $data;
            }
        } catch (\Throwable) {
            return null;
        }

        return null;
    }

    /**
     * Updates the manifest file and DB record for an existing `.pmedia` asset.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param array $changes key-value pairs to merge into the manifest
     * @throws \Throwable if the update fails
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function update(Asset $asset, array $changes): void
    {
        $current = $this->read($asset);
        $updated = array_replace_recursive($current, $changes);
        $json = Json::encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $volume = $asset->getVolume();
        $fs = $volume->getFs();
        $fs->write($asset->getPath(), $json);

        $record = Plugin::getInstance()->getMediaItems()->getByAssetId((int)$asset->id);

        if ($record) {
            if (isset($changes['title'])) {
                $record->title = $changes['title'];
            }
            if (isset($changes['url'])) {
                $record->url = $changes['url'];
            }
            if (isset($changes['type'])) {
                $record->type = $changes['type'];
            }
            if (isset($changes['providerId'])) {
                $record->providerId = $changes['providerId'];
            }
            if (isset($changes['defaults'])) {
                $record->defaults = is_string($changes['defaults'])
                    ? $changes['defaults']
                    : Json::encode($changes['defaults']);
            }
            if (array_key_exists('metadata', $updated)) {
                $record->metadata = is_string($updated['metadata'])
                    ? $updated['metadata']
                    : Json::encode($updated['metadata'] ?? []);
            }
            Plugin::getInstance()->getMediaItems()->save($record);
        }
    }

    /**
     * Determines whether a folder is a dedicated single-item folder.
     *
     * A `.pmedia` that lives alone in a non-root folder is considered to be in
     * its own dedicated folder. This is a structural signal — it survives the
     * filename being re-slugified on edit, unlike a name-matching heuristic.
     *
     * @param VolumeFolder $folder the folder to test
     * @param ?int $excludeAssetId an asset to exclude from the count (e.g. the
     *                             one being deleted, which may still be present)
     * @return bool
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function isDedicatedItemFolder(VolumeFolder $folder, ?int $excludeAssetId = null): bool
    {
        if (!$folder->parentId) {
            return false;
        }

        $query = Asset::find()
            ->folderId($folder->id)
            ->kind('polymedia')
            ->status(null);

        if ($excludeAssetId !== null) {
            $query->id(['not', $excludeAssetId]);
        }

        return $query->count() === 0;
    }

    /**
     * Builds a unique per-item folder name from some base text — a slug plus a
     * short random suffix (e.g. `my-video-a1b2c3d4`).
     *
     * Shared by item creation and the folder migration command so both produce
     * the same naming scheme.
     *
     * @param string $base the text to slugify (a title or filename stem)
     * @return string
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function itemFolderName(string $base): string
    {
        $slug = StringHelper::slugify($base);

        if ($slug === '') {
            $slug = 'media';
        }

        return $slug . '-' . StringHelper::randomString(8);
    }

    // Private Methods
    // =========================================================================

    /**
     * Ensures a dedicated subfolder exists for a media item and returns its ID.
     *
     * Each `.pmedia` lives in its own folder (named after the file stem) so its
     * poster and track files can sit alongside it, keeping the volume tidy. If
     * the target folder or volume can't be resolved, the original folder is used.
     *
     * @param int $volumeId the target volume ID
     * @param int $parentFolderId the folder the item is being added to
     * @param string $name the subfolder name (the `.pmedia` file stem)
     * @return int the item folder ID
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    private function _ensureItemFolderId(int $volumeId, int $parentFolderId, string $name): int
    {
        $assets = Craft::$app->getAssets();
        $parent = $assets->getFolderById($parentFolderId);
        $volume = Craft::$app->getVolumes()->getVolumeById($volumeId);

        if (!$parent || !$volume) {
            return $parentFolderId;
        }

        $folder = $assets->ensureFolderByFullPathAndVolume($parent->path . $name, $volume, false);

        return (int)$folder->id;
    }

    /**
     * Builds the manifest array.
     *
     * @param DetectionResult $detection the detection result
     * @param string $title the title
     * @param ?string $derivedThumbnail the derived thumbnail URL
     * @return array
     */
    private function _buildManifest(
        DetectionResult $detection,
        string $title,
        ?string $derivedThumbnail,
    ): array {
        $manifest = [
            'polymedia' => '1.0',
            'type' => $detection->type,
            'url' => $detection->url,
            'title' => $title,
            'providerId' => $detection->providerId,
        ];

        $metadata = [];

        if ($derivedThumbnail !== null) {
            $metadata['thumbnail'] = $derivedThumbnail;
        }

        if (!empty($detection->hints)) {
            $metadata['hints'] = $detection->hints;
        }

        if (!empty($metadata)) {
            $manifest['metadata'] = $metadata;
        }

        return $manifest;
    }

    /**
     * Creates a MediaItemRecord for a newly saved asset.
     *
     * @param Asset $asset the saved asset
     * @param DetectionResult $detection the detection result
     * @param string $title the title
     * @param ?string $derivedThumbnail the derived thumbnail URL
     */
    private function _createRecord(
        Asset $asset,
        DetectionResult $detection,
        string $title,
        ?string $derivedThumbnail,
    ): void {
        $mediaItems = Plugin::getInstance()->getMediaItems();

        if ($mediaItems->existsForAsset((int)$asset->id)) {
            return;
        }

        $defaults = new PlayerSettings();
        $metadata = $this->_buildMetadata($detection, $derivedThumbnail);

        $record = new MediaItemRecord();
        $record->assetId = $asset->id;
        $record->assetUid = $asset->uid;
        $record->type = $detection->type;
        $record->url = $detection->url;
        $record->providerId = $detection->providerId;
        $record->element = $detection->element;
        $record->title = $title;
        $record->defaults = Json::encode($defaults->toArray());
        $record->metadata = $metadata !== [] ? Json::encode($metadata) : null;
        $mediaItems->save($record);
    }

    /**
     * Builds the metadata object stored on the record and in the manifest file.
     *
     * @param DetectionResult $detection the detection result
     * @param ?string $derivedThumbnail the derived thumbnail URL
     * @return array
     */
    private function _buildMetadata(DetectionResult $detection, ?string $derivedThumbnail): array
    {
        $metadata = [];

        if ($derivedThumbnail !== null) {
            $metadata['thumbnail'] = $derivedThumbnail;
        }

        if (!empty($detection->hints)) {
            $metadata['hints'] = $detection->hints;
        }

        return $metadata;
    }

    /**
     * Converts a MediaItemRecord to manifest array shape.
     *
     * @param MediaItemRecord $record the record
     * @return array
     */
    private function _recordToManifest(MediaItemRecord $record): array
    {
        $manifest = [
            'polymedia' => '1.0',
            'type' => $record->type,
            'url' => $record->url,
            'title' => $record->title,
            'providerId' => $record->providerId,
            'element' => $record->element,
        ];

        if ($record->duration !== null) {
            $manifest['duration'] = (int)$record->duration;
        }

        if ($record->width !== null) {
            $manifest['width'] = (int)$record->width;
        }

        if ($record->height !== null) {
            $manifest['height'] = (int)$record->height;
        }

        $metadata = $record->metadata ? Json::decodeIfJson($record->metadata) : null;

        if (is_array($metadata) && $metadata !== []) {
            $manifest['metadata'] = $metadata;
        }

        return $manifest;
    }

    /**
     * Backfills `metadata` (and related columns) on a record from a full
     * filesystem manifest array. Used when older rows predate the metadata
     * column or were reconciled without metadata.
     *
     * @param MediaItemRecord $record the record to update
     * @param array $manifest the filesystem manifest
     */
    public function backfillRecordFromManifest(MediaItemRecord $record, array $manifest): void
    {
        $dirty = false;

        if (isset($manifest['metadata']) && is_array($manifest['metadata'])) {
            $encoded = Json::encode($manifest['metadata']);

            if ($record->metadata !== $encoded) {
                $record->metadata = $encoded;
                $dirty = true;
            }
        }

        if ($dirty) {
            Plugin::getInstance()->getMediaItems()->save($record);
        }
    }
}
