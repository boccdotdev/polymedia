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
use craft\helpers\UrlHelper;
use craft\web\Controller;
use yii\web\Response;

/**
 * CP JSON actions for Mux library browse, import, and direct upload.
 *
 * Phase A ships the Pro + credentials gates and action skeletons. Browse/import
 * (Phase B) and UpChunk upload (Phase C) fill in the real bodies.
 *
 * @author boccdotdev
 * @since 2.0.0
 */
class MuxController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Lists assets from the connected Mux account.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionLibrary(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        $request = Craft::$app->getRequest();
        $limit = (int)$request->getQueryParam('limit', 25);
        $page = (int)$request->getQueryParam('page', 1);

        try {
            $result = Plugin::getInstance()->getMux()->listAssets($limit, $page);
        } catch (\Throwable $e) {
            return $this->asFailure($e->getMessage());
        }

        // alreadyImported enrichment lands in Phase B with MediaItems lookup.
        return $this->asSuccess(data: $result);
    }

    /**
     * Imports a Mux asset into a Craft `.pmedia` (or reuses an existing one).
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionImport(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        return $this->asFailure(Craft::t(
            'polymedia',
            'Mux import is not available yet.',
        ));
    }

    /**
     * Creates a Mux direct-upload URL for the browser to PUT with UpChunk.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionCreateUpload(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        return $this->asFailure(Craft::t(
            'polymedia',
            'Mux direct upload is not available yet.',
        ));
    }

    /**
     * Polls a Mux direct upload until an asset id is available.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionUploadStatus(): Response
    {
        $this->requireCpRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        return $this->asFailure(Craft::t(
            'polymedia',
            'Mux upload status is not available yet.',
        ));
    }

    /**
     * Completes a Mux upload by creating/reusing a `.pmedia` asset.
     *
     * @return Response
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function actionCompleteUpload(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $denied = $this->_denyUnlessMuxEnabled();

        if ($denied !== null) {
            return $denied;
        }

        return $this->asFailure(Craft::t(
            'polymedia',
            'Mux upload completion is not available yet.',
        ));
    }

    // Private Methods
    // =========================================================================

    /**
     * Returns a failure response when the request is not Pro + Mux-configured,
     * or null when the caller may proceed.
     *
     * Lite / unlicensed Pro gets an upgrade CTA rather than a hard crash.
     * Missing credentials get a settings-oriented message.
     *
     * @return ?Response
     */
    private function _denyUnlessMuxEnabled(): ?Response
    {
        $plugin = Plugin::getInstance();

        if (!$plugin->getIsPro()) {
            return $this->asFailure(
                Craft::t(
                    'polymedia',
                    'Mux library and upload require Polymedia Pro. Upgrade in the Plugin Store.',
                ),
                data: [
                    'upgradeUrl' => 'https://plugins.craftcms.com/polymedia',
                    'code' => 'pro_required',
                ],
            );
        }

        if (!$plugin->getMux()->isConfigured()) {
            $settingsUrl = UrlHelper::cpUrl('settings/plugins/polymedia');

            return $this->asFailure(
                Craft::t(
                    'polymedia',
                    'Mux is not configured. Add a Token ID and Secret in Polymedia settings.',
                ),
                data: [
                    'settingsUrl' => $settingsUrl,
                    'code' => 'not_configured',
                ],
            );
        }

        return null;
    }
}
