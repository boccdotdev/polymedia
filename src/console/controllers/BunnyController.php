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
 * Refreshes imported Bunny Stream items (status, duration, MP4 files, posters).
 *
 * Only items already imported into Craft are refreshed. The command never
 * lists or imports the remote library, and it's safe to run repeatedly, for
 * example from cron when webhooks aren't configured.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyController extends Controller
{
    // Public Properties
    // =========================================================================

    /**
     * @var ?string Sync a single item by its Bunny video ID.
     */
    public ?string $videoId = null;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function options($actionID): array
    {
        $options = parent::options($actionID);

        if ($actionID === 'sync') {
            $options[] = 'videoId';
        }

        return $options;
    }

    /**
     * Pulls current state from Bunny Stream for every imported item, or one with --video-id.
     *
     * Also refreshes posters Polymedia fetched when the thumbnail has changed in Bunny.
     *
     * @return int
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionSync(): int
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getBunnyEnabled()) {
            $this->stderr("Bunny synchronization requires Polymedia Pro and a configured Bunny Stream library.\n", Console::FG_RED);

            return ExitCode::CONFIG;
        }

        $mediaItems = $plugin->getMediaItems();
        $synced = 0;
        $failed = 0;

        foreach ($mediaItems->getByType('bunny') as $record) {
            $metadata = $mediaItems->getMetadata($record);
            $videoId = (string)($metadata['bunnyVideoId'] ?? '');

            if ($this->videoId !== null && $this->videoId !== '' && $videoId !== $this->videoId) {
                continue;
            }

            $label = (string)$record->title !== '' ? (string)$record->title : "#{$record->id}";

            try {
                $fresh = $plugin->getBunnySync()->syncRecord($record, checkRemotePoster: true);
            } catch (\Throwable $e) {
                $failed++;
                $this->stderr("✗ {$label}: {$e->getMessage()}\n", Console::FG_RED);
                continue;
            }

            if (!$fresh) {
                continue;
            }

            $synced++;
            $this->stdout("✓ {$label}\n", Console::FG_GREEN);
        }

        $this->stdout("Synced {$synced} item(s), {$failed} failed.\n");

        return $failed ? ExitCode::TEMPFAIL : ExitCode::OK;
    }
}
