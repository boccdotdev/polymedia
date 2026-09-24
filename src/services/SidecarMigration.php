<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\records\MediaItemRecord;
use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use craft\services\Assets;

/**
 * Plans and executes conservative per-item storage migration.
 *
 * Legacy attachment ownership was never recorded. Those files stay in place;
 * only unshared files already using this item's explicit UID layout can move.
 * Preview and execution use the same decisions, without creating preview folders.
 */
class SidecarMigration
{
    public function __construct(
        private readonly Assets $assets,
        private readonly SidecarStorage $storage,
        private readonly RelatedAssets $relatedAssets,
    ) {
    }

    /**
     * @return array<array{assetId: int, kind: string, status: string, message: string}>
     */
    public function migrateItem(Asset $manifest, MediaItemRecord $record, bool $dryRun): array
    {
        $results = [];
        $seen = [];

        foreach ($this->relatedAssets->getForItem((int)$record->id) as $relation) {
            $assetId = (int)$relation->assetId;

            if (isset($seen[$assetId])) {
                continue;
            }

            $seen[$assetId] = true;
            $asset = $this->assets->getAssetById($assetId);
            $folder = $asset ? $this->assets->getFolderById((int)$asset->folderId) : null;

            if (!$asset || !$folder) {
                $results[] = $this->result($assetId, 'sidecar', 'kept', 'Asset or folder is unavailable; relation left unchanged.');
                continue;
            }

            if (!$this->storage->isItemFolder($folder, (string)$record->assetUid)) {
                $results[] = $this->result($assetId, 'sidecar', 'kept', 'Legacy or library asset ownership is uncertain; left in place for manual review.');
                continue;
            }

            if ((int)$folder->volumeId === (int)$this->storage->getVolume()?->id) {
                continue;
            }

            if ($this->storage->isSharedAsset($assetId, (int)$record->id)) {
                $results[] = $this->result($assetId, 'sidecar', 'kept', 'Referenced by another item or Craft field; left in place.');
                continue;
            }

            $results[] = $this->move(
                $asset,
                'sidecar',
                'configured sidecar volume / ' . $this->storage->itemFolderPath((string)$record->assetUid),
                $dryRun,
                fn(): bool => $this->storage->moveIntoItem($asset, $record),
            );
        }

        // Keep the manifest in place after a partial failure so a retry sees the
        // same source location. Successfully moved sidecars are already skipped.
        if (in_array('failed', array_column($results, 'status'), true)) {
            $results[] = $this->result((int)$manifest->id, 'manifest', 'kept', 'A sidecar move failed; retry before flattening.');

            return $results;
        }

        $folder = $this->assets->getFolderById((int)$manifest->folderId);

        if ($folder && $this->isLegacyManifestFolder($folder, $manifest, $record)) {
            $parent = $this->assets->getFolderById((int)$folder->parentId);

            if ($parent) {
                $results[] = $this->move(
                    $manifest,
                    'manifest',
                    "folder #{$parent->id} / {$parent->path}",
                    $dryRun,
                    fn(): bool => $this->assets->moveAsset(
                        $manifest,
                        $parent,
                        $this->assets->getNameReplacementInFolder($manifest->getFilename(), (int)$parent->id),
                    ),
                );
            }
        }

        return $results;
    }

    /**
     * This naming convention identifies manifests to flatten, not ownership of
     * any attachments or other files. Legacy folders are never deleted.
     */
    private function isLegacyManifestFolder(VolumeFolder $folder, Asset $asset, MediaItemRecord $record): bool
    {
        if (!$folder->parentId) {
            return false;
        }

        $slugs = array_unique(array_filter([
            StringHelper::slugify((string)$record->title),
            StringHelper::slugify((string)$asset->title),
            StringHelper::slugify(pathinfo($asset->getFilename(), PATHINFO_FILENAME)),
        ]));

        foreach ($slugs as $slug) {
            if (preg_match('/^' . preg_quote($slug, '/') . '-[a-zA-Z0-9]{8}$/', (string)$folder->name)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{assetId: int, kind: string, status: string, message: string}
     */
    private function move(Asset $asset, string $kind, string $destination, bool $dryRun, callable $move): array
    {
        $description = "{$asset->getFilename()} ⇒ {$destination}";

        if ($dryRun) {
            return $this->result((int)$asset->id, $kind, 'planned', $description);
        }

        try {
            if (!$move()) {
                $errors = implode(', ', $asset->getFirstErrors());
                throw new \RuntimeException($errors ?: 'Craft declined the move.');
            }
        } catch (\Throwable $e) {
            return $this->result((int)$asset->id, $kind, 'failed', "{$description}: {$e->getMessage()}");
        }

        return $this->result((int)$asset->id, $kind, 'moved', $description);
    }

    /**
     * @return array{assetId: int, kind: string, status: string, message: string}
     */
    private function result(int $assetId, string $kind, string $status, string $message): array
    {
        return compact('assetId', 'kind', 'status', 'message');
    }
}
