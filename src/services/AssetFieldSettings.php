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

use Craft;
use boccdotdev\polymedia\records\FieldSettingsRecord;
use craft\helpers\Json;
use yii\base\Component;

/**
 * Per-field plugin settings for plain Assets fields.
 *
 * Stores the {@see \boccdotdev\polymedia\fields\PolymediaField}-equivalent
 * settings (currently `allowedProviders`) for native `craft\fields\Assets`
 * fields where the `polymedia` kind is enabled. Keyed by field UID so the
 * row survives field renames.
 *
 * @author boccdotdev
 * @since 1.1.0
 */
class AssetFieldSettings extends Component
{
    // Private Properties
    // =========================================================================

    /**
     * @var array<string, array> In-memory cache of `allowedProviders` keyed by field UID.
     */
    private array $_cache = [];

    // Public Methods
    // =========================================================================

    /**
     * Returns the allowed provider keys for the given field UID. Empty = all allowed.
     *
     * @param string $fieldUid the field UID
     * @return string[]
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function getAllowedProviders(string $fieldUid): array
    {
        if (array_key_exists($fieldUid, $this->_cache)) {
            return $this->_cache[$fieldUid];
        }

        // Guard the migration window: an element may validate before this
        // plugin's table-creating migration has run (e.g. a core migration
        // re-saves sections during `craft up`). Don't cache — the table may
        // exist on a later call within the same request.
        if (!Craft::$app->getDb()->tableExists(FieldSettingsRecord::tableName())) {
            return [];
        }

        $record = FieldSettingsRecord::findOne(['fieldUid' => $fieldUid]);
        $providers = [];

        if ($record && $record->allowedProviders) {
            $decoded = Json::decodeIfJson($record->allowedProviders);

            if (is_array($decoded)) {
                $providers = array_values(array_filter($decoded, 'is_string'));
            }
        }

        return $this->_cache[$fieldUid] = $providers;
    }

    /**
     * Persists the allowed provider keys for a field. An empty array deletes the row.
     *
     * @param string $fieldUid the field UID
     * @param string[] $providers the allowed provider keys
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function setAllowedProviders(string $fieldUid, array $providers): void
    {
        $providers = array_values(array_filter($providers, 'is_string'));

        if (empty($providers)) {
            $this->delete($fieldUid);
            return;
        }

        $record = FieldSettingsRecord::findOne(['fieldUid' => $fieldUid]) ?? new FieldSettingsRecord();
        $record->fieldUid = $fieldUid;
        $record->allowedProviders = Json::encode($providers);
        $record->save();

        $this->_cache[$fieldUid] = $providers;
    }

    /**
     * Removes any stored settings for the given field UID.
     *
     * @param string $fieldUid the field UID
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function delete(string $fieldUid): void
    {
        $record = FieldSettingsRecord::findOne(['fieldUid' => $fieldUid]);

        if ($record) {
            $record->delete();
        }

        unset($this->_cache[$fieldUid]);
    }
}
