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
use boccdotdev\polymedia\behaviors\PolymediaAssetFieldBehavior;
use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\services\AssetFieldSettings;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\ProviderFilter;
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
use craft\events\FieldEvent;
use craft\events\ModelEvent;
use craft\events\RegisterAssetFileKindsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterElementSortOptionsEvent;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\TemplateEvent;
use craft\fields\Assets as AssetsField;
use craft\helpers\Assets;
use craft\helpers\Cp;
use craft\helpers\Html;
use craft\helpers\Json;
use craft\models\VolumeFolder;
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
 * @property-read Renderer $renderer
 * @property-read AssetFieldSettings $assetFieldSettings
 * @property-read ProviderFilter $providerFilter
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
    public string $schemaVersion = '1.1.0';

    /**
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * @var bool
     */
    public bool $hasCpSection = false;

    // Constants
    // =========================================================================

    /**
     * Track roles attachable from the asset editor, in display order.
     * Keys are roles; values are the untranslated field labels. Kept in sync
     * with the roles emitted by {@see Renderer::_buildTracksHtml()}.
     *
     * @var array<string,string>
     */
    private const TRACK_ROLES = [
        'captions' => 'Captions',
        'subtitles' => 'Subtitles',
        'descriptions' => 'Descriptions',
    ];

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
            'renderer' => Renderer::class,
            'assetFieldSettings' => AssetFieldSettings::class,
            'providerFilter' => ProviderFilter::class,
        ]);

        $this->_registerFileKind();
        $this->_registerAssetDeleteHandler();
        $this->_registerAssetIndexAttributes();
        $this->_registerAssetReconciler();
        $this->_registerEditorContent();
        $this->_registerFieldType();
        $this->_registerAssetBehavior();
        $this->_registerAssetFieldBehavior();
        $this->_registerAssetFieldSettingsUi();
        $this->_registerAssetFieldPersistence();
        $this->_registerAssetFieldValidation();
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

    /**
     * Returns the asset field settings service.
     *
     * @return AssetFieldSettings
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function getAssetFieldSettings(): AssetFieldSettings
    {
        return $this->get('assetFieldSettings');
    }

    /**
     * Returns the provider filter service.
     *
     * @return ProviderFilter
     *
     * @author boccdotdev
     * @since 1.1.0
     */
    public function getProviderFilter(): ProviderFilter
    {
        return $this->get('providerFilter');
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

                if ($asset->kind !== 'polymedia') {
                    return;
                }

                $this->getMediaItems()->deleteByAssetId($asset->id);

                if ($asset->hardDelete) {
                    $this->_deleteItemFolderIfDedicated($asset);
                }
            },
        );
    }

    /**
     * Deletes a `.pmedia`'s dedicated folder, and the poster/track files
     * co-located in it, when the item is hard-deleted.
     *
     * Only fires when the asset sits alone in its own folder (see
     * {@see ManifestWriter::isDedicatedItemFolder()}), so a shared folder the
     * item merely happens to sit in is never removed.
     *
     * @param Asset $asset the deleted `.pmedia` asset
     */
    private function _deleteItemFolderIfDedicated(Asset $asset): void
    {
        $folder = Craft::$app->getAssets()->getFolderById((int)$asset->folderId);

        if (!$folder) {
            return;
        }

        if (!$this->getManifestWriter()->isDedicatedItemFolder($folder, $asset->id)) {
            return;
        }

        Craft::$app->getAssets()->deleteFoldersByIds($folder->id, true);
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

                if (!$e->isNew && !Craft::$app->getRequest()->getIsConsoleRequest()) {
                    $record = $this->getMediaItems()->getByAssetId($asset->id);

                    if ($record) {
                        $this->_savePosterFromRequest($record);
                        $this->_saveTracksFromRequest($record);
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
        $posterIds = Craft::$app->getRequest()->getBodyParam('polymediaPoster');

        if ($posterIds === null) {
            return;
        }

        $this->savePoster($record, $posterIds);
    }

    /**
     * Attaches or clears the item-level poster for a media item.
     *
     * Accepts the raw `polymediaPoster` submission (an asset ID, a single-element
     * array of one, or empty to clear). Non-image assets and assets the current
     * user can't view are ignored.
     *
     * @param MediaItemRecord $record the media item record
     * @param mixed $posterIds the submitted poster value
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function savePoster(MediaItemRecord $record, mixed $posterIds): void
    {
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
            $relatedAssets->clearPoster($record->id);
        }
    }

    /**
     * Saves the per-site caption/subtitle/description tracks from the asset
     * edit form. Each role's submission replaces the existing tracks for the
     * current site only; rows for other sites are left untouched.
     *
     * @param MediaItemRecord $record the media item record
     */
    private function _saveTracksFromRequest(MediaItemRecord $record): void
    {
        if (!$this->_supportsTracks($record)) {
            return;
        }

        $request = Craft::$app->getRequest();

        foreach (array_keys(self::TRACK_ROLES) as $role) {
            $assetIds = $request->getBodyParam('polymedia' . ucfirst($role));

            if ($assetIds === null) {
                continue;
            }

            $this->saveTracks($record, $role, $assetIds);
        }
    }

    /**
     * Reconciles the attached track assets for a role on the current site.
     *
     * Attaches any newly selected assets and detaches ones that were removed,
     * scoped to the current CP site so other sites' tracks are preserved.
     * `srclang` and `label` are derived from the current site. Non-WebVTT
     * assets and assets the user can't view are skipped.
     *
     * @param MediaItemRecord $record the media item record
     * @param string $role one of `captions`, `subtitles`, `descriptions`
     * @param mixed $assetIds the submitted asset IDs (array or empty to clear)
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function saveTracks(MediaItemRecord $record, string $role, mixed $assetIds): void
    {
        if (!isset(self::TRACK_ROLES[$role])) {
            return;
        }

        $site = Craft::$app->getSites()->getCurrentSite();
        $submittedIds = array_filter(array_map('intval', (array)$assetIds));
        $relatedAssets = $this->getRelatedAssets();

        $existing = $relatedAssets->getTrackRecords($record->id, $role, $site->id);
        $existingByAssetId = [];
        $staleIds = [];

        foreach ($existing as $existingRecord) {
            $existingByAssetId[$existingRecord->assetId] = $existingRecord;

            if (!in_array($existingRecord->assetId, $submittedIds, true)) {
                $staleIds[] = $existingRecord->id;
            }
        }

        $relatedAssets->detachMany($staleIds);

        $currentUser = Craft::$app->getUser()->getIdentity();
        $srclang = explode('-', $site->language)[0] ?: null;
        $label = $site->getLocale()->getDisplayName();
        $sortOrder = 0;

        foreach ($submittedIds as $assetId) {
            $sortOrder++;

            if (isset($existingByAssetId[$assetId])) {
                continue;
            }

            $asset = Craft::$app->getAssets()->getAssetById($assetId);

            if (!$asset || $asset->kind !== Asset::KIND_CAPTIONS_SUBTITLES) {
                continue;
            }

            if (!$currentUser || !$currentUser->can("viewAssets:{$asset->getVolume()->uid}")) {
                continue;
            }

            $relatedAssets->attach(
                itemId: $record->id,
                assetId: $assetId,
                role: $role,
                siteId: $site->id,
                srclang: $srclang,
                label: $label,
                sortOrder: $sortOrder,
            );
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

                $fields = $this->_renderMediaFields($record, $event->static, $event->element);
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
     * @param Asset $asset the `.pmedia` asset being edited
     * @return string
     */
    private function _renderMediaFields(MediaItemRecord $record, bool $static, Asset $asset): string
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

        $html .= Cp::elementSelectFieldHtml(
            $this->getPosterFieldConfig($asset->getFolder(), $elements, $static),
        );

        if ($this->_supportsTracks($record)) {
            $folder = $asset->getFolder();
            $siteId = Craft::$app->getSites()->getCurrentSite()->id;

            foreach (array_keys(self::TRACK_ROLES) as $role) {
                $trackAssets = $this->getRelatedAssets()->resolveTracks($record->id, $role, $siteId);
                $html .= Cp::elementSelectFieldHtml(
                    $this->getTrackFieldConfig($role, $folder, $trackAssets, $static),
                );
            }
        }

        return $html;
    }

    /**
     * Whether the media type renders as a video element and so accepts text
     * tracks. Audio-only providers (Spotify, raw audio) don't take `<track>`.
     *
     * @param MediaItemRecord $record the media item
     * @return bool
     */
    private function _supportsTracks(MediaItemRecord $record): bool
    {
        return str_contains((string)$record->element, 'video');
    }

    /**
     * Builds the poster image picker config: an image-only asset select that
     * allows inline uploads, with browsing and uploads defaulting to the given
     * folder.
     *
     * Shared by the asset edit slideout and the "Add media URL" create screen so
     * a poster can be set in either place, and so uploaded posters land beside
     * the `.pmedia` file rather than the user's temp folder.
     *
     * Returned as a config (rather than rendered HTML) so callers in a
     * namespaced control-panel screen can render it via the `forms` macros
     * inside the active namespace. Pre-rendering here would namespace the
     * field markup but not the input's init JS, leaving the two ids out of
     * sync and the picker dead.
     *
     * @param VolumeFolder|null $folder the folder posters should default to
     * @param Asset[] $elements the currently selected poster (zero or one)
     * @param bool $static whether the field is read-only
     * @return array
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function getPosterFieldConfig(?VolumeFolder $folder, array $elements = [], bool $static = false): array
    {
        ['jsClass' => $jsClass, 'jsSettings' => $jsSettings, 'canUpload' => $canUpload] =
            $this->_uploadJsConfig($folder, $static);

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
     * Builds a caption/subtitle/description track picker config for a single
     * role: a multi-select, `captionsSubtitles`-kind asset select that allows
     * inline uploads pinned to the given folder.
     *
     * Tracks are scoped to the current CP site — the picker shows the tracks
     * already attached for this role + site, and saving reconciles only that
     * site's rows (see {@see saveTracks()}). Per-row `srclang`/`label` overrides
     * are derived from the site on save rather than edited here.
     *
     * @param string $role one of `captions`, `subtitles`, `descriptions`
     * @param VolumeFolder|null $folder the folder uploads should default to
     * @param Asset[] $elements the tracks already attached for this role + site
     * @param bool $static whether the field is read-only
     * @return array
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function getTrackFieldConfig(string $role, ?VolumeFolder $folder, array $elements = [], bool $static = false): array
    {
        ['jsClass' => $jsClass, 'jsSettings' => $jsSettings, 'canUpload' => $canUpload] =
            $this->_uploadJsConfig($folder, $static);

        $label = Craft::t('polymedia', self::TRACK_ROLES[$role] ?? ucfirst($role));

        return [
            'label' => $label,
            'instructions' => $canUpload
                ? Craft::t('polymedia', 'Select or upload WebVTT files for the current site.')
                : Craft::t('polymedia', 'Select WebVTT files for the current site.'),
            'id' => "polymedia-{$role}",
            'name' => "polymedia" . ucfirst($role),
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
     * Resolves the inline-upload JS class and settings for an asset select
     * field, pinning uploads to the given folder when the user can save there.
     *
     * @param VolumeFolder|null $folder the folder uploads should land in
     * @param bool $static whether the field is read-only
     * @return array{jsClass:string,jsSettings:array,canUpload:bool}
     */
    private function _uploadJsConfig(?VolumeFolder $folder, bool $static): array
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
     * Returns the asset index source key for a folder (e.g. `volume:UID` for a
     * volume root, or `folder:UID` for a subfolder).
     *
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
     * Attaches {@see PolymediaAssetFieldBehavior} to native `craft\fields\Assets`
     * instances, exposing `allowedProviders` and `allowsPolymediaKind` accessors.
     *
     * Skipped for {@see PolymediaField} (subclass) which has its own native
     * `allowedProviders` typed property.
     */
    private function _registerAssetFieldBehavior(): void
    {
        Event::on(
            AssetsField::class,
            AssetsField::EVENT_DEFINE_BEHAVIORS,
            function(DefineBehaviorsEvent $event) {
                if ($event->sender instanceof PolymediaField) {
                    return;
                }

                $event->behaviors['polymediaAssetField'] = PolymediaAssetFieldBehavior::class;
            },
        );
    }

    /**
     * Appends the "Allowed Providers" checkbox group to the Assets field
     * settings template when the `polymedia` kind is enabled (or no kind
     * restriction is set).
     */
    private function _registerAssetFieldSettingsUi(): void
    {
        Event::on(
            View::class,
            View::EVENT_AFTER_RENDER_TEMPLATE,
            function(TemplateEvent $event) {
                $template = preg_replace('/\.twig$/', '', $event->template);

                if ($template !== '_components/fieldtypes/Assets/settings') {
                    return;
                }

                $field = $event->variables['field'] ?? null;

                if (!($field instanceof AssetsField) || $field instanceof PolymediaField) {
                    return;
                }

                $providerTypes = $this->getUrlDetector()->getProviderTypes();
                $options = [];

                foreach ($providerTypes as $type) {
                    $options[] = ['label' => ucfirst($type), 'value' => $type];
                }

                $current = $field->uid
                    ? $this->getAssetFieldSettings()->getAllowedProviders($field->uid)
                    : [];

                $polymediaKindEnabled = !$field->restrictFiles
                    || in_array('polymedia', $field->allowedKinds ?? [], true);

                $providersFieldHtml = Cp::checkboxGroupFieldHtml([
                    'label' => Craft::t('polymedia', 'Allowed Providers'),
                    'instructions' => Craft::t('polymedia', 'Restrict which media providers can be selected for `.pmedia` items. Leave all unchecked to allow any provider.'),
                    'id' => 'polymediaAllowedProviders',
                    'name' => 'polymediaAllowedProviders',
                    'values' => $current,
                    'options' => $options,
                ]);

                $hiddenClass = $polymediaKindEnabled ? '' : ' hidden';
                $providersHtml = '<div class="polymedia-allowed-providers-wrapper' . $hiddenClass . '">'
                    . $providersFieldHtml
                    . '</div>';

                $anchor = 'data-error-key="allowedKinds">';
                $pos = strpos($event->output, $anchor);

                if ($pos !== false) {
                    $closePos = strpos($event->output, '</fieldset>', $pos);

                    if ($closePos !== false) {
                        $insertPos = $closePos + strlen('</fieldset>');
                        $event->output = substr($event->output, 0, $insertPos) . $providersHtml . substr($event->output, $insertPos);
                    } else {
                        $event->output .= $providersHtml;
                    }
                } else {
                    $event->output .= $providersHtml;
                }

                Craft::$app->getView()->registerJs(<<<'JS'
(function() {
    var wrappers = document.querySelectorAll('.polymedia-allowed-providers-wrapper:not([data-polymedia-bound])');

    wrappers.forEach(function(wrapper) {
        wrapper.setAttribute('data-polymedia-bound', '1');

        var form = wrapper.closest('form') || document;
        var restrictInput = form.querySelector('input[name$="[restrictFiles]"]:not([type=hidden]), input[name="restrictFiles"]:not([type=hidden])');

        if (!restrictInput) {
            restrictInput = form.querySelector('input[name$="[restrictFiles]"], input[name="restrictFiles"]');
        }

        var polymediaKind = form.querySelector('input[type=checkbox][value="polymedia"][name$="[allowedKinds][]"], input[type=checkbox][value="polymedia"][name="allowedKinds[]"]');

        function isOn(el) {
            if (!el) return false;
            if (el.type === 'checkbox') return el.checked;
            return el.value === '1' || el.value === 'true';
        }

        function update() {
            var restrictFiles = isOn(restrictInput);
            var polymediaAllowed = !restrictFiles || (polymediaKind && polymediaKind.checked);
            wrapper.classList.toggle('hidden', !polymediaAllowed);
        }

        if (restrictInput) {
            restrictInput.addEventListener('change', update);
        }

        if (polymediaKind) {
            polymediaKind.addEventListener('change', update);
        }

        update();
    });
})();
JS);
            },
        );
    }

    /**
     * Persists / cleans up `allowedProviders` on plain Assets fields.
     */
    private function _registerAssetFieldPersistence(): void
    {
        Event::on(
            Fields::class,
            Fields::EVENT_AFTER_SAVE_FIELD,
            function(FieldEvent $event) {
                $field = $event->field;

                if (!($field instanceof AssetsField) || $field instanceof PolymediaField) {
                    return;
                }

                $controller = Craft::$app->controller;

                if (!($controller instanceof \craft\controllers\FieldsController)) {
                    return;
                }

                $polymediaKindEnabled = !$field->restrictFiles
                    || in_array('polymedia', $field->allowedKinds ?? [], true);

                if (!$polymediaKindEnabled) {
                    $this->getAssetFieldSettings()->delete($field->uid);
                    return;
                }

                $providers = $field->polymediaAllowedProviders ?? [];

                if (!is_array($providers)) {
                    $providers = [];
                }

                $this->getAssetFieldSettings()->setAllowedProviders($field->uid, $providers);
            },
        );

        Event::on(
            Fields::class,
            Fields::EVENT_AFTER_DELETE_FIELD,
            function(FieldEvent $event) {
                $field = $event->field;

                if (!($field instanceof AssetsField) || $field instanceof PolymediaField) {
                    return;
                }

                $this->getAssetFieldSettings()->delete($field->uid);
            },
        );
    }

    /**
     * Validates polymedia provider restrictions on plain Assets fields after
     * each element validation pass.
     *
     * {@see PolymediaField} validates itself via `getElementValidationRules()`
     * so it is skipped here.
     */
    private function _registerAssetFieldValidation(): void
    {
        Event::on(
            Element::class,
            Element::EVENT_AFTER_VALIDATE,
            function(Event $event) {
                /** @var Element $element */
                $element = $event->sender;
                $fieldLayout = $element->getFieldLayout();

                if (!$fieldLayout) {
                    return;
                }

                foreach ($fieldLayout->getCustomFields() as $field) {
                    if (!($field instanceof AssetsField) || $field instanceof PolymediaField) {
                        continue;
                    }

                    $providers = $this->getAssetFieldSettings()->getAllowedProviders($field->uid);

                    if (empty($providers)) {
                        continue;
                    }

                    $this->getProviderFilter()->validate($element, $field->handle, $providers);
                }
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
