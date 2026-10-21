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

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use yii\base\Component;
use yii\web\ForbiddenHttpException;

/**
 * References public videos already in Craft from a `.pmedia`, without copying,
 * moving or owning them.
 *
 * The manifest stores `asset:<uid>`, never the source's URL. Readers resolve
 * the source's current URL, so moves and renames keep working, and a trashed
 * or deleted source becomes unavailable instead of falling back to a stale
 * address.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class SourceAssets extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Provider ID prefix for a source asset reference.
     * @since 2.5.0
     */
    public const PREFIX = 'asset:';

    /**
     * @var string[] Video extensions a reference can point at.
     */
    private const EXTENSIONS = ['mp4', 'webm', 'mov'];

    // Private Properties
    // =========================================================================

    /**
     * @var array<string, ?string> Resolved URLs by source UID, including misses,
     * so an unavailable source is only queried once per request.
     */
    private array $_urls = [];

    // Public Methods
    // =========================================================================

    /**
     * Creates a `.pmedia` reference to a video asset, or returns the existing one.
     *
     * The source file is checked once here, not on every render.
     *
     * @param int $sourceAssetId the video asset's ID
     * @param ?int $folderId the destination folder for a new reference
     * @param User $user the user importing
     * @return Asset the `.pmedia` reference
     * @throws ForbiddenHttpException when the user can't view the source, use the existing reference or write to the folder
     * @throws \InvalidArgumentException when the source isn't an available public video
     * @throws \RuntimeException when the source is being imported concurrently
     * @throws \Throwable when the manifest asset can't be saved
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function import(int $sourceAssetId, ?int $folderId, User $user): Asset
    {
        $plugin = Plugin::getInstance();
        $source = Craft::$app->getAssets()->getAssetById($sourceAssetId);

        if (!$source || !$source->canView($user)) {
            throw new ForbiddenHttpException('You cannot access this source asset.');
        }

        if (!$source->uid || $this->publicVideoUrl($source, true) === null) {
            throw new \InvalidArgumentException('Choose an available public MP4, WebM or MOV video outside the sidecar volume.');
        }

        $folder = $plugin->getManifestWriter()->resolveFolder($folderId, $user, $plugin->getSettings());

        if (!$folder) {
            throw new ForbiddenHttpException('Choose a writable library volume for media.');
        }

        $providerId = self::PREFIX . $source->uid;
        $lock = "polymedia:source:{$source->uid}";
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($lock, 10)) {
            throw new \RuntimeException('This source is being imported. Please try again.');
        }

        try {
            $record = $plugin->getMediaItems()->getByTypeAndProviderId('mp4', $providerId);
            $existing = $record ? Craft::$app->getAssets()->getAssetById((int)$record->assetId) : null;

            if ($existing && $existing->dateDeleted === null) {
                if (!$existing->canView($user) || !$user->can('saveAssets:' . $existing->getVolume()->uid)) {
                    throw new ForbiddenHttpException('You cannot access the existing media item.');
                }

                return $existing;
            }

            return $plugin->getManifestWriter()->create(
                volumeId: $folder->volumeId,
                folderId: (int)$folder->id,
                detection: new DetectionResult([
                    'type' => 'mp4',
                    'element' => 'video',
                    'providerId' => $providerId,
                    // Readers resolve this at runtime; a copied (possibly
                    // signed) URL would go stale or leak.
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
     * Returns the URL a manifest plays.
     *
     * Ordinary manifests return their URL. A reference returns its source's
     * current public URL, or null when the source is unavailable; it never
     * falls back to the stored value.
     *
     * @param array $manifest the manifest (`url`, `providerId`, `metadata`)
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function resolveUrl(array $manifest): ?string
    {
        if (!self::isReference($manifest)) {
            return is_string($manifest['url'] ?? null) ? $manifest['url'] : null;
        }

        $uid = is_array($manifest['metadata'] ?? null) ? ($manifest['metadata']['sourceAssetUid'] ?? null) : null;

        if (!is_string($uid) || $uid === '') {
            return null;
        }

        $this->prime([$manifest]);

        return $this->_urls[$uid] ?? null;
    }

    /**
     * Returns whether a manifest references a Craft asset.
     *
     * @param array $manifest the manifest
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function isReference(array $manifest): bool
    {
        $metadata = $manifest['metadata'] ?? [];

        return (is_array($metadata) && array_key_exists('sourceAssetUid', $metadata))
            || str_starts_with((string)($manifest['providerId'] ?? ''), self::PREFIX);
    }

    /**
     * Looks up the sources for a batch of manifests in one query.
     *
     * @param array[] $manifests manifests with `metadata.sourceAssetUid`
     *
     * @author boccdotdev
     * @since 2.5.0
     */
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

        foreach (array_keys($missing) as $uid) {
            $this->_urls[$uid] = null;
        }

        try {
            foreach ($this->findSources(array_keys($missing)) as $asset) {
                if (isset($missing[$asset->uid])) {
                    $this->_urls[$asset->uid] = $this->publicVideoUrl($asset);
                }
            }
        } catch (\Throwable $e) {
            // A broken volume or query leaves the sources unavailable.
            Craft::warning("Polymedia could not resolve source assets: {$e->getMessage()}", __METHOD__);
        }
    }

    /**
     * Clears the per-request URL cache.
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function reset(): void
    {
        $this->_urls = [];
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns the non-trashed assets with these UIDs, enabled or not.
     *
     * @param string[] $uids the source UIDs
     * @return Asset[]
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    protected function findSources(array $uids): array
    {
        return Asset::find()->uid($uids)->status(null)->trashed(false)->all();
    }

    /**
     * Returns a video asset's public HTTP(S) URL, or null when it can't play.
     *
     * Checking the file exists is for import only, never page rendering.
     *
     * @param Asset $asset the source asset
     * @param bool $verifyFile whether to check the file exists on the filesystem
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    protected function publicVideoUrl(Asset $asset, bool $verifyFile = false): ?string
    {
        try {
            if ($asset->dateDeleted !== null
                || $asset->kind !== 'video'
                || !in_array(strtolower(pathinfo($asset->getFilename(), PATHINFO_EXTENSION)), self::EXTENSIONS, true)
            ) {
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

            if (!$parts
                || !in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true)
                || empty($parts['host'])
                || isset($parts['user'])
                || isset($parts['pass'])
            ) {
                return null;
            }

            return $url;
        } catch (\Throwable) {
            return null;
        }
    }
}
