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
        // Stored off; native upload is decided per render by automatic
        // routing, so field config never depends on edition or credentials.
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
     * Returns whether the field offers native upload for an element: only when
     * new MP4s to its upload folder are routed to a provider it allows.
     *
     * @param ?ElementInterface $element the element being edited
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getRoutesUploads(?ElementInterface $element): bool
    {
        $plugin = Plugin::getInstance();
        $settings = $plugin->getSettings();

        if (!$settings->autoRouteVideoUploads || $settings->videoUploadVolumeUids === [] || !$plugin->getVideoEnabled()) {
            return false;
        }

        if ($this->allowedProviders !== [] && !in_array($settings->videoProvider, $this->allowedProviders, true)) {
            return false;
        }

        try {
            $folder = Craft::$app->getAssets()->getFolderById($this->resolveDynamicPathToFolderId($element));
        } catch (\Throwable) {
            return false;
        }

        return $folder !== null && in_array($folder->getVolume()->uid, $settings->videoUploadVolumeUids, true);
    }

    /**
     * @inheritdoc
     */
    protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline): string
    {
        $original = $this->allowUploads;
        $this->allowUploads = $this->getRoutesUploads($element);

        try {
            return parent::inputHtml($value, $element, $inline);
        } finally {
            $this->allowUploads = $original;
        }
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
