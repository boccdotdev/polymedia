<?php

namespace boccdotdev\polymedia\jobs;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\Bunny;
use Craft;
use craft\queue\BaseJob;

/** The shared fetcher rechecks the selected poster under the item lock. */
class FetchBunnyPoster extends BaseJob
{
    public int $itemId = 0;
    public string $providerId = '';
    public int $attempt = 0;

    public function execute($queue): void
    {
        $plugin = Plugin::getInstance();
        $record = $plugin->getMediaItems()->getById($this->itemId);
        if (!$record || $record->type !== 'bunny' || $record->providerId !== $this->providerId
            || $plugin->getRelatedAssets()->getPoster($this->itemId)
        ) {
            return;
        }
        $metadata = $plugin->getMediaItems()->getMetadata($record);
        $url = (string)($metadata['bunnyThumbnailUrl'] ?? '');
        $host = Bunny::validateHostname((string)($metadata['bunnyCdnHostname'] ?? ''));
        $video = Bunny::validateVideoId((string)($metadata['bunnyVideoId'] ?? ''));
        $filename = basename((string)parse_url($url, PHP_URL_PATH));
        if ($url === '' || Bunny::thumbnailUrl($host, $video, $filename) !== $url) {
            return;
        }
        if (!$plugin->getSidecarStorage()->getVolume()) {
            // Import already stored its remote fallback. Do not repeatedly patch
            // thumbnail metadata, which an editor may have changed.
            return;
        }
        $poster = $plugin->getPosterFetcher()->fetchForItem($record, $url);
        if (!$poster && $this->attempt < 7) {
            Craft::$app->getQueue()->delay(min(3600, 30 * (2 ** $this->attempt)))->push(new self([
                'itemId' => $this->itemId,
                'providerId' => $this->providerId,
                'attempt' => $this->attempt + 1,
            ]));
        }
    }

    protected function defaultDescription(): ?string
    {
        return Craft::t('polymedia', 'Fetching Bunny Stream poster');
    }
}
