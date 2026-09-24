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
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\Asset;
use craft\fs\Local;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\services\ProjectConfig;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Owns the storage layout for plugin-managed posters and text tracks.
 *
 * Sidecars live in a dedicated volume, under a folder named with the stable
 * `.pmedia` asset UID. This explicit volume + path rule is also the deletion
 * boundary: Polymedia never deletes a folder in the manifest's own volume.
 *
 * @author boccdotdev
 * @since 2.2.0
 */
class SidecarStorage extends Component
{
    // Const Properties
    // =========================================================================

    public const DEFAULT_NAME = 'Polymedia Sidecars';
    public const DEFAULT_HANDLE = 'polymediaSidecars';
    public const DEFAULT_PATH = '@webroot/polymedia-sidecars';
    public const DEFAULT_URL = '@web/polymedia-sidecars';

    // Public Methods
    // =========================================================================

    /**
     * Returns the configured sidecar volume, or null when it is unavailable.
     *
     * @return ?Volume
     */
    public function getVolume(): ?Volume
    {
        $uid = Plugin::getInstance()->getSettings()->sidecarVolumeUid;

        if (!$uid) {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByUid($uid);
    }

    /**
     * Returns the sidecar volume's root folder.
     *
     * The root is used as the temporary upload destination on the create screen,
     * before the new `.pmedia` has an asset UID.
     *
     * @return ?VolumeFolder
     */
    public function getRootFolder(): ?VolumeFolder
    {
        $volume = $this->getVolume();

        if (!$volume || !$volume->id) {
            return null;
        }

        return Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volume->id);
    }

    /**
     * Returns, and if needed creates, the managed folder for a media item.
     *
     * @param MediaItemRecord $record the media item
     * @return ?VolumeFolder
     */
    public function getItemFolder(MediaItemRecord $record): ?VolumeFolder
    {
        $volume = $this->getVolume();

        if (!$volume || !$volume->id || !$record->assetUid) {
            return null;
        }

        return Craft::$app->getAssets()->ensureFolderByFullPathAndVolume(
            $this->itemFolderPath((string)$record->assetUid),
            $volume,
            false,
        );
    }

    /**
     * Moves an asset into an item's managed sidecar folder.
     *
     * @param Asset $asset the poster or text track
     * @param MediaItemRecord $record the owning media item
     * @return bool whether the asset is now in the item folder
     */
    public function moveIntoItem(Asset $asset, MediaItemRecord $record): bool
    {
        $folder = $this->getItemFolder($record);

        if (!$folder) {
            return false;
        }

        if ((int)$asset->folderId === (int)$folder->id) {
            return true;
        }

        $assets = Craft::$app->getAssets();
        $filename = $assets->getNameReplacementInFolder((string)$asset->filename, (int)$folder->id);

        return $assets->moveAsset($asset, $folder, $filename);
    }

    /**
     * Moves a create-screen upload from the sidecar root into its item folder.
     *
     * Existing library assets are left where the user put them. Only an asset
     * uploaded into the configured sidecar root is adopted.
     *
     * @param Asset $asset the selected poster
     * @param MediaItemRecord $record the newly created media item
     * @return bool whether the asset was adopted
     */
    public function adoptRootUpload(Asset $asset, MediaItemRecord $record): bool
    {
        $root = $this->getRootFolder();

        if (!$root || (int)$asset->folderId !== (int)$root->id) {
            return false;
        }

        return $this->moveIntoItem($asset, $record);
    }

    /**
     * Deletes only the exact sidecar folder owned by a hard-deleted `.pmedia`.
     *
     * The lookup is constrained to the configured sidecar volume and the stable
     * asset UID. The asset's current folder is deliberately ignored.
     *
     * @param Asset $asset the hard-deleted `.pmedia` asset
     */
    public function deleteForAsset(Asset $asset): void
    {
        $volume = $this->getVolume();

        if (!$volume || !$volume->id || !$asset->uid) {
            return;
        }

        $folder = Craft::$app->getAssets()->findFolder([
            'volumeId' => (int)$volume->id,
            'path' => $this->itemFolderPath((string)$asset->uid),
        ]);

        if (!$folder || !$folder->parentId) {
            return;
        }

        Craft::$app->getAssets()->deleteFoldersByIds((int)$folder->id, true);
    }

