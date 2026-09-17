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

namespace boccdotdev\polymedia\console\controllers;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use yii\console\ExitCode;

/**
 * Migrates existing data into the current storage layout.
 *
 * @author boccdotdev
 * @since 1.2.0
 */
class MigrateController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var bool Whether to report planned changes without writing anything.
     */
    public bool $dryRun = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if (in_array($actionID, ['folders', 'sidecars'], true)) {
            $options[] = 'dryRun';
        }

        return $options;
    }

    /**
     * Retired alias for the unsafe per-item-folder migration.
     *
     * @return int
     */
    public function actionFolders(): int
    {
        $this->stderr(
            'This migration has been retired. Use `polymedia/migrate/sidecars` to flatten '
            . 'legacy folders and move managed files into the sidecar volume.' . PHP_EOL,
            Console::FG_RED,
        );

        return ExitCode::USAGE;
    }

    /**
     * Flattens legacy `.pmedia` folders and moves their attached files into the
     * configured sidecar volume.
     *
     * Only folders matching Polymedia's old `<title-slug>-<8 chars>` naming
     * convention are migrated. The command never deletes a legacy folder, even
     * after it becomes empty, so unrelated files cannot be removed.
     *
     * @return int
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function actionSidecars(): int
    {
        $plugin = Plugin::getInstance();
        $sidecars = $plugin->getSidecarStorage();

        if (!$sidecars->getVolume()) {
            $this->stderr(
                'No sidecar volume is configured. Select one in Polymedia settings or run '
                . '`php craft polymedia/setup/sidecar-volume` first.' . PHP_EOL,
                Console::FG_RED,
            );

            return ExitCode::CONFIG;
        }

        $assets = Asset::find()
            ->kind('polymedia')
            ->status(null)
            ->all();

        if (!$assets) {
            $this->stdout('Nothing to migrate.' . PHP_EOL, Console::FG_GREEN);

            return ExitCode::OK;
        }

        $movedManifests = 0;
        $movedSidecars = 0;

        foreach ($assets as $asset) {
            $record = $plugin->getMediaItems()->getByAssetId((int)$asset->id);

            if (!$record) {
                $this->stderr("  ! Skipped #{$asset->id}: media item record not found." . PHP_EOL, Console::FG_YELLOW);
                continue;
            }

            $movedSidecars += $this->_migrateRelatedAssets($asset, $record);
            $movedManifests += $this->_flattenManifest($asset, $record);
        }

        $verb = $this->dryRun ? 'Would migrate' : 'Migrated';
        $this->stdout(
            sprintf(
                '%s%s %d manifest(s) and %d sidecar file(s).%s',
                PHP_EOL,
                $verb,
                $movedManifests,
                $movedSidecars,
                PHP_EOL,
            ),
            Console::FG_GREEN,
        );
        $this->stdout(
            'Legacy folders were not deleted. Review and remove empty folders from the Assets index.' . PHP_EOL,
            Console::FG_YELLOW,
        );

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Moves related files out of folders created by Polymedia 1.2–2.1.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param MediaItemRecord $record its media item record
     * @return int number of files moved or planned
     */
    private function _migrateRelatedAssets(Asset $asset, MediaItemRecord $record): int
    {
        $plugin = Plugin::getInstance();
        $assetsService = Craft::$app->getAssets();
        $sidecarVolume = $plugin->getSidecarStorage()->getVolume();
        $count = 0;
        $seen = [];

        foreach ($plugin->getRelatedAssets()->getForItem($record->id) as $relation) {
            if (isset($seen[$relation->assetId])) {
                continue;
            }

            $seen[$relation->assetId] = true;
            $relatedAsset = $assetsService->getAssetById($relation->assetId);
            $folder = $relatedAsset
                ? $assetsService->getFolderById((int)$relatedAsset->folderId)
                : null;

            if (!$relatedAsset || !$folder) {
                continue;
            }

            $alreadyManaged = $sidecarVolume
                && (int)$folder->volumeId === (int)$sidecarVolume->id
                && $this->_isManagedSidecarFolder($folder, $record);

            if (
                $alreadyManaged
                || (
                    !$this->_isLegacyItemFolder($folder, $asset, $record)
                    && !$this->_isManagedSidecarFolder($folder, $record)
                )
            ) {
                continue;
            }

            $this->stdout("  → Sidecar #{$relatedAsset->id} {$relatedAsset->filename}" . PHP_EOL);
            $count++;

            if (!$this->dryRun) {
                $plugin->getSidecarStorage()->moveIntoItem($relatedAsset, $record);
            }
        }

        return $count;
    }

    /**
     * Moves a manifest from a legacy generated folder to its parent.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param MediaItemRecord $record its media item record
     * @return int one when moved or planned, otherwise zero
     */
    private function _flattenManifest(Asset $asset, MediaItemRecord $record): int
    {
        $assetsService = Craft::$app->getAssets();
        $folder = $assetsService->getFolderById((int)$asset->folderId);

        if (!$folder || !$this->_isLegacyItemFolder($folder, $asset, $record)) {
            return 0;
        }

        $parent = $assetsService->getFolderById((int)$folder->parentId);

        if (!$parent) {
            return 0;
        }

        $this->stdout(
            "  → Manifest #{$asset->id} {$folder->path}{$asset->filename} ⇒ {$parent->path}" . PHP_EOL,
        );

        if (!$this->dryRun) {
            $filename = $assetsService->getNameReplacementInFolder(
                (string)$asset->filename,
                (int)$parent->id,
            );
            $assetsService->moveAsset($asset, $parent, $filename);
        }

        return 1;
    }

    /**
     * Whether a folder matches the old title-slug plus random suffix convention.
     *
     * @param VolumeFolder $folder candidate folder
     * @param Asset $asset the manifest asset
     * @param MediaItemRecord $record its media item record
     * @return bool
     */
    private function _isLegacyItemFolder(
        VolumeFolder $folder,
        Asset $asset,
        MediaItemRecord $record,
    ): bool {
        if (!$folder->parentId) {
            return false;
        }

        $slugs = array_unique(array_filter([
            StringHelper::slugify((string)$record->title),
            StringHelper::slugify((string)$asset->title),
            StringHelper::slugify(pathinfo((string)$asset->filename, PATHINFO_FILENAME)),
        ]));

        foreach ($slugs as $slug) {
            if (preg_match('/^' . preg_quote($slug, '/') . '-[a-zA-Z0-9]{8}$/', (string)$folder->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether a folder uses the asset-UID layout of a sidecar volume.
     *
     * This lets the command move managed files again after an administrator
     * selects a different sidecar volume.
     *
     * @param VolumeFolder $folder candidate folder
     * @param MediaItemRecord $record its media item record
     * @return bool
     */
    private function _isManagedSidecarFolder(
        VolumeFolder $folder,
        MediaItemRecord $record,
    ): bool {
        return (bool)$folder->parentId
            && (string)$folder->path === Plugin::getInstance()
                ->getSidecarStorage()
                ->itemFolderPath((string)$record->assetUid);
    }
}
