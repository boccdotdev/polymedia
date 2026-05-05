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

use boccdotdev\polymedia\records\FieldRelationRecord;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use yii\base\Component;

/**
 * Service for per-placement poster overrides stored in `polymedia_field_relations`.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class FieldOverrides extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Returns the poster override asset for a given relation ID.
     *
     * @param int $relationId the relations table row ID
     * @return ?Asset the poster override asset, or null
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getPosterOverride(int $relationId): ?Asset
    {
        $record = FieldRelationRecord::findOne(['relationId' => $relationId]);

        if (!$record || !$record->posterAssetId) {
            return null;
        }

        return Craft::$app->getAssets()->getAssetById((int)$record->posterAssetId);
    }

    /**
     * Returns the poster override for a specific media asset within a field placement.
     *
     * @param Asset $mediaAsset the polymedia asset
     * @param ElementInterface $source the source element (entry, etc.)
     * @param string $fieldHandle the field handle
     * @param ?int $siteId optional site ID (for site-scoped relations)
     * @return ?Asset the poster override asset, or null
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getPosterOverrideForPlacement(
        Asset $mediaAsset,
        ElementInterface $source,
        string $fieldHandle,
        ?int $siteId = null,
    ): ?Asset {
        $field = Craft::$app->getFields()->getFieldByHandle($fieldHandle);

        if (!$field) {
            return null;
        }

        $query = (new \craft\db\Query())
            ->select(['id'])
            ->from('{{%relations}}')
            ->where([
                'sourceId' => $source->id,
                'fieldId' => $field->id,
                'targetId' => $mediaAsset->id,
            ]);

        if ($siteId !== null) {
            $query->andWhere(['sourceSiteId' => $siteId]);
        }

        $relationId = $query->scalar();

        if (!$relationId) {
            return null;
        }

        return $this->getPosterOverride((int)$relationId);
    }

    /**
     * Sets or clears the poster override for a relation.
     *
     * @param int $relationId the relations table row ID
     * @param ?int $posterAssetId the poster asset ID, or null to clear
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function setOverride(int $relationId, ?int $posterAssetId): void
    {
        $record = FieldRelationRecord::findOne(['relationId' => $relationId]);

        if ($posterAssetId) {
            if ($record) {
                $record->posterAssetId = $posterAssetId;
                $record->save();
            } else {
                $record = new FieldRelationRecord();
                $record->relationId = $relationId;
                $record->posterAssetId = $posterAssetId;
                $record->save();
            }
        } elseif ($record) {
            $record->delete();
        }
    }
}
