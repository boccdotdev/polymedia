<?php

namespace boccdotdev\polymedia\controllers;

use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\services\Bunny;
use Craft;
use craft\web\Controller;
use yii\web\Response;

class BunnyWebhooksController extends Controller
{
    protected array|bool|int $allowAnonymous = ['index'];
    public $enableCsrfValidation = false;

    public function actionIndex(): Response
    {
        $this->requirePostRequest();
        $plugin = Plugin::getInstance();
        $request = Craft::$app->getRequest();
        $bunny = $plugin->getBunny();
        $body = $request->getRawBody();
        $headers = $request->getHeaders();
        if (!$plugin->isBunnyEnabled() || !Bunny::verifyWebhookSignature(
            $body,
            (string)$headers->get('X-BunnyStream-Signature', ''),
            (string)$headers->get('X-BunnyStream-Signature-Version', ''),
            (string)$headers->get('X-BunnyStream-Signature-Algorithm', ''),
            $bunny->getWebhookKey(),
        )) {
            return $this->reply(401, ['error' => 'Invalid Bunny webhook signature or disabled integration.']);
        }
        try {
            $event = json_decode($body, true, 32, JSON_THROW_ON_ERROR);
            $library = Bunny::validateLibraryId((string)($event['VideoLibraryId'] ?? ''));
            $video = Bunny::validateVideoId((string)($event['VideoGuid'] ?? ''));
        } catch (\Throwable) {
            return $this->reply(400, ['error' => 'Invalid Bunny webhook payload.']);
        }
        if ($library !== $bunny->getLibraryId()) {
            return $this->reply(200, ['ignored' => true]);
        }
        try {
            // No enumeration or import, and no trust in the event's status.
            $record = $plugin->getBunnySync()->syncVideo($library, $video);
            return $this->reply(200, ['ignored' => $record === null]);
        } catch (\Throwable $e) {
            Craft::warning('Bunny webhook synchronization failed: ' . $e->getMessage(), __METHOD__);
            return $this->reply(503, ['error' => 'Synchronization failed. Retry delivery.']);
        }
    }

    private function reply(int $status, array $data): Response
    {
        $response = $this->asJson($data);
        $response->setStatusCode($status);
        return $response;
    }
}
