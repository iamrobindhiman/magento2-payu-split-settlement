<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Plugin;

use RKD\PayuSplit\Split\Plugin\SuppressWebkulHelperPaysellerPlugin;
use RKD\PayuSplit\Split\Service\WebkulPayoutSuppressor;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Helper\Orders;

/**
 * A5c-4b — INV-17 suppressor for Webkul's dormant Helper\Orders::paysellerpayment (zero callers
 * today; future-proofing against an online-disbursement add-on). The helper returns void and has the
 * (order, seller) key directly, so the plugin simply skips proceed() — performing no payout — when
 * the suppressor reports the vendor is PayU-managed.
 */
class SuppressWebkulHelperPaysellerPluginTest extends TestCase
{
    /** @var WebkulPayoutSuppressor&MockObject */
    private $suppressor;
    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->suppressor = $this->createMock(WebkulPayoutSuppressor::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function plugin(): SuppressWebkulHelperPaysellerPlugin
    {
        return new SuppressWebkulHelperPaysellerPlugin($this->suppressor, $this->logger);
    }

    /**
     * @return Order&MockObject
     */
    private function order(int $id): Order
    {
        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn($id);
        return $order;
    }

    public function testProceedsWhenNotManaged(): void
    {
        $this->suppressor->expects($this->once())
            ->method('isManagedPayout')
            ->with(42, 18)
            ->willReturn(false);

        $proceedArgs = null;
        $order = $this->order(42);
        $proceed = function (...$args) use (&$proceedArgs) {
            $proceedArgs = $args;
            return null;
        };

        $this->plugin()->aroundPaysellerpayment(
            $this->createMock(Orders::class),
            $proceed,
            $order,
            18,
            'tr-abc'
        );

        $this->assertSame([$order, 18, 'tr-abc'], $proceedArgs, 'A non-managed payout must run untouched.');
    }

    public function testSkipsPayoutWhenManaged(): void
    {
        $this->suppressor->method('isManagedPayout')->with(42, 18)->willReturn(true);
        $this->logger->expects($this->once())->method('warning');

        $proceeded = false;
        $proceed = function () use (&$proceeded) {
            $proceeded = true;
            return null;
        };

        $this->plugin()->aroundPaysellerpayment(
            $this->createMock(Orders::class),
            $proceed,
            $this->order(42),
            18,
            'tr-abc'
        );

        $this->assertFalse($proceeded, 'A PayU-managed payout must NOT run via the dormant helper.');
    }
}
