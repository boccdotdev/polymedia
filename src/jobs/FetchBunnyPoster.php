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
use boccdotdev\polymedia\services\Bunny;
use Craft;
use craft\queue\BaseJob;

/**
 * Downloads a Bunny Stream thumbnail into the sidecar volume as the item's
 * poster, retrying while the CDN catches up.
 *
 * Only the thumbnail URL built from the item's own pinned host and video ID
 * is fetched. {@see \boccdotdev\polymedia\services\PosterFetcher::fetchForItem()}
 * re-checks the current poster under the item lock.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class FetchBunnyPoster extends BaseJob
{
    // Public Properties
    // =========================================================================

    /**
     * @var int Media item record ID
     */
    public int $itemId = 0;

    /**
     * @var string The item's provider ID when queued; a changed ID cancels the job
     */
    public string $providerId = '';

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
        $plugin = Plugin::getInstance();
        $record = $plugin->getMediaItems()->getById($this->itemId);

        if (!$record
            || $record->type !== 'bunny'
            || (string)$record->providerId !== $this->providerId
            || $plugin->getRelatedAssets()->getPoster((int)$record->id)
        ) {
            return;
        }

        // Without sidecar storage the import already kept the remote URL.
        if (!$plugin->getSidecarStorage()->getVolume()) {
            return;
        }

        $url = $this->_thumbnailUrl($plugin->getMediaItems()->getMetadata($record));

        if ($url === null) {
            return;
        }

        $this->setProgress($queue, 0.2);

        try {
            $poster = $plugin->getPosterFetcher()->fetchForItem($record, $url);
        } catch (\Throwable $e) {
            Craft::warning("FetchBunnyPoster failed for item #{$this->itemId}: {$e->getMessage()}", __METHOD__);
            $poster = null;
        }

        if ($poster) {
            $this->setProgress($queue, 1.0);

            return;
        }

        if ($this->attempt + 1 >= $this->maxAttempts) {
            Craft::warning("FetchBunnyPoster gave up for item #{$this->itemId} after {$this->maxAttempts} attempts.", __METHOD__);

            return;
        }

        $plugin->getPosterFetcher()->queueBunnyPoster($record, $this->attempt + 1);
        $this->setProgress($queue, 1.0);
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function defaultDescription(): ?string
    {
        return Craft::t('polymedia', 'Fetching Bunny Stream poster');
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the stored thumbnail URL when it matches the item's own video.
     *
     * @param array $metadata the item metadata
     * @return ?string
     */
    private function _thumbnailUrl(array $metadata): ?string
    {
        $url = (string)($metadata['bunnyThumbnailUrl'] ?? '');

        if ($url === '') {
            return null;
        }

        try {
            $expected = Bunny::thumbnailUrl(
                (string)($metadata['bunnyCdnHostname'] ?? ''),
                (string)($metadata['bunnyVideoId'] ?? ''),
                basename((string)parse_url($url, PHP_URL_PATH)),
            );
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $expected === $url ? $url : null;
    }
}
