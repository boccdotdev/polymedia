<?php

namespace boccdotdev\polymedia\console\controllers;

use boccdotdev\polymedia\Plugin;
use craft\console\Controller;
use yii\console\ExitCode;

/** Refresh imported Bunny items, never enumerate or import a remote library. */
class BunnyController extends Controller
{
    public ?string $videoId = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['videoId']);
    }

    public function actionSync(): int
    {
        $plugin = Plugin::getInstance();
        if (!$plugin->isBunnyEnabled()) {
            $this->stderr("Bunny synchronization requires Polymedia Pro and a configured library.\n");
            return ExitCode::CONFIG;
        }
        $failed = 0;
        $synced = 0;
        foreach ($plugin->getMediaItems()->getByType('bunny') as $record) {
            $metadata = $plugin->getMediaItems()->getMetadata($record);
            if ($this->videoId !== null && ($metadata['bunnyVideoId'] ?? null) !== $this->videoId) {
                continue;
            }
            try {
                if ($plugin->getBunnySync()->syncRecord($record)) {
                    $synced++;
                }
            } catch (\Throwable $e) {
                $failed++;
                $this->stderr("Item #{$record->id}: {$e->getMessage()}\n");
            }
        }
        $this->stdout("Synced {$synced} item(s), {$failed} failed.\n");
        return $failed ? ExitCode::TEMPFAIL : ExitCode::OK;
    }
}
