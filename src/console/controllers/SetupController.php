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
use boccdotdev\polymedia\services\SidecarStorage;
use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Creates optional infrastructure used by Polymedia.
 *
 * @author boccdotdev
 * @since 2.2.0
 */
class SetupController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string Local filesystem base path.
     */
    public string $path = SidecarStorage::DEFAULT_PATH;

    /**
     * @var string Public base URL for the local filesystem.
     */
    public string $url = SidecarStorage::DEFAULT_URL;

    /**
     * @var bool Whether to report the planned setup without writing anything.
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

        if ($actionID === 'sidecar-volume') {
            $options[] = 'path';
            $options[] = 'url';
            $options[] = 'dryRun';
        }

        return $options;
    }

    /**
     * Creates and selects the default public local sidecar volume.
     *
     * @return int
     */
    public function actionSidecarVolume(): int
    {
        if (!Craft::$app->getConfig()->getGeneral()->allowAdminChanges) {
            $this->stderr(
                'Project config is read-only. Create the filesystem and volume in an environment '
                . 'where admin changes are allowed, then deploy project config.' . PHP_EOL,
                Console::FG_RED,
            );

            return ExitCode::CONFIG;
        }

        $storage = Plugin::getInstance()->getSidecarStorage();
        $existing = $storage->getVolume();

        if ($existing) {
            $this->stdout(
                "Polymedia already uses the “{$existing->name}” sidecar volume." . PHP_EOL,
                Console::FG_GREEN,
            );

            return ExitCode::OK;
        }

        if ($this->dryRun) {
            $this->stdout(
                sprintf(
                    "Would create the “%s” local filesystem and volume.%s  Path: %s%s  URL:  %s%s",
                    SidecarStorage::DEFAULT_NAME,
                    PHP_EOL,
                    $this->path,
                    PHP_EOL,
                    $this->url,
                    PHP_EOL,
                ),
                Console::FG_YELLOW,
            );

            return ExitCode::OK;
        }

        try {
            $volume = $storage->createDefaultLocalVolume($this->path, $this->url);
        } catch (\Throwable $e) {
            $this->stderr("Could not create the sidecar volume: {$e->getMessage()}" . PHP_EOL, Console::FG_RED);

            return ExitCode::UNSPECIFIED_ERROR;
        }

        $this->stdout(
            "Created and selected the “{$volume->name}” sidecar volume." . PHP_EOL,
            Console::FG_GREEN,
        );

        return ExitCode::OK;
    }
}
