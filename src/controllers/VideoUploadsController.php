<?php

namespace boccdotdev\polymedia\controllers;

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

/**
 * Shared authorization for explicit and automatically routed video uploads.
 */
class VideoUploadsController extends Controller
{
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
