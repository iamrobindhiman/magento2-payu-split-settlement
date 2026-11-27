<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use RKD\PayuSplit\Split\Service\SnapshotPlanReader;
use PHPUnit\Framework\TestCase;

/**
 * Rebuilds an OrderSplitPlan from the A4 payment-time snapshot — never from live
 * Webkul (matrix INV-8). The reconstructed plan is exactly what the A3 gateway's
 * payment_split consumes: vendor segments keyed by seller, the parent remainder,
 * the grand total, and the seller -> child-key map. A snapshot whose segments no
 * longer reconcile to the recorded txn is treated as corruption and rejected.
 */
class SnapshotPlanReaderTest extends TestCase
{
    private SegmentLoader $segmentLoader;
    private SnapshotPlanReader $reader;

    protected function setUp(): void
    {
        $this->segmentLoader = $this->createMock(SegmentLoader::class);
        $this->reader = new SnapshotPlanReader($this->segmentLoader);
    }

    /**
     * @param int $orderId
     * @param string $incrementId
     * @param int $txnPaise
     * @return SplitOrder
     */
    private function header(int $orderId, string $incrementId, int $txnPaise): SplitOrder
    {
        $header = $this->createMock(SplitOrder::class);
        $header->method('getOrderId')->willReturn($orderId);
        $header->method('getOrderIncrementId')->willReturn($incrementId);
        $header->method('getTxnAmountPaise')->willReturn($txnPaise);
        return $header;
    }

    /**
     * @param string $type
     * @param int $sellerId
     * @param int $amountPaise
     * @param string|null $childKey
     * @return SplitSegment
     */
    private function segment(string $type, int $sellerId, int $amountPaise, ?string $childKey): SplitSegment
    {
        $segment = $this->createMock(SplitSegment::class);
        $segment->method('getSegmentType')->willReturn($type);
        $segment->method('getSellerId')->willReturn($sellerId);
        $segment->method('getAmountPaise')->willReturn($amountPaise);
        $segment->method('getChildMerchantKey')->willReturn($childKey);
        return $segment;
    }

    public function testReconstructsPlanFromVendorSegmentsAndCommission(): void
    {
        // Two vendors (5000 + 5000 paise payout) + parent commission/shipping 2000 = 12000 txn.
        $this->segmentLoader->method('loadByOrderId')->with(45)->willReturn([
            $this->segment(SplitSegment::TYPE_VENDOR, 18, 5000, 'CHLD01'),
            $this->segment(SplitSegment::TYPE_VENDOR, 20, 5000, 'CHILD20'),
            $this->segment(SplitSegment::TYPE_COMMISSION, 0, 2000, null),
        ]);

        $plan = $this->reader->read($this->header(45, '000000045', 12000));

        $this->assertSame(45, $plan->orderId());
        $this->assertSame('000000045', $plan->orderIncrementId());

        $segments = $plan->result()->vendorSegments();
        $this->assertSame(5000, $segments[18]->paise);
        $this->assertSame(5000, $segments[20]->paise);
        $this->assertSame(2000, $plan->result()->platformAmount()->paise);
        $this->assertSame(12000, $plan->result()->grandTotal()->paise);

        $this->assertSame(['18' => 'CHLD01', '20' => 'CHILD20'], $this->normaliseKeys($plan->sellerKeyMap()));
    }

    public function testVendorWithoutResolvedKeyIsOmittedFromKeyMap(): void
    {
        $this->segmentLoader->method('loadByOrderId')->willReturn([
            $this->segment(SplitSegment::TYPE_VENDOR, 18, 6000, 'CHLD01'),
            $this->segment(SplitSegment::TYPE_VENDOR, 20, 4000, null), // no child key resolved
            $this->segment(SplitSegment::TYPE_COMMISSION, 0, 2000, null),
        ]);

        $plan = $this->reader->read($this->header(46, '000000046', 12000));

        $this->assertArrayHasKey(18, $plan->sellerKeyMap());
        $this->assertArrayNotHasKey(20, $plan->sellerKeyMap());
        // The segment itself is still present in the result (the base cannot shrink).
        $this->assertArrayHasKey(20, $plan->result()->vendorSegments());
    }

    public function testCommissionOnlyOrderHasEmptyVendorSegments(): void
    {
        $this->segmentLoader->method('loadByOrderId')->willReturn([
            $this->segment(SplitSegment::TYPE_COMMISSION, 0, 9900, null),
        ]);

        $plan = $this->reader->read($this->header(40, '000000040', 9900));

        $this->assertSame([], $plan->result()->vendorSegments());
        $this->assertSame(9900, $plan->result()->platformAmount()->paise);
    }

    public function testRejectsSnapshotThatDoesNotReconcileToTxn(): void
    {
        // 5000 + 5000 + 2000 = 12000, but the header records 13000 → corruption.
        $this->segmentLoader->method('loadByOrderId')->willReturn([
            $this->segment(SplitSegment::TYPE_VENDOR, 18, 5000, 'CHLD01'),
            $this->segment(SplitSegment::TYPE_VENDOR, 20, 5000, 'CHILD20'),
            $this->segment(SplitSegment::TYPE_COMMISSION, 0, 2000, null),
        ]);

        $this->expectException(SplitCalculationException::class);
        $this->expectExceptionMessage('does not reconcile');
        $this->reader->read($this->header(47, '000000047', 13000));
    }

    /**
     * @param array<int, string> $map
     * @return array<string, string>
     */
    private function normaliseKeys(array $map): array
    {
        $out = [];
        foreach ($map as $sellerId => $key) {
            $out[(string) $sellerId] = $key;
        }
        return $out;
    }
}
