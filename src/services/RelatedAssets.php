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

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\records\RelatedAssetRecord;
use Craft;
use craft\elements\Asset;
use yii\base\Component;
use yii\base\InvalidArgumentException;

/**
 * Service for managing related assets (poster, captions, subtitles,
 * descriptions, transcript) attached to polymedia items.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class RelatedAssets extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[]
     */
    private const VALID_ROLES = ['poster', 'captions', 'subtitles', 'descriptions', 'transcript'];

    /**
     * @var string[]
     */
    private const SITE_REQUIRED_ROLES = ['captions', 'subtitles', 'descriptions'];

    /**
     * @var string[]
     */
    private const SITE_FORBIDDEN_ROLES = ['poster', 'transcript'];

    // Public Methods
    // =========================================================================

    /**
     * Returns all related assets for a media item.
     *
     * @param int $itemId the media item record ID
     * @return RelatedAssetRecord[]
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getForItem(int $itemId): array
    {
        return RelatedAssetRecord::findAll(['itemId' => $itemId]);
    }

    /**
     * Returns the poster related asset for a media item.
     *
     * @param int $itemId the media item record ID
     * @return ?Asset the poster asset, or null
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getPoster(int $itemId): ?Asset
    {
        $record = RelatedAssetRecord::findOne([
            'itemId' => $itemId,
            'role' => 'poster',
        ]);

        if (!$record) {
            return null;
        }

        return Craft::$app->getAssets()->getAssetById($record->assetId);
    }

    /**
     * Returns track-type related assets for a media item, filtered by role and site.
     *
     * @param int $itemId the media item record ID
     * @param string $role `captions`, `subtitles`, or `descriptions`
     * @param ?int $siteId optional site ID filter; null = all sites
     * @return Asset[]
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function resolveTracks(int $itemId, string $role, ?int $siteId = null): array
    {
        $assets = [];

        foreach ($this->getTrackRecords($itemId, $role, $siteId) as $record) {
            $asset = Craft::$app->getAssets()->getAssetById($record->assetId);

            if ($asset) {
                $assets[] = $asset;
            }
        }

        return $assets;
    }

    /**
     * Returns the raw track records for a media item, filtered by role and site.
     *
     * @param int $itemId the media item record ID
     * @param string $role `captions`, `subtitles`, or `descriptions`
     * @param ?int $siteId optional site ID filter; null = all sites
     * @return RelatedAssetRecord[]
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function getTrackRecords(int $itemId, string $role, ?int $siteId = null): array
    {
        $conditions = ['itemId' => $itemId, 'role' => $role];

        if ($siteId !== null) {
            $conditions['siteId'] = $siteId;
        }

        return RelatedAssetRecord::findAll($conditions);
    }

    /**
     * Returns the transcript related asset for a media item.
     *
     * @param int $itemId the media item record ID
     * @return ?Asset the transcript asset, or null
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getTranscript(int $itemId): ?Asset
    {
        $record = RelatedAssetRecord::findOne([
            'itemId' => $itemId,
            'role' => 'transcript',
        ]);

        if (!$record) {
            return null;
        }

        return Craft::$app->getAssets()->getAssetById($record->assetId);
    }

    /**
     * Attaches a related asset to a media item.
     *
     * @param int $itemId the media item record ID
     * @param int $assetId the asset ID to attach
     * @param string $role the role (poster, captions, subtitles, descriptions, transcript)
     * @param ?int $siteId the site ID (required for track roles, forbidden for poster/transcript)
     * @param ?string $srclang the language code for track roles
     * @param ?string $label the label for track roles
     * @param int $sortOrder sort position
     * @return RelatedAssetRecord the created record
     * @throws InvalidArgumentException if validation fails
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function attach(
        int $itemId,
        int $assetId,
        string $role,
        ?int $siteId = null,
        ?string $srclang = null,
        ?string $label = null,
        int $sortOrder = 0,
    ): RelatedAssetRecord {
        $this->_validateRole($role);
        $this->_validateSiteScope($role, $siteId);

        $asset = Craft::$app->getAssets()->getAssetById($assetId);

        if (!$asset) {
            throw new InvalidArgumentException("Asset #{$assetId} not found.");
        }

        $this->_validateAssetForRole($asset, $role);

        if ($role === 'poster' || $role === 'transcript') {
            RelatedAssetRecord::deleteAll([
                'itemId' => $itemId,
                'role' => $role,
            ]);
        }

        $record = new RelatedAssetRecord();
        $record->itemId = $itemId;
        $record->assetId = $assetId;
        $record->role = $role;
        $record->siteId = $siteId;
        $record->srclang = $srclang;
        $record->label = $label;
        $record->sortOrder = $sortOrder;
        $record->save();

        return $record;
    }

    /**
     * Detaches a related asset record.
     *
     * @param int $recordId the related asset record ID
     * @return bool whether the deletion succeeded
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function detach(int $recordId): bool
    {
        return RelatedAssetRecord::deleteAll(['id' => $recordId]) > 0;
    }

    /**
     * Detaches multiple related asset records in a single query.
     *
     * @param int[] $recordIds the related asset record IDs
     * @return int the number of records deleted
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function detachMany(array $recordIds): int
    {
        if (!$recordIds) {
            return 0;
        }

        return RelatedAssetRecord::deleteAll(['id' => $recordIds]);
    }

    /**
     * Removes the poster attachment from a media item, if any.
     *
     * @param int $itemId the media item record ID
     * @return int the number of records deleted
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function clearPoster(int $itemId): int
    {
        return RelatedAssetRecord::deleteAll(['itemId' => $itemId, 'role' => 'poster']);
    }

    /**
     * Validates that a VTT file starts with the WEBVTT header.
     *
     * @param Asset $asset the VTT asset to validate
     * @return bool
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function validateVtt(Asset $asset): bool
    {
        if (!Plugin::getInstance()->getSettings()->validateVttOnUpload) {
            return true;
        }

        try {
            $volume = $asset->getVolume();
            $fs = $volume->getFs();
            $contents = $fs->read($asset->getPath());
            $header = substr($contents, 0, 1024);

            $header = ltrim($header, "\xEF\xBB\xBF");

            return str_starts_with(trim($header), 'WEBVTT');
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Attaches or clears the item-level poster for a media item.
     *
     * Accepts the raw `polymediaPoster` submission (an asset ID, a single-element
     * array of one, or empty to clear). Non-image assets and assets the current
     * user can't view are ignored.
     *
     * @param MediaItemRecord $record the media item record
     * @param mixed $posterIds the submitted poster value
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function savePoster(MediaItemRecord $record, mixed $posterIds): void
    {
        if (is_array($posterIds)) {
            $posterAssetId = (int)($posterIds[0] ?? 0) ?: null;
        } else {
            $posterAssetId = (int)$posterIds ?: null;
        }

        if ($posterAssetId) {
            $posterAsset = Craft::$app->getAssets()->getAssetById($posterAssetId);

            if (!$posterAsset || $posterAsset->kind !== 'image') {
                return;
            }

            $currentUser = Craft::$app->getUser()->getIdentity();

            if (!$currentUser || !$currentUser->can("viewAssets:{$posterAsset->getVolume()->uid}")) {
                return;
            }

            $this->attach(
                itemId: $record->id,
                assetId: $posterAssetId,
                role: 'poster',
            );
        } else {
            $this->clearPoster($record->id);
        }
    }

    /**
     * Reconciles the attached track assets for a role on the current site.
     *
     * @param MediaItemRecord $record the media item record
     * @param string $role one of `captions`, `subtitles`, `descriptions`
     * @param mixed $assetIds the submitted asset IDs (array or empty to clear)
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function saveTracks(MediaItemRecord $record, string $role, mixed $assetIds): void
    {
        if (!isset(EditorContent::TRACK_ROLES[$role])) {
            return;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $submittedIds = array_filter(array_map('intval', (array)$assetIds));

        $existing = $this->getTrackRecords($record->id, $role, $site->id);
        $existingByAssetId = [];
        $staleIds = [];

        foreach ($existing as $existingRecord) {
            $existingByAssetId[$existingRecord->assetId] = $existingRecord;

            if (!in_array($existingRecord->assetId, $submittedIds, true)) {
                $staleIds[] = $existingRecord->id;
            }
        }

        $this->detachMany($staleIds);

        $currentUser = Craft::$app->getUser()->getIdentity();
        $srclang = explode('-', $site->language)[0] ?: null;
        $label = $site->getLocale()->getDisplayName();
        $sortOrder = 0;

        foreach ($submittedIds as $assetId) {
            $sortOrder++;

            if (isset($existingByAssetId[$assetId])) {
                continue;
            }

            $asset = Craft::$app->getAssets()->getAssetById($assetId);

            if (!$asset || $asset->kind !== Asset::KIND_CAPTIONS_SUBTITLES) {
                continue;
            }

            if (!$currentUser || !$currentUser->can("viewAssets:{$asset->getVolume()->uid}")) {
                continue;
            }

            if (!$this->validateVtt($asset)) {
                continue;
            }

            $this->attach(
                itemId: $record->id,
                assetId: $assetId,
                role: $role,
                siteId: $site->id,
                srclang: $srclang,
                label: $label,
                sortOrder: $sortOrder,
            );
        }
    }

    // Private Methods
    // =========================================================================

    /**
     * @param string $role the role to validate
     * @throws InvalidArgumentException if the role is invalid
     */
    private function _validateRole(string $role): void
    {
        if (!in_array($role, self::VALID_ROLES, true)) {
            throw new InvalidArgumentException("Invalid role: {$role}");
        }
    }

    /**
     * @param string $role the role
     * @param ?int $siteId the site ID
     * @throws InvalidArgumentException if site scope is invalid for the role
     */
    private function _validateSiteScope(string $role, ?int $siteId): void
    {
        if (in_array($role, self::SITE_REQUIRED_ROLES, true) && $siteId === null) {
            throw new InvalidArgumentException("Site ID is required for role: {$role}");
        }

        if (in_array($role, self::SITE_FORBIDDEN_ROLES, true) && $siteId !== null) {
            throw new InvalidArgumentException("Site ID must not be set for role: {$role}");
        }
    }

    /**
     * @param Asset $asset the asset
     * @param string $role the role
     * @throws InvalidArgumentException if the asset kind is wrong for the role
     */
    private function _validateAssetForRole(Asset $asset, string $role): void
    {
        if ($role === 'poster' && $asset->kind !== 'image') {
            throw new InvalidArgumentException('Poster must be an image asset.');
        }
    }
}