    /**
     * Creates the default public local filesystem and sidecar volume.
     *
     * This is idempotent. If the default filesystem or volume already exists,
     * it is reused. The chosen volume is saved to the plugin settings.
     *
     * @param string $path local filesystem base path
     * @param string $url public base URL
     * @return Volume the configured sidecar volume
     */
    public function createDefaultLocalVolume(
        string $path = self::DEFAULT_PATH,
        string $url = self::DEFAULT_URL,
    ): Volume {
        $volumes = Craft::$app->getVolumes();
        $existingVolume = $volumes->getVolumeByHandle(self::DEFAULT_HANDLE);

        if ($existingVolume) {
            $this->_configure($existingVolume);

            return $existingVolume;
        }

        $filesystems = Craft::$app->getFs();
        $filesystem = $filesystems->getFilesystemByHandle(self::DEFAULT_HANDLE);

        if (!$filesystem) {
            $filesystem = new Local([
                'name' => self::DEFAULT_NAME,
                'handle' => self::DEFAULT_HANDLE,
                'hasUrls' => true,
                'url' => $url,
                'path' => $path,
            ]);

            if (!$filesystems->saveFilesystem($filesystem)) {
                throw new InvalidArgumentException(
                    'Could not create the Polymedia sidecar filesystem: '
                    . implode(', ', $filesystem->getFirstErrors()),
                );
            }
        }

        $volume = new Volume([
            'name' => self::DEFAULT_NAME,
            'handle' => self::DEFAULT_HANDLE,
            'fs' => self::DEFAULT_HANDLE,
        ]);

        if (!$volumes->saveVolume($volume)) {
            throw new InvalidArgumentException(
                'Could not create the Polymedia sidecar volume: '
                . implode(', ', $volume->getFirstErrors()),
            );
        }

        $this->_configure($volume);

        return $volume;
    }

    /**
     * Returns the canonical relative folder path for an asset UID.
     *
     * @param string $assetUid the `.pmedia` asset UID
     * @return string
     */
    public function itemFolderPath(string $assetUid): string
    {
        return trim($assetUid, '/\\') . '/';
    }

    /**
     * Recognizes the explicit UID layout, including in a previous sidecar volume.
     * Title-based legacy folders do not establish attachment ownership.
     */
    public function isItemFolder(VolumeFolder $folder, string $assetUid): bool
    {
        return $assetUid !== ''
            && (bool)$folder->parentId
            && (string)$folder->path === $this->itemFolderPath($assetUid);
    }

    /**
     * Checks both Polymedia attachments and native Craft relation fields.
     *
     * Includes references from trashed elements: restoring an item must not
     * restore a relation to a file another item's cleanup has since deleted.
     */
    public function isSharedAsset(int $assetId, int $itemId): bool
    {
        return (new Query())
            ->from(Table::RELATED_ASSETS)
            ->where(['assetId' => $assetId])
            ->andWhere(['not', ['itemId' => $itemId]])
            ->exists()
            || (new Query())
                ->from(CraftTable::RELATIONS)
                ->where(['targetId' => $assetId])
                ->exists();
    }

    // Private Methods
    // =========================================================================

    /**
     * Saves a volume as the plugin's sidecar destination.
     *
     * @param Volume $volume
     */
    private function _configure(Volume $volume): void
    {
        $plugin = Plugin::getInstance();
        $plugin->getSettings()->sidecarVolumeUid = $volume->uid;

        Craft::$app->getProjectConfig()->set(
            ProjectConfig::PATH_PLUGINS . ".{$plugin->handle}.settings.sidecarVolumeUid",
            $volume->uid,
            'Configure the Polymedia sidecar volume',
        );
    }
}
