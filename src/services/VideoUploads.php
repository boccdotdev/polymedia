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
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\Assets as AssetsField;
use craft\helpers\Json;
use craft\models\VolumeFolder;
use yii\base\Component;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Authorizes hosted video uploads before any bytes leave the browser.
 *
 * Preflight checks the destination folder, field and owner element, then
 * returns a signed upload context bound to the user, provider and folder.
 * Creating the remote upload and completing it both require that context,
 * and re-check permissions. The context holds no provider credentials.
 *
 * Covers the explicit Upload video action and, with routing on, new MP4s
 * from Craft's standard uploader. A routed MP4 bound for a volume that isn't
 * selected for routing gets an explicit `route: false` and uploads natively;
 * any failure is an error, never a native fallback.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class VideoUploads extends Component
{
    // Const Properties
    // =========================================================================

    /**
     * @var int Seconds an upload context stays valid.
     */
    private const LIFETIME = 21600;

    /**
     * @var int Upload context format version.
     */
    private const VERSION = 1;

    // Public Methods
    // =========================================================================

    /**
     * Authorizes an upload to the selected provider and returns its context.
     *
     * @param array $input request input: `folderId`, `fieldId`, `elementId`,
     *                     `siteId`, and for native routing `routing` and `filename`
     * @return array{provider: string, folderId: int, route: bool, token?: string}
     *         `route` is false only for a routed upload to a volume that
     *         isn't selected for routing, which then uploads natively
     * @throws ForbiddenHttpException when the provider, destination or field refuses the upload
     * @throws BadRequestHttpException when the field isn't an Assets field
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function preflight(array $input): array
    {
        $settings = Plugin::getInstance()->getSettings();
        $provider = $settings->videoProvider;
        $this->_requireProvider($provider);
        $routing = filter_var($input['routing'] ?? false, FILTER_VALIDATE_BOOL);

        if ($routing && (!$settings->autoRouteVideoUploads || !self::isMp4((string)($input['filename'] ?? '')))) {
            throw new ForbiddenHttpException('Automatic video uploads are not enabled for this request.');
        }

        [$folder, $field, $owner] = $this->_destination($input);
        $this->_checkFieldLocation($field, $owner, $folder, $routing);

        // A deliberate native destination, not recovery from a failure.
        if ($routing && !in_array($folder->getVolume()->uid, $settings->videoUploadVolumeUids, true)) {
            return ['provider' => $provider, 'folderId' => (int)$folder->id, 'route' => false];
        }

        $this->_checkField($field, $owner, $folder, $provider, $routing);

        $context = [
            'version' => self::VERSION,
            'userId' => (int)Craft::$app->getUser()->getId(),
            'provider' => $provider,
            'folderId' => (int)$folder->id,
            'fieldId' => $field?->id,
            'elementId' => $owner?->id,
            'siteId' => $owner->siteId ?? ((int)($input['siteId'] ?? 0) ?: null),
            'routing' => $routing,
            'expires' => time() + self::LIFETIME,
            'nonce' => bin2hex(random_bytes(16)),
        ];

        return [
            'provider' => $provider,
            'folderId' => (int)$folder->id,
            'route' => true,
            'token' => base64_encode(Craft::$app->getSecurity()->hashData(Json::encode($context))),
        ];
    }

    /**
     * Creates the remote upload for the request's upload context, once.
     *
     * Retrying with the same context returns the same remote upload, so a
     * double submit never creates two provider videos.
     *
     * @param string $provider `mux` or `bunny`
     * @param callable(): array $create creates the provider upload; must return `uploadId`
     * @return array the provider upload plus the authorized `folderId`
     * @throws ForbiddenHttpException when the upload context is missing, expired or no longer authorized
     * @throws BadRequestHttpException when the context's field isn't an Assets field
     * @throws \RuntimeException when the upload is busy or can't be retained
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function createUpload(string $provider, callable $create): array
    {
        $context = $this->_requestContext($provider);
        $key = $this->_uploadKey($context);
        $mutex = Craft::$app->getMutex();

        if (!$mutex->acquire($key, 10)) {
            throw new \RuntimeException('This upload is already being prepared. Please retry.');
        }

        try {
            $upload = Craft::$app->getCache()->get($key);

            if (!is_array($upload)) {
                $upload = $create();

                if (empty($upload['uploadId'])) {
                    throw new \RuntimeException('The video provider did not return an upload ID.');
                }

                if (!Craft::$app->getCache()->set($key, $upload, max(1, $context['expires'] - time()))) {
                    throw new \RuntimeException('Could not keep this upload session. The video may already be in the video library.');
                }
            }

            return $upload + ['folderId' => $context['folderId']];
        } finally {
            $mutex->release($key);
        }
    }

    /**
     * Re-checks the request's upload context and proves it owns the upload.
     *
     * Used for both status polling and completion; it doesn't consume the
     * context.
     *
     * @param string $provider `mux` or `bunny`
     * @param string $uploadId the provider upload ID the browser reports
     * @return array the verified upload context
     * @throws ForbiddenHttpException when the context is invalid or the upload isn't its own
     * @throws BadRequestHttpException when the context's field isn't an Assets field
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function completeUpload(string $provider, string $uploadId): array
    {
        $context = $this->_requestContext($provider);
        $upload = Craft::$app->getCache()->get($this->_uploadKey($context));

        if ($uploadId === '' || !is_array($upload) || ($upload['uploadId'] ?? null) !== $uploadId) {
            throw new ForbiddenHttpException('This upload session is unavailable. Import the finished video from the video library instead.');
        }

        return $context;
    }

    /**
     * Requires a field upload's finished `.pmedia` to be selectable in the field.
     *
     * The field's selection condition can change while a long upload runs.
     *
     * @param array $context the verified upload context
     * @param Asset $asset the finished `.pmedia`
     * @throws ForbiddenHttpException when the field's condition rejects the asset
     * @throws BadRequestHttpException when the context's field isn't an Assets field
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public function assertSelectable(array $context, Asset $asset): void
    {
        if (empty($context['fieldId'])) {
            return;
        }

        [, $field, $owner] = $this->_destination($context);
        $condition = $field?->getSelectionCondition();

        if (!$condition) {
            return;
        }

        $condition->referenceElement = $owner;

        if (!$condition->matchElement($asset)) {
            throw new ForbiddenHttpException('The video was uploaded, but its media asset can’t be selected in this field. It stays in the asset library.');
        }
    }

    /**
     * Returns whether a file name is an MP4.
     *
     * @param string $filename the file name
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function isMp4(string $filename): bool
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4';
    }

    /**
     * Validates a decoded upload context. Pure function, exposed for tests.
     *
     * @param array $context the decoded context
     * @param int $userId the current user ID
     * @param string $provider the provider being used
     * @param int $now the current Unix time
     * @return bool
     *
     * @author boccdotdev
     * @since 2.5.0
     */
    public static function contextMatches(array $context, int $userId, string $provider, int $now): bool
    {
        return ($context['version'] ?? null) === self::VERSION
            && $userId > 0
            && ($context['userId'] ?? null) === $userId
            && ($context['provider'] ?? null) === $provider
            && is_int($context['expires'] ?? null)
            && $context['expires'] > $now
            && is_int($context['folderId'] ?? null)
            && $context['folderId'] > 0
            && is_string($context['nonce'] ?? null)
            && preg_match('/^[a-f0-9]{32}$/D', $context['nonce']) === 1;
    }

    // Private Methods
    // =========================================================================

    /**
     * Decodes and re-authorizes the request's `uploadContext`.
     *
     * @param string $provider the provider being used
     * @return array the context
     * @throws ForbiddenHttpException when the context is invalid or no longer authorized
     * @throws BadRequestHttpException when the context's field isn't an Assets field
     */
    private function _requestContext(string $provider): array
    {
        $this->_requireProvider($provider);

        $request = Craft::$app->getRequest();
        $signed = base64_decode((string)$request->getBodyParam('uploadContext', ''), true);
        $json = $signed !== false ? Craft::$app->getSecurity()->validateData($signed) : false;

        try {
            $context = $json !== false ? Json::decode($json) : null;
        } catch (\Throwable) {
            $context = null;
        }

        if (!is_array($context) || !self::contextMatches($context, (int)Craft::$app->getUser()->getId(), $provider, time())) {
            throw new ForbiddenHttpException('The video upload authorization has expired or is invalid. Please start again.');
        }

        $postedFolder = (int)$request->getBodyParam('folderId', 0);

        if ($postedFolder !== 0 && $postedFolder !== $context['folderId']) {
            throw new ForbiddenHttpException('The video upload destination cannot be changed.');
        }

        $settings = Plugin::getInstance()->getSettings();
        [$folder, $field, $owner] = $this->_destination($context);
        $routing = !empty($context['routing']);

        if ($routing && (!$settings->autoRouteVideoUploads || !in_array($folder->getVolume()->uid, $settings->videoUploadVolumeUids, true))) {
            throw new ForbiddenHttpException('Automatic video uploads are no longer enabled for this volume.');
        }

        $this->_checkField($field, $owner, $folder, $provider, $routing);

        return $context;
    }

    /**
     * Requires a signed-in user and an enabled, selected provider.
     *
     * @param string $provider the provider
     * @throws ForbiddenHttpException when uploads to the provider aren't allowed
     */
    private function _requireProvider(string $provider): void
    {
        if (!Craft::$app->getUser()->getIdentity() || !Plugin::getInstance()->isVideoProviderEnabled($provider)) {
            throw new ForbiddenHttpException('The selected video provider requires Polymedia Pro and configured credentials.');
        }
    }

    /**
     * Resolves the destination folder, field and owner element.
     *
     * An explicit folder that's invalid or not writable is refused, never
     * swapped for another volume.
     *
     * @param array $input `folderId`, `fieldId`, `elementId`, `siteId`, `routing`
     * @return array{0: VolumeFolder, 1: ?AssetsField, 2: ?ElementInterface}
     * @throws ForbiddenHttpException when the user can't upload there
     * @throws BadRequestHttpException when the field isn't an Assets field
     */
    private function _destination(array $input): array
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();
        $field = null;
        $owner = null;
        $folderId = (int)($input['folderId'] ?? 0);
        $fieldId = (int)($input['fieldId'] ?? 0);

        if ($fieldId > 0) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);

            if (!$field instanceof AssetsField) {
                throw new BadRequestHttpException('The upload field is not an Assets field.');
            }

            if (!empty($input['elementId'])) {
                $owner = Craft::$app->getElements()->getElementById((int)$input['elementId'], null, (int)($input['siteId'] ?? 0) ?: null);

                if (!$owner || !$user || !$owner->canSave($user)) {
                    throw new ForbiddenHttpException('You cannot upload into this element.');
                }

                if (!$owner->getFieldLayout()?->getFieldById($fieldId)) {
                    throw new ForbiddenHttpException('The upload field does not belong to this element.');
                }
            }

            if (!$folderId) {
                $folderId = (int)$field->resolveDynamicPathToFolderId($owner);
            }
        }

        if ($folderId > 0) {
            $folder = Craft::$app->getAssets()->getFolderById($folderId);
        } elseif ($fieldId === 0 && empty($input['routing'])) {
            $folder = $plugin->getManifestWriter()->resolveFolder(null, $user, $plugin->getSettings());
        } else {
            $folder = null;
        }

        if (!$folder || !$user || !$user->can('saveAssets:' . $folder->getVolume()->uid)) {
            throw new ForbiddenHttpException('Choose a writable asset folder for the video.');
        }

        if ($folder->getVolume()->uid === $plugin->getSettings()->sidecarVolumeUid) {
            throw new ForbiddenHttpException('The sidecar volume cannot contain media manifests.');
        }

        return [$folder, $field, $owner];
    }

    /**
     * Requires the field to accept a `.pmedia` from this provider in this folder.
     *
     * @param ?AssetsField $field the field, when uploading into one
     * @param ?ElementInterface $owner the element that owns the field
     * @param VolumeFolder $folder the destination folder
     * @param string $provider the provider
     * @param bool $routing whether this is a routed native upload
     * @throws ForbiddenHttpException when the field refuses the upload
     */
    private function _checkField(?AssetsField $field, ?ElementInterface $owner, VolumeFolder $folder, string $provider, bool $routing = false): void
    {
        if (!$field) {
            return;
        }

        $this->_checkFieldLocation($field, $owner, $folder, $routing);

        if ($field->restrictFiles && !in_array('polymedia', $field->allowedKinds ?? [], true)) {
            throw new ForbiddenHttpException('This field must allow the Polymedia file kind before videos can be uploaded into it.');
        }

        $allowed = $field instanceof PolymediaField
            ? $field->allowedProviders
            : Plugin::getInstance()->getAssetFieldSettings()->getAllowedProviders((string)$field->uid);

        if ($allowed !== [] && !in_array($provider, $allowed, true)) {
            throw new ForbiddenHttpException('This field does not allow the configured video provider.');
        }
    }

    /**
     * Requires the folder to be within the field's upload location or sources,
     * and a routed native upload to a field that allows uploads.
     *
     * @param ?AssetsField $field the field, when uploading into one
     * @param ?ElementInterface $owner the element that owns the field
     * @param VolumeFolder $folder the destination folder
     * @param bool $routing whether this is a routed native upload
     * @throws ForbiddenHttpException when the folder is outside the field's reach
     */
    private function _checkFieldLocation(?AssetsField $field, ?ElementInterface $owner, VolumeFolder $folder, bool $routing = false): void
    {
        if (!$field) {
            return;
        }

        // Polymedia fields only offer native upload when routing applies.
        if ($routing && !$field->allowUploads && !$field instanceof PolymediaField) {
            throw new ForbiddenHttpException('Uploads are disabled for this field.');
        }

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
     * Returns the cache and mutex key for a context's remote upload.
     *
     * @param array $context the upload context
     * @return string
     */
    private function _uploadKey(array $context): string
    {
        return 'polymedia:upload:' . $context['nonce'];
    }
}
