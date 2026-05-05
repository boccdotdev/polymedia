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

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\helpers\Json;
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

        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $volumeOptions = [];
        $folderOptions = [];

        $currentUser = Craft::$app->getUser()->getIdentity();

        foreach ($volumes as $volume) {
            if (!$currentUser || !$currentUser->can("saveAssets:$volume->uid")) {
                continue;
            }

            $volumeOptions[] = [
                'label' => $volume->name,
                'value' => $volume->id,
            ];

            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId($volume->id);

            if ($rootFolder) {
                $folderOptions[$volume->id] = $rootFolder->id;
            }
        }

        $defaultVolumeId = null;

        if ($settings->defaultVolumeUid) {
            $defaultVolume = Craft::$app->getVolumes()->getVolumeByUid($settings->defaultVolumeUid);

            if ($defaultVolume) {
                $defaultVolumeId = $defaultVolume->id;
            }
        }

        if (!$defaultVolumeId && !empty($volumeOptions)) {
            $defaultVolumeId = $volumeOptions[0]['value'];
        }

        $defaultFolderId = $defaultVolumeId ? ($folderOptions[$defaultVolumeId] ?? '') : '';

        return $this->asCpScreen()
            ->title(Craft::t('polymedia', 'Add media URL'))
            ->contentTemplate('polymedia/_cp/create-screen', [
                'providerTypes' => $providerTypes,
                'volumeOptions' => $volumeOptions,
                'folderOptionsJson' => Json::encode($folderOptions),
                'defaultVolumeId' => $defaultVolumeId,
                'defaultFolderId' => $defaultFolderId,
                'warnOnSignedUrl' => $settings->warnOnSignedUrlInPublicVolume,
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
        $volumeId = $request->getRequiredBodyParam('volumeId');
        $folderId = $request->getBodyParam('folderId', '');
        $confirmedSignedUrlWarning = (bool)$request->getBodyParam('confirmedSignedUrlWarning', false);

        if ($url === '' || $title === '') {
            return $this->asFailure(Craft::t('polymedia', 'URL and title are required.'));
        }

        if ((int)$folderId === 0) {
            $rootFolder = Craft::$app->getAssets()->getRootFolderByVolumeId((int)$volumeId);

            if ($rootFolder) {
                $folderId = $rootFolder->id;
            }
        }

        $volume = Craft::$app->getVolumes()->getVolumeById((int)$volumeId);

        if (!$volume) {
            return $this->asFailure(Craft::t('polymedia', 'Invalid volume.'));
        }

        $currentUser = Craft::$app->getUser()->getIdentity();

        if (!$currentUser || !$currentUser->can("saveAssets:$volume->uid")) {
            return $this->asFailure(Craft::t('polymedia', 'You do not have permission to save assets in this volume.'));
        }

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
            volumeId: (int)$volumeId,
            folderId: (int)$folderId,
            detection: $detection,
            title: $title,
            derivedThumbnail: $derivedThumbnail,
        );

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
