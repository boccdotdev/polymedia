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
 * Authorizes hosted video uploads before the browser sends any bytes.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class VideoUploadsController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Checks the destination and returns a signed upload context.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST or the field is invalid
     * @throws ForbiddenHttpException when the upload isn't allowed
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionPreflight(): Response
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();

        return $this->asSuccess(data: Plugin::getInstance()->getVideoUploads()->preflight(
            Craft::$app->getRequest()->getBodyParams(),
        ));
    }
}
