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
use craft\web\Controller;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * CP JSON action that wraps an existing Craft video in a `.pmedia` reference.
 *
 * Available in Lite.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class SourceAssetsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Creates (or reuses) a `.pmedia` reference to a video asset.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST or lacks `sourceAssetId`
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionImport(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        $request = Craft::$app->getRequest();
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user) {
            return $this->asFailure(Craft::t('polymedia', 'You cannot access this source asset.'));
        }

        try {
            $asset = Plugin::getInstance()->getSourceAssets()->import(
                (int)$request->getRequiredBodyParam('sourceAssetId'),
                (int)$request->getBodyParam('folderId') ?: null,
                $user,
            );
        } catch (ForbiddenHttpException | \InvalidArgumentException | \RuntimeException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(Craft::t('polymedia', 'Media item created.'), [
            'assetId' => $asset->id,
            'redirectUrl' => $asset->getCpEditUrl(),
        ]);
    }
}
