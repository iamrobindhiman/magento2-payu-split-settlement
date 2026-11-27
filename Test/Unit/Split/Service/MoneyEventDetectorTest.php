<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Service\MoneyEventDetector;
use PHPUnit\Framework\TestCase;

/**
 * A money event on a not-yet-split order means a customer refund is pending that needs
 * the split to exist first (Fact 4) — so the split must be brought forward rather than
 * waiting for every vendor to ship or the T+25 ceiling. In the cancellation scope a
 * money event is a cancelled vendor portion (marketplace_orders.is_canceled = 1).
 */
class MoneyEventDetectorTest extends TestCase
{
    public function testCancelledVendorPortionIsAMoneyEvent(): void
    {
        $status = $this->createMock(SellerOrderStatus::class);
        $status->method('hasCanceledSeller')->with(45)->willReturn(true);

        $this->assertTrue((new MoneyEventDetector($status))->isMoneyEvent(45));
    }

    public function testNoCancelledPortionIsNotAMoneyEvent(): void
    {
        $status = $this->createMock(SellerOrderStatus::class);
        $status->method('hasCanceledSeller')->with(48)->willReturn(false);

        $this->assertFalse((new MoneyEventDetector($status))->isMoneyEvent(48));
    }
}
