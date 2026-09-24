<?php

namespace boccdotdev\polymedia\tests\unit;

use boccdotdev\polymedia\controllers\PickerController;
use Craft;
use craft\elements\Asset;
use craft\elements\db\AssetQuery;
use craft\elements\User;
use craft\web\Request;
use PHPUnit\Framework\TestCase;
use yii\base\Action;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class PickerControllerTest extends TestCase
{
    public function testCandidateIdIntersectsRatherThanReplacesFieldCriteria(): void
    {
        $this->checkCandidate(true, true);
    }

    public function testInvisibleAssetIsNotSelectable(): void
    {
        $this->checkCandidate(false, false);
    }

    public function testIneligibleOrMissingAssetIsNotSelectable(): void
    {
        $this->checkCandidate(null, false);
    }

    public function testInheritedActionsAreRejected(): void
    {
        $controller = $this->getMockBuilder(PickerController::class)
            ->disableOriginalConstructor()
            ->onlyMethods([])
            ->getMock();

        $this->expectException(NotFoundHttpException::class);
        $controller->beforeAction(new Action('perform-action', $controller));
    }

    private function checkCandidate(?bool $canView, bool $expected): void
    {
        $user = $this->getMockBuilder(User::class)
            ->disableOriginalConstructor()
            ->getMock();
        $asset = null;
        if ($canView !== null) {
            $asset = $this->getMockBuilder(Asset::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['canView'])
                ->getMock();
            $asset->expects($this->once())->method('canView')
                ->with($user)->willReturn($canView);
        }

        $query = $this->getMockBuilder(AssetQuery::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['one'])
            ->getMock();
        $query->id = ['not', 7];
        $query->kind = ['unknown'];
        $query->where = ['=', 'elements.enabled', true];
        $query->expects($this->once())->method('one')->willReturn($asset);

        $request = $this->getMockBuilder(Request::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRequiredBodyParam'])
            ->getMock();
        $request->method('getRequiredBodyParam')->with('assetId')->willReturn(42);

        $controller = $this->getMockBuilder(PickerController::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getElementQuery', 'asJson'])
            ->getMock();
        $controller->request = $request;
        $controller->method('getElementQuery')->willReturn($query);
        $controller->expects($this->once())->method('asJson')
            ->with(['selectable' => $expected])
            ->willReturn(new Response(['charset' => 'UTF-8']));

        $app = Craft::$app;
        Craft::$app = new class($user) {
            public function __construct(private User $user)
            {
            }

            public function getUser(): object
            {
                return new class($this->user) {
                    public function __construct(private User $user)
                    {
                    }

                    public function getIdentity(): User
                    {
                        return $this->user;
                    }
                };
            }
        };

        try {
            $controller->actionCheck();
            $this->assertSame(['not', 7], $query->id);
            $this->assertSame(['unknown'], $query->kind);
            $this->assertSame([
                'and',
                ['=', 'elements.enabled', true],
                ['elements.id' => 42],
            ], $query->where);
        } finally {
            Craft::$app = $app;
        }
    }
}
