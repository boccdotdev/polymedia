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
        $uid = StringHelper::randomString(8);
        $filename = "{$slug}-{$uid}.pmedia";

        $tmp = Assets::tempFilePath('pmedia');
        file_put_contents($tmp, $json);

        $asset = new Asset();
        $asset->tempFilePath = $tmp;
        $asset->filename = $filename;
        $asset->newFolderId = $folderId;
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
     * Reads and parses the manifest JSON from a `.pmedia` asset.
     *
     * Falls back to the DB record if the file is missing or invalid.
     *
     * @param Asset $asset the `.pmedia` asset
     * @return array the manifest data
     * @throws \Throwable if the filesystem read fails and no DB record exists
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function read(Asset $asset): array
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
            // Fall through to DB mirror
        }

        $record = Plugin::getInstance()->mediaItems->getByAssetId($asset->id);

        if (!$record) {
            throw new InvalidArgumentException("No manifest data found for asset #{$asset->id}");
        }

        return $this->_recordToManifest($record);
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
        $updated = array_merge($current, $changes);
        $json = Json::encode($updated, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $volume = $asset->getVolume();
        $fs = $volume->getFs();
        $fs->write($asset->getPath(), $json);

        $record = Plugin::getInstance()->mediaItems->getByAssetId($asset->id);

        if ($record) {
            if (isset($changes['title'])) {
                $record->title = $changes['title'];
            }
            if (isset($changes['url'])) {
                $record->url = $changes['url'];
            }
            if (isset($changes['defaults'])) {
                $record->defaults = is_string($changes['defaults'])
                    ? $changes['defaults']
                    : Json::encode($changes['defaults']);
            }
            $record->save();
        }
    }

    // Private Methods
    // =========================================================================

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
        if (Plugin::getInstance()->getMediaItems()->existsForAsset($asset->id)) {
            return;
        }

        $defaults = new PlayerSettings();

        $record = new MediaItemRecord();
        $record->assetId = $asset->id;
        $record->assetUid = $asset->uid;
        $record->type = $detection->type;
        $record->url = $detection->url;
        $record->providerId = $detection->providerId;
        $record->element = $detection->element;
        $record->title = $title;
        $record->defaults = Json::encode($defaults->toArray());
        $record->save();
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
        ];

        if ($record->duration !== null) {
            $manifest['duration'] = (int)$record->duration;
        }

        return $manifest;
    }
}
