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
use craft\console\Controller;
use craft\helpers\Console;
use yii\console\ExitCode;

/**
 * Syncs Mux asset state (status, duration, poster) into media items.
 *
 * The pull-based counterpart to webhook delivery: both feed
 * {@see \boccdotdev\polymedia\services\MediaItems::applyMuxAssetState()}, so
 * this command works with no public endpoint (local dev) and repairs items
 * that missed a webhook (production).
 *
 * @author boccdotdev
 * @since 2.2.0
 */
class MuxController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var string|null Sync a single item by its Mux asset id.
     */
    public ?string $muxAssetId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'sync-status') {
            $options[] = 'muxAssetId';
        }

        return $options;
    }

    /**
     * Pulls current status/duration from Mux for every mux media item (or one,
     * via `--mux-asset-id`) and applies it, fetching posters for newly ready
     * assets. Safe to run repeatedly.
     *
     * @return int
     *
     * @author boccdotdev
     * @since 2.2.0
     */
    public function actionSyncStatus(): int
    {
        $plugin = Plugin::getInstance();
        $mux = $plugin->getMux();
        $mediaItems = $plugin->getMediaItems();

        if (!$mux->isConfigured()) {
            $this->stderr("Mux is not configured. Add a Token ID and Secret in Polymedia settings.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $targets = [];

        if ($this->muxAssetId !== null && $this->muxAssetId !== '') {
            $record = $mediaItems->getByMuxAssetId($this->muxAssetId);

            if (!$record) {
                $this->stderr("No media item found for Mux asset {$this->muxAssetId}.\n", Console::FG_RED);

                return ExitCode::DATAERR;
            }

            $targets[$this->muxAssetId] = $record;
        } else {
            foreach ($mediaItems->getByType('mux') as $record) {
                $metadata = $mediaItems->getMetadata($record);
                $muxAssetId = isset($metadata['muxAssetId']) ? (string)$metadata['muxAssetId'] : '';

                if ($muxAssetId !== '') {
                    $targets[$muxAssetId] = $record;
                }
            }
        }

        if ($targets === []) {
            $this->stdout("No mux media items with a stored Mux asset id.\n");

            return ExitCode::OK;
        }

        $synced = 0;
        $failed = 0;

        foreach ($targets as $muxAssetId => $record) {
            $label = (string)$record->title !== '' ? (string)$record->title : (string)$muxAssetId;

            try {
                $state = $mux->getAsset((string)$muxAssetId);
            } catch (\Throwable $e) {
                $failed++;
                $this->stderr("✗ {$label}: {$e->getMessage()}\n", Console::FG_RED);
                continue;
            }

            $mediaItems->applyMuxAssetState((string)$muxAssetId, $state, $record);
            $synced++;

            $status = isset($state['status']) ? (string)$state['status'] : 'unknown';
            $this->stdout("✓ {$label}: {$status}\n", Console::FG_GREEN);
        }

        $this->stdout("Synced {$synced} item(s)" . ($failed ? ", {$failed} failed" : '') . ".\n");

        return $failed ? ExitCode::TEMPFAIL : ExitCode::OK;
    }
}
