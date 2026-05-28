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
use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
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

        if ($actionID === 'folders') {
            $options[] = 'dryRun';
        }

        return $options;
    }

    /**
     * Moves existing `.pmedia` items into their own dedicated folder, along
     * with any poster/track files co-located beside them.
     *
     * Items that already sit alone in a non-root folder are left untouched, so
     * the command is safe to run repeatedly.
     *
     * @return int
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function actionFolders(): int
    {
        $plugin = Plugin::getInstance();
        $assetsService = Craft::$app->getAssets();
        $manifestWriter = $plugin->getManifestWriter();

        $assets = Asset::find()
            ->kind('polymedia')
            ->status(null)
            ->all();

        // Decide eligibility up front, against the pre-migration state, so that
        // moving one item out of a shared folder can't make a sibling look
        // "dedicated" and get skipped.
        $toMigrate = [];

        foreach ($assets as $asset) {
            $folder = $assetsService->getFolderById((int)$asset->folderId);

            if ($folder && !$manifestWriter->isDedicatedItemFolder($folder, $asset->id)) {
                $toMigrate[] = [$asset, $folder];
            }
        }

        if (!$toMigrate) {
            $this->stdout('Nothing to migrate — all .pmedia items already have their own folder.' . PHP_EOL, Console::FG_GREEN);
            return ExitCode::OK;
        }

        $movedFiles = 0;

        foreach ($toMigrate as [$asset, $folder]) {
            $movedFiles += $this->_migrateItem($asset, $folder);
        }

        $verb = $this->dryRun ? 'Would migrate' : 'Migrated';
        $this->stdout(
            sprintf(
                '%s%s %d item(s), moving %d related file(s).%s',
                PHP_EOL,
                $verb,
                count($toMigrate),
                $movedFiles,
                PHP_EOL,
            ),
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }

    // Private Methods
    // =========================================================================

    /**
     * Migrates a single `.pmedia` into a new dedicated folder beside its
     * current location, dragging co-located related files along with it.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param VolumeFolder $folder the asset's current folder
     * @return int the number of related files moved
     */
    private function _migrateItem(Asset $asset, VolumeFolder $folder): int
    {
        $assetsService = Craft::$app->getAssets();

        $stem = pathinfo((string)$asset->filename, PATHINFO_FILENAME);
        $folderName = Plugin::getInstance()->getManifestWriter()->itemFolderName($stem);
        $targetPath = $folder->path . $folderName;

        $this->stdout("  → #{$asset->id} {$asset->filename}  ⇒  {$targetPath}/" . PHP_EOL);

        $originalFolderId = (int)$asset->folderId;
        $related = $this->_coLocatedRelatedAssets($asset, $originalFolderId);

        if ($this->dryRun) {
            foreach ($related as $relatedAsset) {
                $this->stdout("      · {$relatedAsset->filename}" . PHP_EOL, Console::FG_GREY);
            }
            return count($related);
        }

        $newFolder = $assetsService->ensureFolderByFullPathAndVolume($targetPath, $folder->getVolume(), false);
        $assetsService->moveAsset($asset, $newFolder);

        foreach ($related as $relatedAsset) {
            $assetsService->moveAsset($relatedAsset, $newFolder);
        }

        return count($related);
    }

    /**
     * Returns the item's related assets (poster/tracks/transcript) that
     * currently sit in the same folder as the `.pmedia`.
     *
     * Related assets living elsewhere (e.g. a shared poster) are left in place.
     *
     * @param Asset $asset the `.pmedia` asset
     * @param int $folderId the folder the related assets must be in to move
     * @return Asset[]
     */
    private function _coLocatedRelatedAssets(Asset $asset, int $folderId): array
    {
        $plugin = Plugin::getInstance();
        $assetsService = Craft::$app->getAssets();

        $record = $plugin->getMediaItems()->getByAssetId($asset->id);

        if (!$record) {
            return [];
        }

        $found = [];

        foreach ($plugin->getRelatedAssets()->getForItem($record->id) as $relation) {
            $relatedAsset = $assetsService->getAssetById($relation->assetId);

            if ($relatedAsset && (int)$relatedAsset->folderId === $folderId) {
                $found[] = $relatedAsset;
            }
        }

        return $found;
    }
}
