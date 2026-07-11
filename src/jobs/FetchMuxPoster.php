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

namespace boccdotdev\polymedia\jobs;

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\queue\BaseJob;

/**
 * Retries downloading a Mux first-frame poster until the image CDN is ready
 * or the attempt budget is exhausted.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class FetchMuxPoster extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int Media item record ID
     */
    public int $itemId = 0;

    /**
     * @var string Mux playback ID
     */
    public string $playbackId = '';

    /**
     * @var int 0-based attempt counter
     */
    public int $attempt = 0;

    /**
     * @var int Maximum attempts before giving up
     */
    public int $maxAttempts = 8;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function execute($queue): void
    {
        if ($this->itemId <= 0 || $this->playbackId === '') {
            return;
        }

        $plugin = Plugin::getInstance();
        $record = $plugin->getMediaItems()->getById($this->itemId);

        if (!$record) {
            return;
        }

        // User (or prior job) already attached a poster — nothing to do.
        if ($plugin->getRelatedAssets()->getPoster((int)$record->id)) {
            return;
        }

        $this->setProgress($queue, 0.2);

        $url = $plugin->getMux()->firstFrameThumbnailUrl($this->playbackId);
        $poster = $plugin->getPosterFetcher()->fetchForItem($record, $url);

        if ($poster) {
            $this->setProgress($queue, 1.0);

            return;
        }

        $nextAttempt = $this->attempt + 1;

        if ($nextAttempt >= $this->maxAttempts) {
            Craft::warning(
                "FetchMuxPoster gave up for item #{$this->itemId} after {$this->maxAttempts} attempts.",
                __METHOD__,
            );

            return;
        }

        $plugin->getPosterFetcher()->queueMuxPoster($record, $this->playbackId, $nextAttempt);
        $this->setProgress($queue, 1.0);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('polymedia', 'Fetching Mux poster');
    }
}
