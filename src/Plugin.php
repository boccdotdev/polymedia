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

namespace boccdotdev\polymedia;

use boccdotdev\polymedia\behaviors\PolymediaAssetBehavior;
use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\records\RelatedAssetRecord;
use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\services\FieldOverrides;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\RelatedAssets;
use boccdotdev\polymedia\services\Renderer;
use boccdotdev\polymedia\services\ThumbnailDeriver;
use boccdotdev\polymedia\services\UrlDetector;
use boccdotdev\polymedia\variables\PolymediaVariable;
use boccdotdev\polymedia\web\assets\cp\PolymediaAsset;
use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\controllers\ElementsController;
use craft\elements\Asset;
use craft\events\DefineAttributeHtmlEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\DefineElementEditorHtmlEvent;
use craft\events\ModelEvent;
use craft\events\RegisterAssetFileKindsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\helpers\Assets;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\services\Fields;
use craft\web\twig\variables\CraftVariable;
use craft\web\View;
use yii\base\Event;

/**
 * Polymedia plugin class.
 *
 * @property-read Settings $settings
 * @property-read UrlDetector $urlDetector
 * @property-read ThumbnailDeriver $thumbnailDeriver
 * @property-read ManifestWriter $manifestWriter
 * @property-read MediaItems $mediaItems
 * @property-read RelatedAssets $relatedAssets
 * @property-read FieldOverrides $fieldOverrides
 * @property-read Renderer $renderer
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

    // Public Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    public function init(): void
    {
        parent::init();

        $generalConfig = Craft::$app->getConfig()->getGeneral();

        if (!in_array('pmedia', $generalConfig->allowedFileExtensions, true)) {
            $generalConfig->allowedFileExtensions[] = 'pmedia';
        }

        $this->setComponents([
            'urlDetector' => UrlDetector::class,
            'thumbnailDeriver' => ThumbnailDeriver::class,
            'manifestWriter' => ManifestWriter::class,
            'mediaItems' => MediaItems::class,
            'relatedAssets' => RelatedAssets::class,
            'fieldOverrides' => FieldOverrides::class,
            'renderer' => Renderer::class,
        ]);

        $this->_registerFileKind();
        $this->_registerAssetDeleteHandler();
        $this->_registerAssetIndexAttributes();
        $this->_registerAssetReconciler();
        $this->_registerEditorContent();
        $this->_registerFieldType();
        $this->_registerAssetBehavior();
        $this->_registerTwigVariable();
        $this->_registerCpAssets();
    }

    /**
     * Returns the URL detector service.
     *
     * @return UrlDetector
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getUrlDetector(): UrlDetector
    {
        return $this->get('urlDetector');
    }

    /**
     * Returns the thumbnail deriver service.
     *
     * @return ThumbnailDeriver
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getThumbnailDeriver(): ThumbnailDeriver
    {
        return $this->get('thumbnailDeriver');
    }

    /**
     * Returns the manifest writer service.
     *
     * @return ManifestWriter
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getManifestWriter(): ManifestWriter
    {
        return $this->get('manifestWriter');
    }

    /**
     * Returns the media items service.
     *
     * @return MediaItems
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getMediaItems(): MediaItems
    {
        return $this->get('mediaItems');
    }

    /**
     * Returns the related assets service.
     *
     * @return RelatedAssets
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getRelatedAssets(): RelatedAssets
    {
        return $this->get('relatedAssets');
    }

    /**
     * Returns the field overrides service.
     *
     * @return FieldOverrides
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getFieldOverrides(): FieldOverrides
    {
        return $this->get('fieldOverrides');
    }

    /**
     * Returns the renderer service.
     *
     * @return Renderer
     *
     * @author boccdotdev
     * @since 1.0.0
     */
    public function getRenderer(): Renderer
    {
        return $this->get('renderer');
    }

    // Protected Methods
    // =========================================================================

    /**
     * @inheritdoc
     */
    protected function createSettingsModel(): ?Model
    {
        return new Settings();
    }

    /**
     * @inheritdoc
     */
    protected function settingsHtml(): ?string
    {
        $volumes = Craft::$app->getVolumes()->getAllVolumes();
        $volumeOptions = [['label' => Craft::t('polymedia', 'Select a volume…'), 'value' => '']];

        foreach ($volumes as $volume) {
            $volumeOptions[] = [
                'label' => $volume->name,
                'value' => $volume->uid,
            ];
        }

        return Craft::$app->getView()->renderTemplate('polymedia/settings', [
            'settings' => $this->getSettings(),
            'volumeOptions' => $volumeOptions,
        ]);
    }

    // Private Methods
    // =========================================================================

    /**
     * Registers the `polymedia` custom file kind for `.pmedia` files.
     */
    private function _registerFileKind(): void
    {
        Event::on(
            Assets::class,
            Assets::EVENT_REGISTER_FILE_KINDS,
            function(RegisterAssetFileKindsEvent $e) {
                $e->fileKinds['polymedia'] = [
                    'label' => Craft::t('polymedia', 'Media URL'),
                    'extensions' => ['pmedia'],
                ];
            },
        );
    }

    /**
     * Cleans up media item records when assets are deleted.
     */
    private function _registerAssetDeleteHandler(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_AFTER_DELETE,
            function(Event $e) {
                /** @var Asset $asset */
                $asset = $e->sender;
                $this->getMediaItems()->deleteByAssetId($asset->id);
            },
        );
    }

    /**
     * Registers custom table attributes, sort options, and searchable attributes
     * for polymedia assets in the asset element index.
     */
    private function _registerAssetIndexAttributes(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function(RegisterElementTableAttributesEvent $e) {
                $e->tableAttributes['polymediaType'] = [
                    'label' => Craft::t('polymedia', 'Media Type'),
                ];
                $e->tableAttributes['polymediaProvider'] = [
                    'label' => Craft::t('polymedia', 'Provider'),
                ];
                $e->tableAttributes['polymediaDuration'] = [
                    'label' => Craft::t('polymedia', 'Duration'),
                ];
            },
        );

        Event::on(
            Asset::class,
            Element::EVENT_DEFINE_ATTRIBUTE_HTML,
            function(DefineAttributeHtmlEvent $e) {
                /** @var Asset $asset */
                $asset = $e->sender;

                if (!in_array($e->attribute, ['polymediaType', 'polymediaProvider', 'polymediaDuration'], true)) {
                    return;
                }

                if ($asset->kind !== 'polymedia') {
                    $e->html = '';
                    $e->handled = true;
                    return;
                }

                $record = $this->getMediaItems()->getByAssetId($asset->id);

                if (!$record) {
                    $e->html = '';
                    $e->handled = true;
                    return;
                }

                $e->html = match ($e->attribute) {
                    'polymediaType' => Html::encode(ucfirst($record->type)),
                    'polymediaProvider' => Html::encode(parse_url($record->url, PHP_URL_HOST) ?: ''),
                    'polymediaDuration' => $this->_formatDuration($record->duration),
                    default => '',
                };
                $e->handled = true;
            },
        );

        Event::on(
            Asset::class,
            Element::EVENT_REGISTER_SORT_OPTIONS,
            function(RegisterElementSortOptionsEvent $e) {
                $e->sortOptions['polymediaType'] = [
                    'label' => Craft::t('polymedia', 'Media Type'),
                    'orderBy' => 'polymedia_items.type',
                    'defaultDir' => 'asc',
                ];
            },
        );

    }

    /**
     * Reconciles orphaned `.pmedia` assets that have no `MediaItemRecord` row.
     *
     * Covers assets created by the asset indexer or manual file upload outside
     * the plugin's "Add media URL" flow.
     */
    private function _registerAssetReconciler(): void
    {
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_SAVE,
            function(ModelEvent $e) {
                /** @var Asset $asset */
                $asset = $e->sender;

                if ($asset->kind !== 'polymedia') {
                    return;
                }

                if (!$e->isNew) {
                    $this->_syncFilename($asset);
                    $this->_saveMediaSettings($asset);
                }
            },
        );

        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function(ModelEvent $e) {
                /** @var Asset $asset */
                $asset = $e->sender;

                if ($asset->kind !== 'polymedia') {
                    return;
                }

                if (!$this->getMediaItems()->existsForAsset($asset->id)) {
                    $this->_reconcileManifest($asset);
                    return;
                }

                if (!$e->isNew) {
                    $record = $this->getMediaItems()->getByAssetId($asset->id);

                    if ($record) {
                        $this->_savePosterFromRequest($record);
                    }
                }
            },
        );
    }

    /**
     * Reads a `.pmedia` manifest file and creates the missing `MediaItemRecord`.
     *
     * @param Asset $asset the `.pmedia` asset with no DB record
     */
    private function _reconcileManifest(Asset $asset): void
    {
        try {
            $volume = $asset->getVolume();
            $fs = $volume->getFs();
            $contents = $fs->read($asset->getPath());
            $data = Json::decodeIfJson($contents);

            if (!is_array($data) || !isset($data['polymedia'], $data['type'], $data['url'])) {
                Craft::warning(
                    "Polymedia: skipping reconciliation for asset #{$asset->id} — invalid manifest shape.",
                    __METHOD__,
                );
                return;
            }

            $detection = new DetectionResult();
            $detection->type = $data['type'];
            $detection->url = $data['url'];
            $detection->providerId = $data['providerId'] ?? '';
            $detection->element = $data['element'] ?? $this->getUrlDetector()->getElementMap()[$data['type']] ?? '';

            $defaults = new PlayerSettings();

            $record = new MediaItemRecord();
            $record->assetId = $asset->id;
            $record->assetUid = $asset->uid;
            $record->type = $detection->type;
            $record->url = $detection->url;
            $record->providerId = $detection->providerId;
            $record->element = $detection->element;
            $record->title = $data['title'] ?? $asset->title ?? '';
            $record->defaults = Json::encode($defaults->toArray());

            if (isset($data['duration'])) {
                $record->duration = (int)$data['duration'];
            }

            $record->save();

            Craft::info(
                "Polymedia: reconciled asset #{$asset->id} as {$detection->type}.",
                __METHOD__,
            );
        } catch (\Throwable $e) {
            Craft::warning(
                "Polymedia: failed to reconcile asset #{$asset->id}: {$e->getMessage()}",
                __METHOD__,
            );
        }
    }

    /**
     * Syncs the `.pmedia` filename to the asset title when it changes.
     *
     * @param Asset $asset the polymedia asset
     */
    private function _syncFilename(Asset $asset): void
    {
        $slug = \craft\helpers\StringHelper::slugify($asset->title ?: 'media');

        if ($slug === '') {
            $slug = 'media';
        }

        $expectedFilename = "{$slug}.pmedia";
        $currentFilename = $asset->getFilename();

        if ($currentFilename === $expectedFilename) {
            return;
        }

        $asset->newFilename = $expectedFilename;
    }

    /**
     * Saves polymedia metadata from the asset edit form.
     *
     * @param Asset $asset the polymedia asset being saved
     */
    private function _saveMediaSettings(Asset $asset): void
    {
        $request = Craft::$app->getRequest();

        if ($request->getIsConsoleRequest()) {
            return;
        }

        $newUrl = $request->getBodyParam('polymediaUrl');

        if ($newUrl === null) {
            return;
        }

        $record = $this->getMediaItems()->getByAssetId($asset->id);

        if (!$record) {
            return;
        }

        $newType = $request->getBodyParam('polymediaType');
        $urlChanged = false;

        if (trim($newUrl) !== '' && $newUrl !== $record->url) {
            $record->url = trim($newUrl);
            $urlChanged = true;
        }

        if ($newType !== null && $newType !== $record->type) {
            $providerTypes = $this->getUrlDetector()->getProviderTypes();

            if (in_array($newType, $providerTypes, true)) {
                $record->type = $newType;
                $record->element = $this->getUrlDetector()->getElementMap()[$newType] ?? '';
            }
        }

        if ($urlChanged) {
            $detection = $this->getUrlDetector()->detect($record->url, $record->type);

            if ($detection) {
                $record->providerId = $detection->providerId;
                $record->element = $detection->element;
            }
        }

        $record->save();

        $this->getManifestWriter()->update($asset, [
            'url' => $record->url,
            'type' => $record->type,
            'providerId' => $record->providerId,
        ]);
    }

    /**
     * Saves or clears the item-level poster from the asset edit form.
     *
     * @param MediaItemRecord $record the media item record
     */
    private function _savePosterFromRequest(MediaItemRecord $record): void
    {
        $request = Craft::$app->getRequest();
        $posterIds = $request->getBodyParam('polymediaPoster');

        if ($posterIds === null) {
            return;
        }

        if (is_array($posterIds)) {
            $posterAssetId = (int)($posterIds[0] ?? 0) ?: null;
        } else {
            $posterAssetId = (int)$posterIds ?: null;
        }
        $relatedAssets = $this->getRelatedAssets();

        if ($posterAssetId) {
            $posterAsset = Craft::$app->getAssets()->getAssetById($posterAssetId);

            if (!$posterAsset || $posterAsset->kind !== 'image') {
                return;
            }

            $currentUser = Craft::$app->getUser()->getIdentity();

            if (!$currentUser || !$currentUser->can("viewAssets:{$posterAsset->getVolume()->uid}")) {
                return;
            }

            $relatedAssets->attach(
                itemId: $record->id,
                assetId: $posterAssetId,
                role: 'poster',
            );
        } else {
            $existing = RelatedAssetRecord::findOne([
                'itemId' => $record->id,
                'role' => 'poster',
            ]);

            if ($existing) {
                $relatedAssets->detach($existing->id);
            }
        }
    }

    /**
     * Appends polymedia fields to the asset editor content area.
     */
    private function _registerEditorContent(): void
    {
        Event::on(
            ElementsController::class,
            ElementsController::EVENT_DEFINE_EDITOR_CONTENT,
            function(DefineElementEditorHtmlEvent $event) {
                if (!$event->element instanceof Asset || $event->element->kind !== 'polymedia') {
                    return;
                }

                $record = $this->getMediaItems()->getByAssetId($event->element->id);

                if (!$record) {
                    return;
                }

                $fields = $this->_renderMediaFields($record, $event->static);
                $pos = strrpos($event->html, '</div>');

                if ($pos !== false) {
                    $event->html = substr($event->html, 0, $pos) . $fields . substr($event->html, $pos);
                } else {
                    $event->html .= $fields;
                }
            },
            null,
            false,
        );
    }

    /**
     * Renders the media metadata and poster fields for the editor.
     *
     * @param MediaItemRecord $record
     * @param bool $static
     * @return string
     */
    private function _renderMediaFields(MediaItemRecord $record, bool $static): string
    {
        $providerTypes = $this->getUrlDetector()->getProviderTypes();
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
                . Html::encode($this->_formatDuration((int)$record->duration)), ['class' => 'light']);
            $html .= Html::endTag('div');
        }

        $posterAsset = $this->getRelatedAssets()->getPoster($record->id);
        $elements = $posterAsset ? [$posterAsset] : [];

        $html .= Cp::elementSelectFieldHtml([
            'label' => Craft::t('polymedia', 'Poster Image'),
            'instructions' => Craft::t('polymedia', 'Select an image to use as the poster/thumbnail for this media item.'),
            'id' => 'polymedia-poster',
            'name' => 'polymediaPoster',
            'elementType' => Asset::class,
            'sources' => '*',
            'criteria' => ['kind' => 'image'],
            'single' => true,
            'elements' => $elements,
            'disabled' => $static,
        ]);

        return $html;
    }

    /**
     * Registers the Polymedia field type.
     */
    private function _registerFieldType(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_REGISTER_FIELD_TYPES,
            function(RegisterComponentTypesEvent $e) {
                $e->types[] = PolymediaField::class;
            },
        );
    }

    /**
     * Attaches {@see PolymediaAssetBehavior} to every {@see Asset}, exposing
     * `getPlayer()`, `getElement()`, `getData()`, `getPoster()`, `getTracks()`,
     * `getTranscript()`, and `getIsPolymedia()` to templates.
     */
    private function _registerAssetBehavior(): void
    {
        Event::on(
            Asset::class,
            Asset::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                $event->behaviors['polymedia'] = PolymediaAssetBehavior::class;
            },
        );
    }

    /**
     * Registers the `craft.polymedia` Twig variable.
     */
    private function _registerTwigVariable(): void
    {
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function(Event $e) {
                /** @var CraftVariable $variable */
                $variable = $e->sender;
                $variable->set('polymedia', PolymediaVariable::class);
            },
        );
    }

    /**
     * Registers the CP asset bundle on CP requests.
     */
    private function _registerCpAssets(): void
    {
        if (!Craft::$app->getRequest()->getIsCpRequest()) {
            return;
        }

        Event::on(
            View::class,
            View::EVENT_BEFORE_RENDER_TEMPLATE,
            function() {
                Craft::$app->getView()->registerAssetBundle(PolymediaAsset::class);
            },
        );
    }

    /**
     * Formats a duration in seconds as `H:MM:SS` or `M:SS`.
     *
     * @param int|null $seconds the duration in seconds
     * @return string
     */
    private function _formatDuration(?int $seconds): string
    {
        if ($seconds === null) {
            return '';
        }

        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $secs = $seconds % 60;

        if ($hours > 0) {
            return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
        }

        return sprintf('%d:%02d', $minutes, $secs);
    }
}
