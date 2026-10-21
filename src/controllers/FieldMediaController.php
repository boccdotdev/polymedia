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
use craft\elements\Asset;
use craft\web\Controller;
use yii\base\InvalidConfigException;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * CP JSON actions behind a field's Add video or audio menu.
 *
 * Available in Lite. The menu's items still go through their own actions,
 * which check the destination folder again.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class FieldMediaController extends Controller
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the folder a field's new media goes to, after checking the user
     * can add media to the field on its element.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionDestination(): Response
    {
        $this->_requireCpJsonPost();

        try {
            $folder = Plugin::getInstance()->getFieldMedia()->getDestination($this->_fieldInput());
        } catch (BadRequestHttpException | ForbiddenHttpException $e) {
            return $this->asFailure($e->getMessage());
        }

        return $this->asSuccess(data: ['folderId' => (int)$folder->id]);
    }

    /**
     * Returns whether an asset can be selected into the field on its element.
     *
     * @return Response
     * @throws BadRequestHttpException when the request isn't a CP JSON POST or lacks `assetId`
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function actionCheck(): Response
    {
        $this->_requireCpJsonPost();

        $assetId = (int)$this->request->getRequiredBodyParam('assetId');
        $asset = $assetId > 0 ? Craft::$app->getAssets()->getAssetById($assetId) : null;

        try {
            $selectable = $asset instanceof Asset
                && Plugin::getInstance()->getFieldMedia()->isSelectable($this->_fieldInput(), $asset);
        } catch (BadRequestHttpException | ForbiddenHttpException | InvalidConfigException) {
            $selectable = false;
        }

        return $this->asJson(['selectable' => $selectable]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Requires a CP JSON POST.
     *
     * @throws BadRequestHttpException when the request isn't one
     */
    private function _requireCpJsonPost(): void
    {
        $this->requireCpRequest();
        $this->requirePostRequest();
        $this->requireAcceptsJson();
    }

    /**
     * Returns the posted field, element and site.
     *
     * @return array{fieldId: int, elementId: int, siteId: int}
     */
    private function _fieldInput(): array
    {
        return [
            'fieldId' => (int)$this->request->getBodyParam('fieldId'),
            'elementId' => (int)$this->request->getBodyParam('elementId'),
            'siteId' => (int)$this->request->getBodyParam('siteId'),
        ];
    }
}
