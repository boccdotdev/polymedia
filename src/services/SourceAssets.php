<?php

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

/**
 * References native Craft videos without copying or taking ownership of them.
 */
class SourceAssets extends Component
{
    /** @var array<string, ?string> Includes misses, so unavailable sources are queried once. */
    private array $_urls = [];

    public function import(int $sourceAssetId, ?int $folderId, User $user): Asset
    {
        $plugin = Plugin::getInstance();
        $source = Craft::$app->getAssets()->getAssetById($sourceAssetId);
        if (!$source || !$source->canView($user)) {
            throw new ForbiddenHttpException('You cannot access this source asset.');
        }

        $url = $this->publicVideoUrl($source, true);
        if ($url === null || !$source->uid) {
            throw new \InvalidArgumentException('Choose an available public MP4, WebM, or MOV video outside the sidecar volume.');
        }

        $folder = $plugin->getManifestWriter()->resolveFolder($folderId, $user, $plugin->getSettings());
        if (!$folder) {
            throw new ForbiddenHttpException('Choose a writable library volume for media.');
        }

        $providerId = 'asset:' . $source->uid;
        $lock = 'polymedia:source:' . $source->uid;
        $mutex = Craft::$app->getMutex();
        if (!$mutex->acquire($lock, 10)) {
            throw new \RuntimeException('This source is being imported. Please try again.');
        }

        try {
            $record = $plugin->getMediaItems()->getByTypeAndProviderId('mp4', $providerId);
            $existing = $record ? Craft::$app->getAssets()->getAssetById((int)$record->assetId) : null;
            if ($existing && $existing->dateDeleted === null) {
                if (!$existing->canView($user) || !$user->can('saveAssets:' . $existing->getVolume()->uid)) {
                    throw new ForbiddenHttpException('You cannot access the existing media wrapper.');
                }
                return $existing;
            }

            return $plugin->getManifestWriter()->create(
                volumeId: (int)$folder->volumeId,
                folderId: (int)$folder->id,
                detection: new DetectionResult([
                    'type' => 'mp4',
                    'element' => 'video',
                    'providerId' => $providerId,
                    // The public manifest must not retain an obsolete or signed
                    // source URL. Readers resolve this reference at runtime.
                    'url' => $providerId,
                ]),
                title: (string)$source->title,
                extraMetadata: ['sourceAssetUid' => $source->uid],
            );
        } finally {
            $mutex->release($lock);
        }
    }

    /**
     * Never falls back to the stored URL for a native-asset reference.
     */
    public function resolveUrl(array $manifest): ?string
    {
        $metadata = $manifest['metadata'] ?? [];
        if (!self::isReference($manifest)) {
            return isset($manifest['url']) && is_string($manifest['url']) ? $manifest['url'] : null;
        }

        $uid = is_array($metadata) ? ($metadata['sourceAssetUid'] ?? null) : null;
        if (!is_string($uid) || $uid === '') {
            return null;
        }
        $this->prime([$manifest]);
        return $this->_urls[$uid] ?? null;
    }

    public static function isReference(array $manifest): bool
    {
        $metadata = $manifest['metadata'] ?? [];
        return (is_array($metadata) && array_key_exists('sourceAssetUid', $metadata))
            || str_starts_with((string)($manifest['providerId'] ?? ''), 'asset:');
    }

    /** Batch source lookups for already-loaded manifests, including GraphQL batches. */
    public function prime(array $manifests): void
    {
        $missing = [];
        foreach ($manifests as $manifest) {
            $uid = $manifest['metadata']['sourceAssetUid'] ?? null;
            if (is_string($uid) && $uid !== '' && !array_key_exists($uid, $this->_urls)) {
                $missing[$uid] = true;
            }
        }
        if ($missing === []) {
            return;
        }
        foreach ($missing as $uid => $_) {
            $this->_urls[$uid] = null;
        }
        try {
            foreach ($this->findSources(array_keys($missing)) as $asset) {
                if (isset($missing[$asset->uid])) {
                    $this->_urls[$asset->uid] = $this->publicVideoUrl($asset);
                }
            }
        } catch (\Throwable) {
            // A broken volume/query must not expose a cached manifest URL.
        }
    }

    public function reset(): void
    {
        $this->_urls = [];
    }

    /** @return Asset[] */
    protected function findSources(array $uids): array
    {
        return Asset::find()->uid($uids)->status(null)->trashed(false)->all();
    }

    /** Filesystem existence checks belong to import, never page rendering. */
    protected function publicVideoUrl(Asset $asset, bool $verifyFile = false): ?string
    {
        try {
            if ($asset->dateDeleted !== null || $asset->kind !== 'video'
                || !in_array(strtolower(pathinfo($asset->getFilename(), PATHINFO_EXTENSION)), ['mp4', 'webm', 'mov'], true)) {
                return null;
            }
            $volume = $asset->getVolume();
            if ($volume->uid === Plugin::getInstance()->getSettings()->sidecarVolumeUid) {
                return null;
            }
            $fs = $volume->getFs();
            if (!$fs->hasUrls || ($verifyFile && !$fs->fileExists($asset->getPath()))) {
                return null;
            }
            $url = $asset->getUrl();
            $parts = is_string($url) ? parse_url($url) : false;
            if (!$parts || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
                return null;
            }
            return $url;
        } catch (\Throwable) {
            return null;
        }
    }
}
