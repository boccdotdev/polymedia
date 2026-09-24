<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\WebhooksController;
use boccdotdev\polymedia\Plugin;
use boccdotdev\polymedia\records\MediaItemRecord;
use boccdotdev\polymedia\services\MediaItems;
use boccdotdev\polymedia\services\Mux;
use Craft;
use PHPUnit\Framework\TestCase;

class MuxWebhookOutcomeTest extends TestCase
{
    /** @dataProvider outcomes */
    public function testDeliveryOutcome(string $outcome, int $status, bool $handled): void
    {
        $previousApp = Craft::$app;
        $payload = json_encode([
            'type' => 'video.asset.ready',
            'data' => ['id' => 'mux-1', 'status' => 'ready'],
        ]);
        $timestamp = time();
        $signature = hash_hmac('sha256', "{$timestamp}.{$payload}", 'test-secret');
        $request = $this->createMock(\craft\web\Request::class);
        $request->method('getRawBody')->willReturn($payload);
        $headers = new \yii\web\HeaderCollection();
        $headers->set('Mux-Signature', "t={$timestamp},v1={$signature}");
        $request->method('getHeaders')->willReturn($headers);
        $response = $this->getMockBuilder(\craft\web\Response::class)->disableOriginalConstructor()
            ->onlyMethods([])->getMock();
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()
            ->onlyMethods(['getRequest', 'getResponse'])->getMock();
        $app->method('getRequest')->willReturn($request);
        $app->method('getResponse')->willReturn($response);
        Craft::$app = $app;

        try {
            $mux = $this->createMock(Mux::class);
            $mux->method('getWebhookSecret')->willReturn('test-secret');
            $items = $this->createMock(MediaItems::class);
            if ($outcome === 'failure') {
                $items->method('applyMuxAssetState')->willThrowException(new \RuntimeException('Write failed'));
            } else {
                $record = $this->createMock(MediaItemRecord::class);
                $record->method('__get')->willReturn(7);
                $items->method('applyMuxAssetState')->willReturn($outcome === 'unmatched' ? null : $record);
            }
            $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
                ->onlyMethods(['getMux', 'getMediaItems'])->getMock();
            $plugin->method('getMux')->willReturn($mux);
            $plugin->method('getMediaItems')->willReturn($items);
            $app->loadedModules[Plugin::class] = $plugin;
            $controller = $this->getMockBuilder(WebhooksController::class)->disableOriginalConstructor()
                ->onlyMethods(['requirePostRequest', 'asJson'])->getMock();
            $controller->method('asJson')->willReturnCallback(function(array $data) use ($response): \yii\web\Response {
                $response->data = $data;
                return $response;
            });

            $result = $controller->actionMux();
            self::assertSame($status, $result->getStatusCode());
            self::assertSame($handled, $result->data['handled']);
            self::assertSame($status === 200, $result->data['ok']);
        } finally {
            Craft::$app = $previousApp;
        }
    }

    public static function outcomes(): array
    {
        return [
            'retry transient failure' => ['failure', 503, false],
            'ack unknown asset' => ['unmatched', 200, false],
            'ack applied or unchanged' => ['matched', 200, true],
        ];
    }

    public function testConsoleCountsPersistenceFailureAndReturnsTemporaryFailure(): void
    {
        $previousApp = Craft::$app;
        $app = $this->getMockBuilder(\craft\web\Application::class)->disableOriginalConstructor()->getMock();
        Craft::$app = $app;

        try {
            $record = $this->createMock(MediaItemRecord::class);
            $record->method('__get')->willReturn('Example');
            $mux = $this->createMock(Mux::class);
            $mux->method('isConfigured')->willReturn(true);
            $mux->method('getAsset')->willReturn(['status' => 'ready']);
            $items = $this->createMock(MediaItems::class);
            $items->method('getByMuxAssetId')->willReturn($record);
            $items->method('applyMuxAssetState')->willThrowException(new \RuntimeException('Write failed'));
            $plugin = $this->getMockBuilder(Plugin::class)->disableOriginalConstructor()
                ->onlyMethods(['getMux', 'getMediaItems'])->getMock();
            $plugin->method('getMux')->willReturn($mux);
            $plugin->method('getMediaItems')->willReturn($items);
            $app->loadedModules[Plugin::class] = $plugin;
            $controller = $this->getMockBuilder(\boccdotdev\polymedia\console\controllers\MuxController::class)
                ->disableOriginalConstructor()->onlyMethods(['stdout', 'stderr'])->getMock();
            $controller->muxAssetId = 'mux-1';
            $controller->expects(self::once())->method('stderr')
                ->with(self::stringContains('Write failed'), self::anything())->willReturn(1);
            $controller->expects(self::once())->method('stdout')
                ->with("Synced 0 item(s), 1 failed.\n")->willReturn(1);

            self::assertSame(\yii\console\ExitCode::TEMPFAIL, $controller->actionSyncStatus());
        } finally {
            Craft::$app = $previousApp;
        }
    }
}
