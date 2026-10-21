<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\services;

use craft\elements\Asset;
use craft\helpers\StringHelper;
use craft\models\VolumeFolder;
use craft\services\Assets;

/**
 * Finds and prunes the per-item folders Polymedia 1.2 to 2.1 created.
 *
 * Those versions put each `.pmedia` in its own `<slug>-<8 characters>`
 * folder, and `polymedia/migrate/sidecars` moves the manifest up into the
 * parent. So a legacy folder is a child folder whose name is the slug of a
 * `.pmedia` sitting beside it, plus 8 letters or digits. Items an editor
 * added inside another item's folder left nested legacy folders, so a folder
 * inside a legacy folder counts too when its name ends the same way.
 *
 * A folder is deleted only when nothing is left in it: no assets in it or
 * below it, trashed or not, no other folders, and no files on disk that
 * Craft never indexed.
 *
 * @author boccdotdev
 * @since 2.6.0
 */
class LegacyFolders
{
    // Const Properties
    // =========================================================================

    /**
     * Any name ending in a hyphen and 8 letters or digits.
     */
    private const NESTED_NAME = '/^.+-[a-zA-Z0-9]{8}$/D';

    // Public Methods
    // =========================================================================

    /**
     * @param Assets $assets Craft's assets service
     * @param SidecarStorage $storage the sidecar storage service
     * @param RelatedAssets $relatedAssets the related assets service
     * @param MediaItems $mediaItems the media items service
     */
    public function __construct(
        private readonly Assets $assets,
        private readonly SidecarStorage $storage,
        private readonly RelatedAssets $relatedAssets,
        private readonly MediaItems $mediaItems,
    ) {
    }

    /**
     * Returns the legacy folders beside the given manifests, deepest first.
     *
     * @param Asset[] $manifests the `.pmedia` assets
     * @return VolumeFolder[]
     *
     * @author boccdotdev
     * @since 2.6.0
     */
    public function find(array $manifests): array
    {
        $sidecarVolumeId = (int)$this->storage->getVolume()?->id;
        $children = [];
        $found = [];

        foreach ($manifests as $manifest) {
            $parentId = (int)$manifest->folderId;

            if (!isset($children[$parentId])) {
                $children[$parentId] = $parentId ? $this->assets->findFolders(['parentId' => $parentId]) : [];
            }

            $slugs = $this->_slugs($manifest);

            foreach ($children[$parentId] as $folder) {
                if ((int)$folder->volumeId === $sidecarVolumeId || isset($found[$folder->id])) {
                    continue;
                }

                foreach ($slugs as $slug) {
                    if (preg_match('/^' . preg_quote($slug, '/') . '-[a-zA-Z0-9]{8}$/D', (string)$folder->name)) {
                        $found[$folder->id] = $folder;
                        break;
                    }
                }
            }
        }

        // Nested legacy folders, whose own manifest may have moved or gone.
        foreach (array_values($found) as $folder) {
            foreach ($this->assets->getAllDescendantFolders($folder, withParent: false) as $descendant) {
                if (preg_match(self::NESTED_NAME, (string)$descendant->name)) {
                    $found[$descendant->id] = $descendant;
                }
            }
        }

        $found = array_values($found);
        usort($found, static fn(VolumeFolder $a, VolumeFolder $b): int => [substr_count((string)$b->path, '/'), (string)$a->path]
            <=> [substr_count((string)$a->path, '/'), (string)$b->path]);

        return $found;
    }

