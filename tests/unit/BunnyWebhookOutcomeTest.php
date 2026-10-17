<?php
/**
 * Polymedia plugin for Craft CMS
 *
 * @link      https://github.com/boccdotdev/polymedia
 * @copyright Copyright (c) 2026 boccdotdev
 */

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\WebhooksController;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\Bunny;
use boccdotdev\polymedia\services\BunnySync;
use Craft;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use yii\web\NotFoundHttpException;

/**
 * Bunny Stream webhook verification and delivery outcomes.
 *
 * @author boccdotdev
 * @since 2.5.0
 */
class BunnyWebhookOutcomeTest extends TestCase
{
    // Const Properties
    // =========================================================================

    private const VIDEO = '657bb740-a71b-4529-a012-528021c31a92';

    // Public Methods
    // =========================================================================

    public static function outcomes(): array
    {
        $body = json_encode(['VideoLibraryId' => 133, 'VideoGuid' => self::VIDEO, 'Status' => 3]);

        return [
            'refreshes an imported video' => ['matched', $body, null, 200, true],
            'acknowledges an unknown video' => ['unmatched', $body, null, 200, false],
            'ignores another library' => ['never', json_encode(['VideoLibraryId' => 134, 'VideoGuid' => self::VIDEO]), null, 200, false],
            'retries a transient failure' => ['failure', $body, null, 503, false],
            'rejects a bad signature' => ['never', $body, str_repeat('0', 64), 401, false],
            'rejects a malformed payload' => ['never', '{"VideoGuid":"../x"}', null, 400, false],
        ];
    }

    #[DataProvider('outcomes')]
    public function testDeliveryOutcome(string $outcome, string $body, ?string $signature, int $status, bool $handled): void
    {
        $result = $this->_deliver($body, $signature ?? hash_hmac('sha256', $body, 'read-only-key'), 'read-only-key', $outcome);

        self::assertSame($status, $result->getStatusCode());
        self::assertSame($handled, $result->data['handled']);
        self::assertSame($status === 200, $result->data['ok']);
    }

    public function testEndpointIsDisabledWithoutAKey(): void
    {
        $this->expectException(NotFoundHttpException::class);
        $this->_deliver('{}', '', '', 'never');
    }

    public function testEndpointIsDisabledWhenBunnyIsUnavailable(): void
    {
        // Lite, or Bunny not the configured provider: a valid delivery still 404s.
        $body = '{"VideoLibraryId":133,"VideoGuid":"' . self::VIDEO . '","Status":3}';
        $this->expectException(NotFoundHttpException::class);
        $this->_deliver($body, hash_hmac('sha256', $body, 'read-only-key'), 'read-only-key', 'never', false);
    }

    public function testSignatureUsesTheExactBodyAndStrictHeaders(): void
    {
        $body = '{"VideoLibraryId":133,"VideoGuid":"' . self::VIDEO . '","Status":3}';
        $signature = hash_hmac('sha256', $body, 'read-only-key');

        self::assertTrue(Bunny::verifyWebhookSignature($body, $signature, 'v1', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body . "\n", $signature, 'v1', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, strtoupper($signature), 'v1', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, $signature, 'v2', 'hmac-sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, $signature, 'v1', 'sha256', 'read-only-key'));
        self::assertFalse(Bunny::verifyWebhookSignature($body, $signature, 'v1', 'hmac-sha256', ''));
    }

    // Private Methods
    // =========================================================================

    /**
     * Delivers a webhook to the controller with mocked services.
     */
    private function _deliver(string $body, string $signature, string $key, string $outcome, bool $enabled = true): \yii\web\Response
    {
        $previousApp = Craft::$app;
        $request = $this->createMock(\craft\web\Request::class);
        $request->method('getRawBody')->willReturn($body);
        $headers = new \yii\web\HeaderCollection();
        $headers->set('X-BunnyStream-Signature', $signature);
        $headers->set('X-BunnyStream-Signature-Version', 'v1');
        $headers->set('X-BunnyStream-Signature-Algorithm', 'hmac-sha256');
        $request->method('getHeaders')->willReturn($headers);
        $response = $this->getMockBuilder(\craft\web\Response::class)->disableOriginalConstructor()
            ->onlyMethods([])->getMock();
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getRequest', 'getResponse'])->getMock();
        $app->method('getRequest')->willReturn($request);
        $app->method('getResponse')->willReturn($response);
        Craft::$app = $app;

        try {
            $bunny = $this->createMock(Bunny::class);
            $bunny->method('getWebhookKey')->willReturn($key);
            $bunny->method('getLibraryId')->willReturn('133');
            $sync = $this->createMock(BunnySync::class);

            match ($outcome) {
                'matched' => $sync->expects(self::once())->method('syncVideo')->with('133', self::VIDEO)
                    ->willReturn($this->createMock(MediaItemRecord::class)),
                'unmatched' => $sync->expects(self::once())->method('syncVideo')->willReturn(null),
                'failure' => $sync->method('syncVideo')->willThrowException(new \RuntimeException('Bunny is down')),
                default => $sync->expects(self::never())->method('syncVideo'),
            };

            $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
                ->onlyMethods(['getBunny', 'getBunnySync', 'getBunnyEnabled'])->getMock();
            $plugin->method('getBunny')->willReturn($bunny);
            $plugin->method('getBunnySync')->willReturn($sync);
            $plugin->method('getBunnyEnabled')->willReturn($enabled);
            $app->loadedModules[Plugin::class] = $plugin;

            $controller = $this->getMockBuilder(WebhooksController::class)->disableOriginalConstructor()
                ->onlyMethods(['requirePostRequest', 'asJson'])->getMock();
            $controller->method('asJson')->willReturnCallback(function(array $data) use ($response): \yii\web\Response {
                $response->data = $data;

                return $response;
            });

            return $controller->actionBunny();
        } finally {
            Craft::$app = $previousApp;
        }
    }
}
