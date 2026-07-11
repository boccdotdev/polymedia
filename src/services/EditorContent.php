<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use Craft;
use craft\elements\Asset;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\models\VolumeFolder;
use yii\base\Component;

/**
 * Control-panel editor HTML and element-select configs for polymedia assets.
 *
 * Extracted from {@see Plugin} so editor UI stays out of the plugin bootstrap.
 *
 * @author boccdotdev
 * @since 1.3.0
 */
class EditorContent extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * Track roles attachable from the asset editor, in display order.
     *
     * @var array<string, string>
     */
    public const TRACK_ROLES = [
        'captions' => 'Captions',
        'subtitles' => 'Subtitles',
        'descriptions' => 'Descriptions',
    ];

    // Public Methods
    // =========================================================================

    /**
     * Renders the media metadata and poster/track fields for the asset editor.
     *
     * @param MediaItemRecord $record the media item
     * @param bool $static whether fields are read-only
     * @param Asset $asset the `.pmedia` asset being edited
     * @return string
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function renderMediaFields(MediaItemRecord $record, bool $static, Asset $asset): string
    {
        $plugin = Plugin::getInstance();
        $providerTypes = $plugin->getUrlDetector()->getProviderTypes();
        $typeOptions = [];

        foreach ($providerTypes as $type) {
            $typeOptions[] = ['label' => ucfirst($type), 'value' => $type];
        }

        $html = '';

        $html .= Cp::textFieldHtml([
            'label' => Craft::t('polymedia', 'Media URL'),
            'id' => 'polymedia-url',
            'name' => 'polymediaUrl',
            'value' => $record->url,
            'disabled' => $static,
        ]);

        $html .= Cp::selectFieldHtml([
            'label' => Craft::t('polymedia', 'Media Type'),
            'id' => 'polymedia-type',
            'name' => 'polymediaType',
            'value' => $record->type,
            'options' => $typeOptions,
            'disabled' => $static,
        ]);

        if ($record->providerId) {
            $html .= Cp::textFieldHtml([
                'label' => Craft::t('polymedia', 'Provider ID'),
                'id' => 'polymedia-provider-id',
                'name' => 'polymediaProviderId',
                'value' => $record->providerId,
                'disabled' => true,
                'readonly' => true,
            ]);
        }

        if ($record->duration) {
            $html .= Html::beginTag('div', ['class' => 'data', 'style' => 'margin-bottom: 14px;']);
            $html .= Html::tag('div', Craft::t('polymedia', 'Duration') . ': '
                . Html::encode($this->formatDuration((int)$record->duration)), ['class' => 'light']);
            $html .= Html::endTag('div');
        }

        $posterAsset = $plugin->getRelatedAssets()->getPoster($record->id);
        $elements = $posterAsset ? [$posterAsset] : [];

        $html .= Cp::elementSelectFieldHtml(
            $this->getPosterFieldConfig($asset->getFolder(), $elements, $static),
        );

        if ($this->supportsTracks($record)) {
            $folder = $asset->getFolder();
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;

            foreach (array_keys(self::TRACK_ROLES) as $role) {
                $trackAssets = $plugin->getRelatedAssets()->resolveTracks($record->id, $role, $siteId);
                $html .= Cp::elementSelectFieldHtml(
                    $this->getTrackFieldConfig($role, $folder, $trackAssets, $static),
                );
            }
        }

        return $html;
    }

    /**
     * Whether the media type accepts text tracks.
     *
     * @param MediaItemRecord $record the media item
     * @return bool
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function supportsTracks(MediaItemRecord $record): bool
    {
        return str_contains((string)$record->element, 'video');
    }

    /**
     * Builds the poster image picker config.
     *
     * @param VolumeFolder|null $folder the folder posters should default to
     * @param Asset[] $elements the currently selected poster (zero or one)
     * @param bool $static whether the field is read-only
     * @return array
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function getPosterFieldConfig(?VolumeFolder $folder, array $elements = [], bool $static = false): array
    {
        ['jsClass' => $jsClass, 'jsSettings' => $jsSettings, 'canUpload' => $canUpload] =
            $this->uploadJsConfig($folder, $static);

        return [
            'label' => Craft::t('polymedia', 'Poster Image'),
            'instructions' => $canUpload
                ? Craft::t('polymedia', 'Select or upload an image to use as the poster/thumbnail for this media item.')
                : Craft::t('polymedia', 'Select an image to use as the poster/thumbnail for this media item.'),
            'id' => 'polymedia-poster',
            'name' => 'polymediaPoster',
            'elementType' => Asset::class,
            'jsClass' => $jsClass,
            'sources' => '*',
            'criteria' => [
                'kind' => ['image'],
                'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
            ],
            'single' => true,
            'limit' => 1,
            'elements' => $elements,
            'disabled' => $static,
            'jsSettings' => $jsSettings,
        ];
    }

    /**
     * Builds a caption/subtitle/description track picker config for a single role.
     *
     * @param string $role one of `captions`, `subtitles`, `descriptions`
     * @param VolumeFolder|null $folder the folder uploads should default to
     * @param Asset[] $elements the tracks already attached for this role + site
     * @param bool $static whether the field is read-only
     * @return array
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function getTrackFieldConfig(string $role, ?VolumeFolder $folder, array $elements = [], bool $static = false): array
    {
        ['jsClass' => $jsClass, 'jsSettings' => $jsSettings, 'canUpload' => $canUpload] =
            $this->uploadJsConfig($folder, $static);

        $label = Craft::t('polymedia', self::TRACK_ROLES[$role] ?? ucfirst($role));

        return [
            'label' => $label,
            'instructions' => $canUpload
                ? Craft::t('polymedia', 'Select or upload WebVTT files for the current site.')
                : Craft::t('polymedia', 'Select WebVTT files for the current site.'),
            'id' => "polymedia-{$role}",
            'name' => 'polymedia' . ucfirst($role),
            'elementType' => Asset::class,
            'jsClass' => $jsClass,
            'sources' => '*',
            'criteria' => [
                'kind' => [Asset::KIND_CAPTIONS_SUBTITLES],
                'siteId' => Craft::$app->getSites()->getCurrentSite()->id,
            ],
            'elements' => $elements,
            'disabled' => $static,
            'jsSettings' => $jsSettings,
        ];
    }

    /**
     * Formats a duration in seconds as `H:MM:SS` or `M:SS`.
     *
     * @param ?int $seconds the duration
     * @return string
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function formatDuration(?int $seconds): string
    {
        if ($seconds === null || $seconds < 0) {
            return '';
        }

        $h = intdiv($seconds, 3600);
        $m = intdiv($seconds % 3600, 60);
        $s = $seconds % 60;

        if ($h > 0) {
            return sprintf('%d:%02d:%02d', $h, $m, $s);
        }

        return sprintf('%d:%02d', $m, $s);
    }

    // Private Methods
    // =========================================================================

    /**
     * @param VolumeFolder|null $folder the folder uploads should land in
     * @param bool $static whether the field is read-only
     * @return array{jsClass: string, jsSettings: array, canUpload: bool}
     */
    private function uploadJsConfig(?VolumeFolder $folder, bool $static): array
    {
        $canUpload = false;
        $jsClass = 'Craft.BaseElementSelectInput';
        $jsSettings = [];

        if (!$static && $folder && $folder->volumeId) {
            $volume = $folder->getVolume();
            $currentUser = Craft::$app->getUser()->getIdentity();

            try {
                $fsType = get_class($volume->getFs());
            } catch (\Throwable) {
                $fsType = null;
            }

            $canUpload = $fsType !== null
                && $currentUser
                && $currentUser->can("saveAssets:{$volume->uid}");

            if ($canUpload) {
                $jsClass = 'Craft.PolymediaPosterInput';
                $jsSettings = [
                    'canUpload' => true,
                    'fsType' => $fsType,
                    'folderId' => (int)$folder->id,
                    'modalSettings' => [
                        'defaultSource' => $this->_folderSourceKey($folder),
                    ],
                ];
            }
        }

        return ['jsClass' => $jsClass, 'jsSettings' => $jsSettings, 'canUpload' => $canUpload];
    }

    /**
     * @param VolumeFolder $folder
     * @return string
     */
    private function _folderSourceKey(VolumeFolder $folder): string
    {
        if ($folder->parentId) {
            return "folder:{$folder->uid}";
        }

        return "volume:{$folder->getVolume()->uid}";
    }
}