    /**
     * Deletes, or with `$apply` off only plans, every empty legacy folder.
     *
     * Folders are handled deepest first, so a chain of nested empty legacy
     * folders clears in one run. Each folder is checked again right before
     * it's deleted.
     *
     * @param Asset[] $manifests the `.pmedia` assets
     * @param bool $apply delete the folders rather than list them
     * @return array<array{folderId: int, path: string, status: string, message: string}>
     *         `planned`, `deleted`, `kept` or `failed`
     *
     * @author boccdotdev
     * @since 2.6.0
     */
    public function prune(array $manifests, bool $apply): array
    {
        $results = [];
        $gone = [];

        foreach ($this->find($manifests) as $folder) {
            $reason = $this->keepReason($folder, $gone);

            if ($reason !== null) {
                $results[] = $this->_result($folder, 'kept', $reason);
                continue;
            }

            if (!$apply) {
                $gone[$folder->id] = true;
                $results[] = $this->_result($folder, 'planned', 'empty');
                continue;
            }

            try {
                $this->assets->deleteFoldersByIds((int)$folder->id);
            } catch (\Throwable $e) {
                $results[] = $this->_result($folder, 'failed', $e->getMessage());
                continue;
            }

            $gone[$folder->id] = true;
            $results[] = $this->_result($folder, 'deleted', 'empty');
        }

        return $results;
    }

    /**
     * Returns why a folder must stay, or null when it's empty.
     *
     * @param VolumeFolder $folder the legacy folder
     * @param array<int, true> $gone folders already deleted, or planned for deletion, this run
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.6.0
     */
    public function keepReason(VolumeFolder $folder, array $gone = []): ?string
    {
        $descendants = $this->assets->getAllDescendantFolders($folder, withParent: false);

        foreach ($descendants as $descendant) {
            if (!isset($gone[$descendant->id])) {
                return "holds the folder {$descendant->path}";
            }
        }

        $folderIds = [(int)$folder->id, ...array_map(static fn(VolumeFolder $descendant): int => (int)$descendant->id, $descendants)];
        $assets = $this->assetsIn($folderIds);

        foreach ($assets as $asset) {
            if ($asset->kind === 'polymedia') {
                return "holds another item's manifest, {$asset->getFilename()}";
            }
        }

        foreach ($assets as $asset) {
            if ($asset->trashed) {
                return "holds a trashed asset, {$asset->getFilename()}";
            }

            if ($this->relatedAssets->isAssetRelated((int)$asset->id)) {
                return "holds a kept sidecar, {$asset->getFilename()}";
            }
        }

        if ($assets !== []) {
            return "holds {$assets[0]->getFilename()}";
        }

        $file = $this->fileOnDisk($folder);

        return $file !== null ? "holds unindexed files on disk, such as {$file}" : null;
    }

    // Protected Methods
    // =========================================================================

    /**
     * Returns up to 20 assets in the given folders, in any status, trashed or not.
     *
     * @param int[] $folderIds
     * @return Asset[]
     */
    protected function assetsIn(array $folderIds): array
    {
        return Asset::find()
            ->folderId($folderIds)
            ->status(null)
            ->trashed(null)
            ->site('*')
            ->unique()
            ->limit(20)
            ->all();
    }

    /**
     * Returns the path of a file under the folder on disk, or null when there's none.
     *
     * Deleting a folder deletes its directory with everything in it, including
     * files Craft never indexed.
     *
     * @param VolumeFolder $folder
     * @return ?string
     * @throws \craft\errors\FsException when the filesystem can't be listed
     */
    protected function fileOnDisk(VolumeFolder $folder): ?string
    {
        foreach ($folder->getVolume()->getFileList((string)$folder->path, true) as $listing) {
            if (!$listing->getIsDir()) {
                return $listing->getUri();
            }
        }

        return null;
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns the slugs a manifest's legacy folder could have been named after.
     *
     * Names that slugified to nothing were given `media`.
     *
     * @param Asset $manifest
     * @return string[]
     */
    private function _slugs(Asset $manifest): array
    {
        $record = $this->mediaItems->getByAssetId((int)$manifest->id);
        $slugs = array_values(array_unique(array_filter([
            StringHelper::slugify((string)$record?->title),
            StringHelper::slugify((string)$manifest->title),
            StringHelper::slugify(pathinfo($manifest->getFilename(), PATHINFO_FILENAME)),
        ])));

        return $slugs ?: ['media'];
    }

    /**
     * @return array{folderId: int, path: string, status: string, message: string}
     */
    private function _result(VolumeFolder $folder, string $status, string $message): array
    {
        return [
            'folderId' => (int)$folder->id,
            'path' => (string)$folder->path,
            'status' => $status,
            'message' => $message,
        ];
    }
}
