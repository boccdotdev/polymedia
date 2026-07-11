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

namespace boccdotdev\polymedia\controllers;

use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\elements\Asset;
use craft\elements\User;
use craft\models\VolumeFolder;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP controller for creating and managing polymedia items.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class MediaItemsController extends Controller
{
    // Const Properties
    // =========================================================================

    /**
     * @var string[] Signed-URL query parameter patterns that indicate a URL
     *               may contain a short-lived or secret token.
     */
    private const SIGNED_URL_PATTERNS = [
        '~[?&](token|sig|signature|Policy|Signature|KeyPair-Id|Key-Pair-Id)=~i',
        '~[?&]token=eyJ~',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Returns the "Add media URL" slideout screen.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function actionCreateScreen(): Response
    {
        $this->requireCpRequest();

        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();
        $providerTypes = $plugin->getUrlDetector()->getProviderTypes();

        $currentUser = Craft::$app->getUser()->getIdentity();
        $folderId = (int)Craft::$app->getRequest()->getParam('folderId') ?: null;
        $folder = $this->_resolveFolder($folderId, $currentUser, $settings);

        return $this->asCpScreen()
            ->title(Craft::t('polymedia', 'Add media URL'))
            ->contentTemplate('polymedia/_cp/create-screen', [
                'providerTypes' => $providerTypes,
                'folderId' => $folder?->id ?? '',
                'warnOnSignedUrl' => $settings->warnOnSignedUrlInPublicVolume,
                'posterFieldConfig' => $plugin->getPosterFieldConfig($folder),
            ])
            ->action('polymedia/media-items/create')
            ->submitButtonLabel(Craft::t('polymedia', 'Save'));
    }

    /**
     * Creates a new `.pmedia` manifest asset from the submitted form.
     *
     * @return ?Response
     * @throws \Throwable if the asset cannot be saved
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function actionCreate(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();

        $request = Craft::$app->getRequest();
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        $url = trim($request->getRequiredBodyParam('url'));
        $title = trim($request->getRequiredBodyParam('title'));
        $typeOverride = $request->getBodyParam('typeOverride') ?: null;
        $folderId = (int)$request->getBodyParam('folderId') ?: null;
        $confirmedSignedUrlWarning = (bool)$request->getBodyParam('confirmedSignedUrlWarning', false);

        if ($url === '' || $title === '') {
            return $this->asFailure(Craft::t('polymedia', 'URL and title are required.'));
        }

        $currentUser = Craft::$app->getUser()->getIdentity();
        $folder = $this->_resolveFolder($folderId, $currentUser, $settings);

        if (!$folder) {
            return $this->asFailure(Craft::t('polymedia', 'You do not have permission to save assets in this volume.'));
        }

        $volume = $folder->getVolume();

        $detection = $plugin->getUrlDetector()->detect($url, $typeOverride);

        if (!$detection) {
            return $this->asFailure(Craft::t('polymedia', 'Could not detect media type from this URL.'));
        }

        if (
            $settings->warnOnSignedUrlInPublicVolume &&
            !$confirmedSignedUrlWarning &&
            $this->_isSignedUrl($url) &&
            $this->_isPublicVolume($volume)
        ) {
            return $this->asFailure(Craft::t(
                'polymedia',
                'This URL appears to contain a signed token. Check "I understand" and save again to continue.',
            ));
        }

        $derivedThumbnail = $plugin->getThumbnailDeriver()->derive($detection);

        $asset = $plugin->getManifestWriter()->create(
            volumeId: (int)$folder->volumeId,
            folderId: (int)$folder->id,
            detection: $detection,
            title: $title,
            derivedThumbnail: $derivedThumbnail,
        );

        $posterIds = $request->getBodyParam('polymediaPoster');
        $record = $plugin->getMediaItems()->getByAssetId((int)$asset->id);
        $hasUserPoster = $posterIds !== null && $posterIds !== '' && $posterIds !== [];

        if ($hasUserPoster && $record) {
            $plugin->savePoster($record, $posterIds);
            $this->_coLocatePoster($posterIds, (int)$folder->id, $asset);
        } elseif ($record && $settings->autoFetchPoster) {
            // User poster wins; otherwise download a derived still when enabled.
            if ($detection->type === 'mux' && $detection->providerId !== '') {
                $plugin->getPosterFetcher()->ensureMuxPoster($record, $detection->providerId);
            } elseif ($derivedThumbnail) {
                $plugin->getPosterFetcher()->fetchForItem($record, $derivedThumbnail);
            }
        }

        return $this->asSuccess(
            Craft::t('polymedia', 'Media item created.'),
            [
                'assetId' => $asset->id,
                'redirectUrl' => $asset->getCpEditUrl(),
            ],
        );
    }

    // Private Methods
    // =========================================================================

    /**
     * Moves a poster uploaded on the create screen into the new item's folder.
     *
     * Inline uploads land in the folder the user was browsing; this co-locates
     * them with the `.pmedia` so each item's files stay together. A poster that
     * already lives elsewhere (a pre-existing asset the user selected) is left
     * where it is, since it may be shared.
     *
     * @param mixed $posterIds the submitted poster value
     * @param int $parentFolderId the folder the user was browsing
     * @param Asset $asset the newly created `.pmedia` asset
     */
    private function _coLocatePoster(mixed $posterIds, int $parentFolderId, Asset $asset): void
    {
        $posterAssetId = is_array($posterIds) ? (int)($posterIds[0] ?? 0) : (int)$posterIds;

        if (!$posterAssetId) {
            return;
        }

        $assets = Craft::$app->getAssets();
        $poster = $assets->getAssetById($posterAssetId);

        if (!$poster || $poster->folderId !== $parentFolderId) {
            return;
        }

        $assets->moveAsset($poster, $asset->getFolder());
    }

    /**
     * Resolves the target folder for a new `.pmedia` asset.
     *
     * Prefers the folder the user is currently viewing (passed from the asset
     * index), then the configured default volume, then the first volume the
     * user can save to. Only returns a folder the user has permission to use.
     *
     * @param int|null $folderId the current source folder, if any
     * @param User|null $user the current user
     * @param Settings $settings the plugin settings
     * @return VolumeFolder|null
     */
    private function _resolveFolder(?int $folderId, ?User $user, Settings $settings): ?VolumeFolder
    {
        $assets = Craft::$app->getAssets();

        if ($folderId) {
            $folder = $assets->getFolderById($folderId);

            if ($folder && $this->_canSave($folder, $user)) {
                return $folder;
            }
        }

        if ($settings->defaultVolumeUid) {
            $volume = Craft::$app->getVolumes()->getVolumeByUid($settings->defaultVolumeUid);

            if ($volume) {
                $folder = $assets->getRootFolderByVolumeId($volume->id);

                if ($folder && $this->_canSave($folder, $user)) {
                    return $folder;
                }
            }
        }

        foreach (Craft::$app->getVolumes()->getAllVolumes() as $volume) {
            if ($user && $user->can("saveAssets:$volume->uid")) {
                return $assets->getRootFolderByVolumeId($volume->id);
            }
        }

        return null;
    }

    /**
     * Checks whether the user can save assets in the given folder's volume.
     *
     * @param VolumeFolder $folder the target folder
     * @param User|null $user the current user
     * @return bool
     */
    private function _canSave(VolumeFolder $folder, ?User $user): bool
    {
        if (!$user || !$folder->volumeId) {
            return false;
        }

        return $user->can("saveAssets:{$folder->getVolume()->uid}");
    }

    /**
     * Checks whether a URL contains signed-URL query parameters.
     *
     * @param string $url the URL to check
     * @return bool
     */
    private function _isSignedUrl(string $url): bool
    {
        foreach (self::SIGNED_URL_PATTERNS as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether a volume uses a publicly accessible filesystem.
     *
     * @param \craft\models\Volume $volume the volume to check
     * @return bool
     */
    private function _isPublicVolume(\craft\models\Volume $volume): bool
    {
        try {
            $fs = $volume->getFs();

            return $fs->hasUrls;
        } catch (\Throwable) {
            return false;
        }
    }
}
