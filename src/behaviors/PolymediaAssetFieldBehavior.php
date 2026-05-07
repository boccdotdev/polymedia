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

namespace boccdotdev\polymedia\behaviors;

use boccdotdev\polymedia\Plugin;
use craft\fields\Assets;
use yii\base\Behavior;

/**
 * Exposes Polymedia per-field settings on plain `craft\fields\Assets` fields.
 *
 * Mirrors {@see \boccdotdev\polymedia\fields\PolymediaField}'s
 * `allowedProviders` filter so any Assets field with the `polymedia` kind
 * enabled can restrict selections to specific providers without switching
 * field type. Settings are persisted by {@see \boccdotdev\polymedia\services\AssetFieldSettings}
 * keyed on the field UID.
 *
 * @property-read Assets $owner
 * @author boccdotdev
 * @since 1.1.0
 */
class PolymediaAssetFieldBehavior extends Behavior
{
    // Private Properties
    // =========================================================================

    /**
     * @var string[] Submitted-but-unsaved provider keys, populated by
     *   {@see self::setPolymediaAllowedProviders()} when Craft constructs the
     *   field from the settings POST body. Persisted on field save by the
     *   plugin; otherwise ignored.
     */
    private array $_polymediaAllowedProviders = [];

    // Public Methods
    // =========================================================================

    /**
     * Coerces the raw POST value to a string[] of provider keys.
     *
     * Yii routes `$field->polymediaAllowedProviders = $value` to this setter
     * via `Component::__set` once {@see PolymediaAssetFieldBehavior} is
     * attached. The form's empty checkbox group submits as an empty string,
     * which is normalized to an empty array here.
     *
     * @param mixed $value the raw POST value
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function setPolymediaAllowedProviders(mixed $value): void
    {
        if (!is_array($value)) {
            $this->_polymediaAllowedProviders = [];
            return;
        }

        $this->_polymediaAllowedProviders = array_values(array_filter($value, 'is_string'));
    }

    /**
     * Returns the most recently submitted provider keys (in-memory only).
     *
     * @return string[]
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function getPolymediaAllowedProviders(): array
    {
        return $this->_polymediaAllowedProviders;
    }

    /**
     * Returns the persisted provider keys this field is allowed to select.
     * Empty = all allowed.
     *
     * @return string[]
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function getAllowedProviders(): array
    {
        if (!$this->owner->uid) {
            return $this->_polymediaAllowedProviders;
        }

        return Plugin::getInstance()->getAssetFieldSettings()->getAllowedProviders($this->owner->uid);
    }

    /**
     * Whether the `polymedia` kind is enabled for this field.
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function getAllowsPolymediaKind(): bool
    {
        if (!$this->owner->restrictFiles) {
            return true;
        }

        return in_array('polymedia', $this->owner->allowedKinds ?? [], true);
    }
}
