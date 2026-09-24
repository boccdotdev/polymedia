<?php

namespace boccdotdev\polymedia\controllers;

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class SourceAssetsController extends Controller
{
    public function actionImport(): ?Response
    {
        $this->requirePostRequest();
        $this->requireCpRequest();
        $this->requireLogin();
        $request = Craft::$app->getRequest();
        $sourceAssetId = (int)$request->getRequiredBodyParam('sourceAssetId');
        $folderId = (int)$request->getBodyParam('folderId') ?: null;

        try {
            $asset = Plugin::getInstance()->getSourceAssets()->import(
                $sourceAssetId,
                $folderId,
                Craft::$app->getUser()->getIdentity(),
            );
        } catch (\InvalidArgumentException | \RuntimeException $e) {
            return $this->asFailure(Craft::t('polymedia', $e->getMessage()));
        }

        return $this->asSuccess(Craft::t('polymedia', 'Media item created.'), [
            'assetId' => $asset->id,
            'redirectUrl' => $asset->getCpEditUrl(),
        ]);
    }
}
