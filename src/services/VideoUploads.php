<?php

namespace boccdotdev\polymedia\services;

use boccdotdev\polymedia\fields\PolymediaField;
use boccdotdev\polymedia\Plugin;
use Craft;
use craft\base\ElementInterface;
use craft\elements\Asset;
use craft\fields\Assets;
use craft\helpers\Json;
use craft\models\VolumeFolder;
use yii\base\Component;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;

/**
 * Authorizes a video upload before bytes leave the browser and binds completion
 * to that user, provider and destination. Tokens contain no provider credentials.
 */
class VideoUploads extends Component
{
    private const LIFETIME = 21600;

    public function preflight(array $input): array
    {
        $plugin = Plugin::getInstance();
        $provider = $plugin->getSettings()->videoProvider;
        $this->requireProvider($provider);
        $routing = filter_var($input['routing'] ?? false, FILTER_VALIDATE_BOOL);

        if ($routing && (!$plugin->getSettings()->autoRouteVideoUploads || !self::isMp4((string)($input['filename'] ?? '')))) {
            throw new ForbiddenHttpException('Automatic video uploads are not enabled for this request.');
        }

        [$folder, $field, $owner] = $this->destination($input);
        $this->checkFieldDestination($field, $owner, $folder, $routing);
        $result = ['provider' => $provider, 'folderId' => (int)$folder->id];
        if ($routing && !in_array($folder->getVolume()->uid, $plugin->getSettings()->videoUploadVolumeUids, true)) {
            // A deliberate native destination, not recovery from a failed upload.
            return $result + ['route' => false];
        }

        $this->checkField($field, $owner, $folder, $provider, $routing);
        $context = [
            'version' => 1,
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
        $signed = Craft::$app->getSecurity()->hashData(Json::encode($context));

        return $result + ['route' => true, 'token' => base64_encode($signed)];
    }

    /**
     * Retrying creation with the same context reuses the same remote upload.
     *
     * @param callable():array $create
     */
    public function createUpload(string $provider, callable $create): array
    {
        $context = $this->requestContext($provider);
        $key = $this->uploadKey($context);
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
                    throw new \RuntimeException('Could not retain this upload session. The video may be available in the library.');
                }
            }

            return $upload + ['folderId' => $context['folderId']];
        } finally {
            $mutex->release($key);
        }
    }

    /**
     * Rechecks permissions and configuration, then proves upload ownership.
     */
    public function completeUpload(string $provider, string $uploadId): array
    {
        $context = $this->requestContext($provider);
        $upload = Craft::$app->getCache()->get($this->uploadKey($context));
        if ($uploadId === '' || !is_array($upload) || ($upload['uploadId'] ?? null) !== $uploadId) {
            throw new ForbiddenHttpException('This upload session is unavailable. Import the completed video from the video library instead.');
        }

        return $context;
    }

    /**
     * Native inline fields must also accept the completed element, not merely
     * its prospective kind. A condition can change while an upload is running.
     */
    public function assertSelectable(array $context, Asset $asset): void
    {
        if (empty($context['fieldId'])) {
            return;
        }

        [, $field, $owner] = $this->destination($context);
        $condition = $field?->getSelectionCondition();
        if ($condition) {
            $condition->referenceElement = $owner;
            if (!$condition->matchElement($asset)) {
                throw new ForbiddenHttpException('The video was uploaded, but its media asset is not selectable for this field. It remains in the library.');
            }
        }
    }

    public static function isMp4(string $filename): bool
    {
        return strtolower(pathinfo($filename, PATHINFO_EXTENSION)) === 'mp4';
    }

    /**
     * Pure validation used after authenticating the token.
     */
    public static function contextMatches(array $context, int $userId, string $provider, int $now): bool
    {
        return ($context['version'] ?? null) === 1
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

    private function requestContext(string $provider): array
    {
        $this->requireProvider($provider);
        $request = Craft::$app->getRequest();
        $encoded = (string)$request->getBodyParam('uploadContext', '');
        $signed = base64_decode($encoded, true);
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
        [$folder, $field, $owner] = $this->destination($context);
        if ($context['routing'] && (!$settings->autoRouteVideoUploads || !in_array($folder->getVolume()->uid, $settings->videoUploadVolumeUids, true))) {
            throw new ForbiddenHttpException('Automatic uploads are no longer enabled for this volume.');
        }
        $this->checkField($field, $owner, $folder, $provider, (bool)$context['routing']);

        return $context;
    }

    private function requireProvider(string $provider): void
    {
        if (!Craft::$app->getUser()->getIdentity() || !Plugin::getInstance()->isVideoProviderEnabled($provider)) {
            throw new ForbiddenHttpException('The selected video provider requires Polymedia Pro and configured credentials.');
        }
    }

    /**
     * Resolve native field upload destinations without falling back to some
     * other writable volume when an explicit folder is invalid.
     *
     * @return array{VolumeFolder, ?Assets, ?ElementInterface}
     */
    private function destination(array $input): array
    {
        $plugin = Plugin::getInstance();
        $user = Craft::$app->getUser()->getIdentity();
        $field = null;
        $owner = null;
        $folderId = (int)($input['folderId'] ?? 0);
        $fieldId = (int)($input['fieldId'] ?? 0);

        if ($fieldId > 0) {
            $field = Craft::$app->getFields()->getFieldById($fieldId);
            if (!$field instanceof Assets) {
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

    private function checkField(?Assets $field, ?ElementInterface $owner, VolumeFolder $folder, string $provider, bool $routing): void
    {
        $this->checkFieldDestination($field, $owner, $folder, $routing);
        if (!$field) {
            return;
        }
        if ($field->restrictFiles && !in_array('polymedia', $field->allowedKinds, true)) {
            throw new ForbiddenHttpException('This field must allow the Polymedia file kind before video uploads can be routed.');
        }

        $allowed = $field instanceof PolymediaField
            ? $field->allowedProviders
            : Plugin::getInstance()->getAssetFieldSettings()->getAllowedProviders((string)$field->uid);
        if ($allowed !== [] && !in_array($provider, $allowed, true)) {
            throw new ForbiddenHttpException('This field does not allow the configured video provider.');
        }
    }

    private function checkFieldDestination(?Assets $field, ?ElementInterface $owner, VolumeFolder $folder, bool $routing): void
    {
        if (!$field) {
            return;
        }
        if ($routing && !$field->allowUploads && !$field instanceof PolymediaField) {
            throw new ForbiddenHttpException('Uploads are disabled for this field.');
        }
        if ($field->restrictLocation) {
            $rootId = (int)$field->resolveDynamicPathToFolderId($owner);
            $candidate = $folder;
            while ((int)$candidate->id !== $rootId && $field->allowSubfolders && $candidate->parentId) {
                $candidate = Craft::$app->getAssets()->getFolderById((int)$candidate->parentId);
                if (!$candidate) {
                    break;
                }
            }
            if (!$candidate || (int)$candidate->id !== $rootId) {
                throw new ForbiddenHttpException('The destination is outside this field’s upload location.');
            }
        } elseif (is_array($field->sources) && !in_array('volume:' . $folder->getVolume()->uid, $field->sources, true)) {
            throw new ForbiddenHttpException('The destination volume is not available to this field.');
        }
    }

    private function uploadKey(array $context): string
    {
        return 'polymedia:upload:' . $context['nonce'];
    }
}
