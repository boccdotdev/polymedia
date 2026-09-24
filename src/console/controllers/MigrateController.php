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
use boccdotdev\polymedia\services\SidecarMigration;
use Craft;
use craft\console\Controller;
use craft\elements\Asset;
use craft\helpers\Console;
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
     * Flattens legacy `.pmedia` folders and relocates explicitly managed sidecars.
     *
     * Legacy and shared attachments stay in place. Folder names are not proof of
     * ownership. The command never deletes a legacy folder, even when empty.
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

        $migration = new SidecarMigration(
            Craft::$app->getAssets(),
            $sidecars,
            $plugin->getRelatedAssets(),
        );
        $counts = ['manifest' => 0, 'sidecar' => 0];
        $failures = 0;
        $kept = 0;

        foreach ($assets as $asset) {
            $record = $plugin->getMediaItems()->getByAssetId((int)$asset->id);

            if (!$record) {
                $failures++;
                $this->stderr("  ! Failed #{$asset->id}: media item record not found." . PHP_EOL, Console::FG_RED);
                continue;
            }

            try {
                $results = $migration->migrateItem($asset, $record, $this->dryRun);
            } catch (\Throwable $e) {
                $failures++;
                $this->stderr("  ! Failed #{$asset->id}: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);
                continue;
            }

            foreach ($results as $result) {
                $line = "  {$result['status']} {$result['kind']} #{$result['assetId']}: {$result['message']}" . PHP_EOL;

                if ($result['status'] === 'failed') {
                    $failures++;
                    $this->stderr($line, Console::FG_RED);
                } elseif ($result['status'] === 'kept') {
                    $kept++;
                    $this->stdout($line, Console::FG_YELLOW);
                } else {
                    $counts[$result['kind']]++;
                    $this->stdout($line);
                }
            }
        }

        $verb = $this->dryRun ? 'Would migrate' : 'Migrated';
        $this->stdout(
            sprintf(
                '%s%s %d manifest(s) and %d sidecar file(s); %d kept, %d failed.%s',
                PHP_EOL,
                $verb,
                $counts['manifest'],
                $counts['sidecar'],
                $kept,
                $failures,
                PHP_EOL,
            ),
            $failures ? Console::FG_RED : Console::FG_GREEN,
        );
        $this->stdout(
            'Legacy folders were not deleted. Review and remove empty folders from the Assets index.' . PHP_EOL,
            Console::FG_YELLOW,
        );

        return $failures ? ExitCode::UNSPECIFIED_ERROR : ExitCode::OK;
    }
}
