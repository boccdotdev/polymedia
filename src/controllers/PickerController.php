<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\controllers;

use Craft;
use craft\controllers\ElementIndexesController;
use craft\elements\Asset;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Checks a candidate against Craft's native picker query without changing it.
 */
class PickerController extends ElementIndexesController
{
    public function beforeAction($action): bool
    {
        // Do not expose the other element-index actions through this controller.
        if ($action->id !== 'check') {
            throw new NotFoundHttpException();
        }

        $this->requireCpRequest();
        $this->requirePostRequest();

        return parent::beforeAction($action);
    }

    protected function elementType(): string
    {
        return Asset::class;
    }

    protected function context(): string
    {
        return 'modal';
    }

    public function actionCheck(): Response
    {
        $assetId = (int)$this->request->getRequiredBodyParam('assetId');
        $asset = $assetId > 0
            ? $this->getElementQuery()
                ->andWhere(['elements.id' => $assetId])
                ->one()
            : null;
        $user = Craft::$app->getUser()->getIdentity();

        return $this->asJson([
            'selectable' => $asset instanceof Asset && $user && $asset->canView($user),
        ]);
    }
}
