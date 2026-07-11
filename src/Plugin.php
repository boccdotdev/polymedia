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
use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\models\DetectionResult;
use boccdotdev\polymedia\models\PlayerSettings;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\AssetFieldSettings;
use boccdotdev\polymedia\services\EditorContent;
use boccdotdev\polymedia\services\ManifestWriter;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\Mux;
use boccdotdev\polymedia\services\PosterFetcher;
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
use craft\events\DefineAssetThumbUrlEvent;
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
use craft\services\Assets as AssetsService;
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
 * @property-read EditorContent $editorContent
 * @property-read Mux $mux
 * @property-read PosterFetcher $posterFetcher
 *
 * @author boccdotdev
 * @since 1.0.0
 */
class Plugin extends BasePlugin
{
    // Const Properties
    // =========================================================================

    /**
     * Free edition: URL media (including Mux paste), field, player.
     */
    public const EDITION_LITE = 'lite';

    /**
     * Commercial edition: Lite + Mux credentials, library browse, direct upload.
     */
    public const EDITION_PRO = 'pro';

    // Public Properties
    // =========================================================================

    /**
     * @var string
     */
    public string $schemaVersion = '1.2.0';

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
    public static function editions(): array
    {
        return [
            self::EDITION_LITE,
            self::EDITION_PRO,
        ];
    }

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
            'editorContent' => EditorContent::class,
            'mux' => Mux::class,
            'posterFetcher' => PosterFetcher::class,
        ]);

        $this->_registerFileKind();
        $this->_registerAssetDeleteHandler();
        $this->_registerAssetIndexAttributes();
        $this->_registerAssetThumbUrl();
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
     * Returns whether the active edition is Pro (or higher).
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getIsPro(): bool
    {
        return $this->is(self::EDITION_PRO, '>=');
    }

    /**
     * Returns whether Mux library/upload UI and controllers may run:
     * Pro edition with both credentials configured.
     *
     * @return bool
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getMuxEnabled(): bool
    {
        return $this->getIsPro() && $this->getMux()->isConfigured();
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

    /**
     * Returns the editor content service.
     *
     * @return EditorContent
     *
     * @author boccdotdev
     * @since 1.3.0
     */
    public function getEditorContent(): EditorContent
    {
        return $this->get('editorContent');
    }

    /**
     * Returns the Mux API service.
     *
     * @return Mux
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getMux(): Mux
    {
        return $this->get('mux');
    }

    /**
     * Returns the poster fetcher service.
     *
     * @return PosterFetcher
     *
     * @author boccdotdev
     * @since 2.0.0
     */
    public function getPosterFetcher(): PosterFetcher
    {
        return $this->get('posterFetcher');
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
    public function getSettings(): Settings
    {
        /** @var Settings $settings */
        $settings = parent::getSettings();

        return $settings;
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
            'isPro' => $this->getIsPro(),
            'muxConfigured' => $this->getMux()->isConfigured(),
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
     *
     * Soft delete leaves records intact for trash restore. Hard delete removes
     * the item record and dedicated folder, and optionally deletes the remote
     * Mux asset when {@see Settings::$deleteMuxAssetOnDelete} is enabled.
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

                // Soft delete leaves MediaItemRecord + related assets intact so
                // restore from trash keeps poster/tracks. Hard delete cleans up.
                if (!$asset->hardDelete) {
                    return;
                }

                $record = $this->getMediaItems()->getByAssetId((int)$asset->id);
                $this->_maybeDeleteMuxAsset($record);
                $this->getMediaItems()->deleteByAssetId((int)$asset->id);
                $this->_deleteItemFolderIfDedicated($asset);
            },
        );
    }

    /**
     * Deletes the remote Mux asset when the setting is on and metadata has an id.
     *
     * Failures are logged and do not block local Craft cleanup.
     *
     * @param ?MediaItemRecord $record
     */
    private function _maybeDeleteMuxAsset(?MediaItemRecord $record): void
    {
        if (!$record || $record->type !== 'mux') {
            return;
        }

        $settings = $this->getSettings();

        if (!$settings->deleteMuxAssetOnDelete) {
            return;
        }

        // Feature is Pro-only; skip silently on Lite even if metadata exists.
        if (!$this->getIsPro() || !$this->getMux()->isConfigured()) {
            return;
        }

        $metadata = $this->getMediaItems()->getMetadata($record);
        $muxAssetId = isset($metadata['muxAssetId']) ? (string)$metadata['muxAssetId'] : '';

        if ($muxAssetId === '') {
            Craft::warning(
                "Polymedia: deleteMuxAssetOnDelete is on but asset #{$record->assetId} has no muxAssetId in metadata.",
                __METHOD__,
            );

            return;
        }

        try {
            $this->getMux()->deleteAsset($muxAssetId);
            Craft::info(
                "Polymedia: deleted Mux asset {$muxAssetId} after hard-delete of Craft asset #{$record->assetId}.",
                __METHOD__,
            );
        } catch (\Throwable $e) {
            Craft::error(
                "Polymedia: failed to delete Mux asset {$muxAssetId}: {$e->getMessage()}",
                __METHOD__,
            );
        }
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
                    'polymediaDuration' => $this->getEditorContent()->formatDuration($record->duration),
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

            if (isset($data['metadata']) && is_array($data['metadata'])) {
                $record->metadata = Json::encode($data['metadata']);
            }

            $this->getMediaItems()->save($record);

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
     * @param MediaItemRecord $record the media item record
     * @param mixed $posterIds the submitted poster value
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function savePoster(MediaItemRecord $record, mixed $posterIds): void
    {
        $this->getRelatedAssets()->savePoster($record, $posterIds);
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
        if (!$this->getEditorContent()->supportsTracks($record)) {
            return;
        }

        $request = Craft::$app->getRequest();

        foreach (array_keys(EditorContent::TRACK_ROLES) as $role) {
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
     * @param MediaItemRecord $record the media item record
     * @param string $role one of `captions`, `subtitles`, `descriptions`
     * @param mixed $assetIds the submitted asset IDs (array or empty to clear)
     *
     * @author boccdotdev
     * @since 1.2.0
     */
    public function saveTracks(MediaItemRecord $record, string $role, mixed $assetIds): void
    {
        $this->getRelatedAssets()->saveTracks($record, $role, $assetIds);
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

                $fields = $this->getEditorContent()->renderMediaFields($record, $event->static, $event->element);
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
     * Builds the poster image picker config.
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
        return $this->getEditorContent()->getPosterFieldConfig($folder, $elements, $static);
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
     * @since 1.2.0
     */
    public function getTrackFieldConfig(string $role, ?VolumeFolder $folder, array $elements = [], bool $static = false): array
    {
        return $this->getEditorContent()->getTrackFieldConfig($role, $folder, $elements, $static);
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
     * Registers the CP asset bundle on CP requests and passes Mux feature flags.
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
                $view = Craft::$app->getView();
                $view->registerAssetBundle(PolymediaAsset::class);
                $view->registerTranslations('polymedia', [
                    'Add media URL',
                    'Media item created.',
                    'Browse Mux library',
                    'Upload to Mux',
                    'Import',
                    'In Craft',
                    'Loading Mux library…',
                    'No Mux assets found.',
                    'Could not load Mux library.',
                    'Mux media imported.',
                    'Already in Craft — using existing media item.',
                    'Previous',
                    'Next',
                    'Close',
                    'Signed',
                    'Processing',
                    'Ready',
                    'Errored',
                    'Title',
                    'Video file',
                    'Start upload',
                    'Uploading…',
                    'Processing on Mux…',
                    'Creating media item…',
                    'Mux upload complete.',
                    'Upload failed.',
                    'Choose a video file.',
                    'Poster will be generated from the first frame when ready.',
                    'Cancel',
                ]);
                $view->registerJs(
                    'window.CraftPolymediaConfig = ' . Json::encode([
                        'muxEnabled' => $this->getMuxEnabled(),
                        'isPro' => $this->getIsPro(),
                    ]) . ';',
                    View::POS_HEAD,
                );
            },
        );
    }

    /**
     * Serves related poster (or remote thumbnail) as the CP thumb for `.pmedia` assets.
     *
     * Folder listing still uses Craft’s folder icon; posters are co-located in the
     * dedicated item folder so volume browsers show a real image among contents.
     */
    private function _registerAssetThumbUrl(): void
    {
        Event::on(
            AssetsService::class,
            AssetsService::EVENT_DEFINE_THUMB_URL,
            function(DefineAssetThumbUrlEvent $event) {
                /** @var Asset $asset */
                $asset = $event->asset;

                if ($asset->kind !== 'polymedia') {
                    return;
                }

                $record = $this->getMediaItems()->getByAssetId((int)$asset->id);

                if (!$record) {
                    return;
                }

                $poster = $this->getRelatedAssets()->getPoster((int)$record->id);

                if ($poster) {
                    $url = Craft::$app->getAssets()->getThumbUrl(
                        $poster,
                        $event->width,
                        $event->height,
                        false,
                    );

                    if ($url) {
                        $event->url = $url;

                        return;
                    }
                }

                // Fall back to remote thumbnail in metadata / deriver until local poster exists.
                $remote = $this->getPosterFetcher()->deriveRemoteUrl($record);

                if ($remote) {
                    $event->url = $remote;
                }
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
}
