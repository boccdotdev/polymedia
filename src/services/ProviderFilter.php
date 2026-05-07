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

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use yii\base\Component;

/**
 * Validates that the polymedia assets selected in a field belong to the
 * configured allowed providers. Shared between
 * {@see \boccdotdev\polymedia\fields\PolymediaField} and the
 * {@see \boccdotdev\polymedia\behaviors\PolymediaAssetFieldBehavior}
 * attached to plain Assets fields.
 *
 * @author boccdotdev
 * @since 1.1.0
 */
class ProviderFilter extends Component
{
    // Public Methods
    // =========================================================================

    /**
     * Validates the selected assets for a field handle on an element.
     *
     * Adds an error to `$element` for every selected polymedia asset whose
     * provider is not in `$allowedProviders`. A non-polymedia asset is ignored.
     *
     * @param ElementInterface $element the element being validated
     * @param string $fieldHandle the field handle to read from
     * @param string[] $allowedProviders the provider keys allowed by the field
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function validate(ElementInterface $element, string $fieldHandle, array $allowedProviders): void
    {
        if (empty($allowedProviders)) {
            return;
        }

        $value = $element->getFieldValue($fieldHandle);

        if (!$value instanceof AssetQuery) {
            return;
        }

        $mediaItems = Plugin::getInstance()->getMediaItems();

        foreach ($value->all() as $asset) {
            /** @var Asset $asset */
            if ($asset->kind !== 'polymedia') {
                continue;
            }

            $record = $mediaItems->getByAssetId($asset->id);

            if (!$record) {
                continue;
            }

            if (!in_array($record->type, $allowedProviders, true)) {
                $element->addError(
                    $fieldHandle,
                    Craft::t(
                        'polymedia',
                        '"{title}" is a {type} media item, which is not allowed by this field.',
                        ['title' => $asset->title, 'type' => ucfirst($record->type)],
                    ),
                );
            }
        }
    }
}
