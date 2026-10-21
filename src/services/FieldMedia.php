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

use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\models\Settings;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\models\VolumeFolder;
use yii\base\Component;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Authorizes an Assets field as the destination for new or reused `.pmedia`
 * assets.
 *
 * Backs the field's Add video or audio menu and uploads bound to a field: the
 * field and the element that owns it, the field's upload location, its
 * allowed providers and its selection rules. The CP only hides what these
 * checks refuse; it never authorizes anything.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class FieldMedia extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var string Menu item: create from a pasted URL.
     * @since 2.5.0
     */
    public const ITEM_URL = 'url';

    /**
     * @var string Menu item: reference a video asset already in Craft.
     * @since 2.5.0
     */
    public const ITEM_EXISTING = 'existing';

    /**
     * @var string Menu item: import from the hosting provider's library.
     * @since 2.5.0
     */
    public const ITEM_LIBRARY = 'library';

    /**
     * @var string Menu item: upload to the hosting provider.
     * @since 2.5.0
     */
    public const ITEM_UPLOAD = 'upload';

    // Public Methods
    // =========================================================================

    /**
     * Returns whether a field explicitly allows the Polymedia file kind.
     *
     * Unrestricted fields accept `.pmedia` assets too, but don't offer the
     * Add video or audio menu.
     *
     * @param AssetsField $field the field
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function offersMedia(AssetsField $field): bool
    {
        return $field->restrictFiles && in_array('polymedia', $field->allowedKinds ?? [], true);
    }

    /**
     * Returns the UID of the volume new uploads to a field go to.
     *
     * Mirrors how Craft resolves the upload volume when rendering a field:
     * from the location source only. Resolving the dynamic subpath here would
     * create folders on every render.
     *
     * @param AssetsField $field the field
     * @return ?string
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function uploadVolumeUid(AssetsField $field): ?string
    {
        $sourceKey = $field->restrictLocation ? $field->restrictedLocationSource : $field->defaultUploadLocationSource;
        $parts = is_string($sourceKey) ? explode(':', $sourceKey, 2) : [];

        if (count($parts) !== 2 || $parts[0] !== 'volume') {
            return null;
        }

        return Craft::$app->getVolumes()->getVolumeByUid($parts[1])?->uid;
    }

    /**
     * Returns the provider keys a field allows. Empty means all.
     *
     * @param AssetsField $field the field
     * @return string[]
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getAllowedProviders(AssetsField $field): array
    {
        return $field instanceof PolymediaField
            ? $field->allowedProviders
            : Plugin::getInstance()->getAssetFieldSettings()->getAllowedProviders((string)$field->uid);
    }

    /**
     * Returns the Add video or audio items a field offers, in menu order.
     *
     * @param AssetsField $field the field
     * @return string[] `ITEM_*` keys
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getMenuItems(AssetsField $field): array
    {
        $plugin = Plugin::getInstance();
        $allowed = $this->getAllowedProviders($field);
        $allows = static fn(string $type): bool => $allowed === [] || in_array($type, $allowed, true);
        $items = [];

        // Bunny videos only come from the library, never from a pasted URL.
        if ($allowed === [] || array_diff($allowed, [Settings::PROVIDER_BUNNY]) !== []) {
            $items[] = self::ITEM_URL;
        }

        if ($allows('mp4')) {
            $items[] = self::ITEM_EXISTING;
        }

        if ($plugin->getVideoEnabled() && $allows($plugin->getSettings()->videoProvider)) {
            $items[] = self::ITEM_LIBRARY;
            $items[] = self::ITEM_UPLOAD;
        }

        return $items;
    }

    /**
     * Returns the menu a field's input renders with, or null when the field
     * doesn't offer one.
     *
     * `canAdd` is false when the current user can't save assets to the
     * field's upload volume. It only hides the button.
     *
     * @param AssetsField $field the field
     * @return ?array{items: string[], canAdd: bool}
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getMenu(AssetsField $field): ?array
    {
        if (!self::offersMedia($field)) {
            return null;
        }

        $user = Craft::$app->getUser()->getIdentity();
        $volumeUid = self::uploadVolumeUid($field);
        $items = $this->getMenuItems($field);

        return [
            'items' => $items,
            'canAdd' => $items !== []
                && $user !== null
                && $volumeUid !== null
                && $volumeUid !== Plugin::getInstance()->getSettings()->sidecarVolumeUid
                && $user->can("saveAssets:$volumeUid"),
        ];
    }

    /**
     * Resolves a field and, when given, the element that owns it.
     *
     * @param array $input `fieldId`, and optionally `elementId` and `siteId`
     * @return array{0: AssetsField, 1: ?ElementInterface}
     * @throws BadRequestHttpException when the field isn't an Assets field
     * @throws ForbiddenHttpException when the user can't save the element or the field isn't in its layout
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function resolveField(array $input): array
    {
        $fieldId = (int)($input['fieldId'] ?? 0);
        $field = $fieldId > 0 ? Craft::$app->getFields()->getFieldById($fieldId) : null;

        if (!$field instanceof AssetsField) {
            throw new BadRequestHttpException('The upload field is not an Assets field.');
        }

        if (empty($input['elementId'])) {
            return [$field, null];
        }

        $user = Craft::$app->getUser()->getIdentity();
        $owner = Craft::$app->getElements()->getElementById((int)$input['elementId'], null, (int)($input['siteId'] ?? 0) ?: null);

        if (!$owner || !$user || !$owner->canSave($user)) {
            throw new ForbiddenHttpException('You cannot upload into this element.');
        }

        if (!$owner->getFieldLayout()?->getFieldById($fieldId)) {
            throw new ForbiddenHttpException('The upload field does not belong to this element.');
        }

        return [$field, $owner];
    }

    /**
     * Returns the folder a field's new `.pmedia` assets go to.
     *
     * Resolves the field's upload location for the element the way Craft's
     * own field uploads do, so a dynamic subpath may create its folder.
     *
     * @param array $input `fieldId`, `elementId`, `siteId`
     * @return VolumeFolder
     * @throws BadRequestHttpException when the field isn't an Assets field
     * @throws ForbiddenHttpException when the field doesn't offer media or the user can't add to it
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function getDestination(array $input): VolumeFolder
    {
        [$field, $owner] = $this->resolveField($input);

        if (!self::offersMedia($field)) {
            throw new ForbiddenHttpException('This field does not accept video or audio.');
        }

        $user = Craft::$app->getUser()->getIdentity();

        try {
            $folder = Craft::$app->getAssets()->getFolderById($field->resolveDynamicPathToFolderId($owner));
        } catch (\craft\errors\InvalidFsException | \craft\errors\InvalidSubpathException) {
            $folder = null;
        }

        // A folder without a volume is the user's temporary upload folder.
        if (!$folder || !$folder->volumeId || !$user || !$user->can('saveAssets:' . $folder->getVolume()->uid)) {
            throw new ForbiddenHttpException('You cannot add media to this field’s upload location.');
        }

        if ($folder->getVolume()->uid === Plugin::getInstance()->getSettings()->sidecarVolumeUid) {
            throw new ForbiddenHttpException('The sidecar volume cannot contain media manifests.');
        }

        return $folder;
    }

    /**
     * Requires a folder to be within a field's upload location or sources.
     *
     * @param AssetsField $field the field
     * @param ?ElementInterface $owner the element that owns the field
     * @param VolumeFolder $folder the folder
     * @throws ForbiddenHttpException when the folder is outside the field's reach
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function checkLocation(AssetsField $field, ?ElementInterface $owner, VolumeFolder $folder): void
    {
        if ($field->restrictLocation) {
            $rootId = (int)$field->resolveDynamicPathToFolderId($owner);
            $candidate = $folder;

            while ($candidate && (int)$candidate->id !== $rootId && $field->allowSubfolders && $candidate->parentId) {
                $candidate = Craft::$app->getAssets()->getFolderById((int)$candidate->parentId);
            }

            if (!$candidate || (int)$candidate->id !== $rootId) {
                throw new ForbiddenHttpException('The destination is outside this field’s upload location.');
            }

            return;
        }

        if (is_array($field->sources) && !in_array('volume:' . $folder->getVolume()->uid, $field->sources, true)) {
            throw new ForbiddenHttpException('The destination volume is not available to this field.');
        }
    }

    /**
     * Returns whether an asset can be selected into a field on an element.
     *
     * Checks what the field enforces for a new or reused `.pmedia`: the
     * user can view it, and its kind, location, provider and the field's
     * selection condition. Capacity and duplicates are the input's to check.
     *
     * @param array $input `fieldId`, `elementId`, `siteId`
     * @param Asset $asset the asset
     * @return bool
     * @throws BadRequestHttpException when the field isn't an Assets field
     * @throws ForbiddenHttpException when the user can't save the element or the field isn't in its layout
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function isSelectable(array $input, Asset $asset): bool
    {
        [$field, $owner] = $this->resolveField($input);
        $user = Craft::$app->getUser()->getIdentity();

        if (!$user || !$asset->canView($user)) {
            return false;
        }

        if ($field->restrictFiles && !in_array($asset->kind, $field->allowedKinds ?? [], true)) {
            return false;
        }

        $folder = $asset->getFolder();

        try {
            $this->checkLocation($field, $owner, $folder);
        } catch (ForbiddenHttpException) {
            return false;
        }

        $allowed = $this->getAllowedProviders($field);

        if ($allowed !== [] && $asset->kind === 'polymedia') {
            $record = Plugin::getInstance()->getMediaItems()->getByAssetId((int)$asset->id);

            if (!$record || !in_array($record->type, $allowed, true)) {
                return false;
            }
        }

        $condition = $field->getSelectionCondition();

        if ($condition) {
            $condition->referenceElement = $owner;

            if (!$condition->matchElement($asset)) {
                return false;
            }
        }

        return true;
    }
}
