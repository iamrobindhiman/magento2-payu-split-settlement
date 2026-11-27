<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Service\PayuReleaseGuard;
use RKD\PayuSplit\Split\Service\SaleslistPayoutKeyResolver;
use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Message\ManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * A5c-4b — the shared INV-17 decision used by all three suppressor plugins. Owns the one safety-
 * critical rule: resolve the submitted payout to (order, seller) keys, block if ANY is PayU-managed,
 * and FAIL CLOSED (block, never allow) if ownership can't be determined. Centralised so the
 * fail-closed guarantee lives in exactly one tested place, not copied across three plugins.
 */
class WebkulPayoutSuppressorTest extends TestCase
{
    /** @var PayuReleaseGuard&MockObject */
    private $guard;
    /** @var SaleslistPayoutKeyResolver&MockObject */
    private $resolver;
    /** @var ResultFactory&MockObject */
    private $resultFactory;
    /** @var ManagerInterface&MockObject */
    private $messageManager;
    /** @var LoggerInterface&MockObject */
    private $logger;
    /** @var Redirect&MockObject */
    private $redirect;

    protected function setUp(): void
    {
        $this->guard = $this->createMock(PayuReleaseGuard::class);
        $this->resolver = $this->createMock(SaleslistPayoutKeyResolver::class);
        $this->resultFactory = $this->createMock(ResultFactory::class);
        $this->messageManager = $this->createMock(ManagerInterface::class);
        $this->logger = $this->createMock(LoggerInterface::class);

        $this->redirect = $this->createMock(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $this->resultFactory->method('create')->willReturn($this->redirect);
    }

    private function suppressor(): WebkulPayoutSuppressor
    {
        return new WebkulPayoutSuppressor(
            $this->guard,
            $this->resolver,
            $this->resultFactory,
            $this->messageManager,
            $this->logger
        );
    }

    public function testControllerSuppressionReturnsNullWhenNoneManaged(): void
    {
        $this->resolver->method('resolve')->willReturn([
            ['order_id' => 42, 'seller_id' => 18],
            ['order_id' => 41, 'seller_id' => 18],
        ]);
        $this->guard->method('isManagedByPayu')->willReturn(false);
        $this->resultFactory->expects($this->never())->method('create');
        $this->messageManager->expects($this->never())->method('addErrorMessage');

        $this->assertNull($this->suppressor()->controllerSuppression(18, [55, 56]));
    }

    public function testControllerSuppressionReturnsNullWhenNoIds(): void
    {
        $this->resolver->method('resolve')->willReturn([]);
        $this->resultFactory->expects($this->never())->method('create');

        $this->assertNull($this->suppressor()->controllerSuppression(18, []));
    }

    public function testControllerSuppressionBlocksWhenAnyManaged(): void
    {
        $this->resolver->method('resolve')->willReturn([['order_id' => 42, 'seller_id' => 18]]);
        $this->guard->method('isManagedByPayu')->with(42, 18)->willReturn(true);
        $this->messageManager->expects($this->once())->method('addErrorMessage');
        $this->logger->expects($this->once())->method('warning');
        $this->logger->expects($this->never())->method('critical');

        $this->assertSame($this->redirect, $this->suppressor()->controllerSuppression(18, [55]));
    }

    public function testControllerSuppressionBlocksOnSecondManagedAmongMany(): void
    {
        $this->resolver->method('resolve')->willReturn([
            ['order_id' => 41, 'seller_id' => 18],
            ['order_id' => 42, 'seller_id' => 18],
        ]);
        $this->guard->method('isManagedByPayu')->willReturnMap([
            [41, 18, false],
            [42, 18, true],
        ]);

        $this->assertSame($this->redirect, $this->suppressor()->controllerSuppression(18, [55, 56]));
    }

    public function testControllerSuppressionFailsClosedWhenGuardThrows(): void
    {
        $this->resolver->method('resolve')->willReturn([['order_id' => 42, 'seller_id' => 18]]);
        $this->guard->method('isManagedByPayu')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('critical');
        $this->messageManager->expects($this->once())->method('addErrorMessage');

        $this->assertSame($this->redirect, $this->suppressor()->controllerSuppression(18, [55]));
    }

    public function testControllerSuppressionFailsClosedWhenResolverThrows(): void
    {
        $this->resolver->method('resolve')->willThrowException(new \RuntimeException('saleslist read failed'));
        $this->logger->expects($this->once())->method('critical');

        $this->assertSame($this->redirect, $this->suppressor()->controllerSuppression(18, [55]));
    }

    public function testIsManagedPayoutTrueWhenManaged(): void
    {
        $this->guard->method('isManagedByPayu')->with(42, 18)->willReturn(true);

        $this->assertTrue($this->suppressor()->isManagedPayout(42, 18));
    }

    public function testIsManagedPayoutFalseWhenNotManaged(): void
    {
        $this->guard->method('isManagedByPayu')->with(42, 18)->willReturn(false);
        $this->logger->expects($this->never())->method('critical');

        $this->assertFalse($this->suppressor()->isManagedPayout(42, 18));
    }

    public function testIsManagedPayoutFailsClosedOnError(): void
    {
        $this->guard->method('isManagedByPayu')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('critical');

        $this->assertTrue($this->suppressor()->isManagedPayout(42, 18));
    }
}
