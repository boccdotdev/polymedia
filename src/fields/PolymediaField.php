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

namespace boccdotdev\polymedia\fields;

use boccdotdev\polymedia\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\fields\Assets;
use craft\helpers\Cp;

/**
 * Polymedia field type.
 *
 * Extends `craft\fields\Assets` to scope selection to `.pmedia` assets
 * and provide provider filtering.
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class PolymediaField extends Assets
{
    // Public Properties
    // =========================================================================

    /**
     * @var array Allowed provider type keys. Empty array = all providers allowed.
     */
    public array $allowedProviders = [];

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public static function displayName(): string
    {
        return Craft::t('polymedia', 'Polymedia');
    }

    /**
     * @inheritdoc
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public static function icon(): string
    {
        return 'play';
    }

    /**
     * @inheritdoc
     */
    public function __construct(array $config = [])
    {
        $config['restrictFiles'] = true;
        $config['allowedKinds'] = ['polymedia'];
        $config['allowUploads'] = false;

        if (!isset($config['id'])) {
            $plugin = Plugin::getInstance();

            if ($plugin) {
                $settings = $plugin->getSettings();
                $config['allowedProviders'] = $config['allowedProviders'] ?? $settings->defaultFieldAllowedProviders;
            }
        }

        parent::__construct($config);
    }

    /**
     * @inheritdoc
     */
    protected function defineRules(): array
    {
        $rules = parent::defineRules();

        $rules[] = [['allowedProviders'], 'each', 'rule' => ['string']];

        return $rules;
    }

    /**
     * @inheritdoc
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getSettingsHtml(): ?string
    {
        $parentHtml = parent::getSettingsHtml();

        Craft::$app->getView()->registerCss('[id$="allow-uploads-field"] { display: none; }');

        $plugin = Plugin::getInstance();
        $providerTypes = $plugin->getUrlDetector()->getProviderTypes();

        $providerOptions = [];

        foreach ($providerTypes as $type) {
            $providerOptions[] = [
                'label' => ucfirst($type),
                'value' => $type,
            ];
        }

        $providersHtml = Cp::checkboxGroupFieldHtml([
            'label' => Craft::t('polymedia', 'Allowed Providers'),
            'instructions' => Craft::t('polymedia', 'Restrict which media providers can be selected. Leave all unchecked to allow any provider.'),
            'id' => 'allowedProviders',
            'name' => 'allowedProviders',
            'values' => $this->allowedProviders,
            'options' => $providerOptions,
        ]);

        $pos = strpos($parentHtml, '</fieldset>');

        if ($pos !== false) {
            $pos += strlen('</fieldset>');
            $parentHtml = substr($parentHtml, 0, $pos) . $providersHtml . substr($parentHtml, $pos);
        } else {
            $parentHtml .= $providersHtml;
        }

        return $parentHtml;
    }

    /**
     * @inheritdoc
     */
    public function getElementValidationRules(): array
    {
        $rules = parent::getElementValidationRules();
        $rules[] = 'validateProviderFilter';

        return $rules;
    }

    /**
     * Validates that all selected assets match the allowed providers.
     *
     * @param ElementInterface $element the element being validated
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function validateProviderFilter(ElementInterface $element): void
    {
        Plugin::getInstance()->getProviderFilter()->validate($element, $this->handle, $this->allowedProviders);
    }
}
