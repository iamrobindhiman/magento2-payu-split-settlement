<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitRefundFactory;
use RKD\PayuSplit\Split\Service\RefundLedger;
use PHPUnit\Framework\TestCase;

/**
 * The ledger persists one rkd_payu_refund row per var8 response leg. The recordLeg write
 * is the testable part (the load path is a thin collection wrapper, smoke-covered live).
 */
class RefundLedgerTest extends TestCase
{
    public function testRecordLegPersistsAllLegColumns(): void
    {
        $leg = $this->createMock(SplitRefund::class);
        $captured = [];
        $leg->method('setData')->willReturnCallback(
            static function (array $data) use (&$captured, $leg) {
                $captured = $data;
                return $leg;
            }
        );

        $factory = $this->createMock(SplitRefundFactory::class);
        $factory->method('create')->willReturn($leg);

        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->once())->method('save')->with($leg);

        $ledger = new RefundLedger(
            $this->createMock(CollectionFactory::class),
            $factory,
            $resource
        );

        $ledger->recordLeg(
            42,
            7,
            5000,
            0,
            '139931269',
            SplitRefund::STATE_QUEUED,
            SplitRefund::REASON_VENDOR_CANCEL
        );

        $this->assertSame(42, $captured['order_id']);
        $this->assertSame(7, $captured['segment_id']);
        $this->assertSame(5000, $captured['amount_paise']);
        $this->assertSame(0, $captured['aggregator_paise']);
        $this->assertSame('139931269', $captured['payu_request_id']);
        $this->assertSame(SplitRefund::STATE_QUEUED, $captured['state']);
        $this->assertSame(SplitRefund::REASON_VENDOR_CANCEL, $captured['reason']);
    }

    public function testFailedLegCountCountsOnlyFailedLegsForTheSegment(): void
    {
        // The R-6 retry-cap is ledger-derived (no schema column): count the segment's FAILED
        // refund legs. Each rejected var8 tick writes one FAILED child leg, so the count is
        // the number of prior failed attempts.
        $filters = [];
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            static function ($field, $cond) use (&$filters, $collection) {
                $filters[$field] = $cond;
                return $collection;
            }
        );
        $collection->method('getSize')->willReturn(5);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $ledger = new RefundLedger(
            $factory,
            $this->createMock(SplitRefundFactory::class),
            $this->createMock(SplitRefundResource::class)
        );

        $this->assertSame(5, $ledger->failedLegCount(7));
        $this->assertSame(7, $filters['segment_id']);
        $this->assertSame(SplitRefund::STATE_FAILED, $filters['state']);
    }

    public function testReleaseBlockingLegsFiltersPendingQueuedHeldForTheSegment(): void
    {
        // #12: the RELEASE gate must EXCLUDE SUCCESS (a completed refund no longer blocks the
        // remainder) while the executor idempotency (activeLegs) still INCLUDES it. releaseBlockingLegs
        // is the legs that still block a release: PENDING/QUEUED/HELD (a stuck HELD refund still owes
        // the customer). FAILED/REARMED/SUCCESS are excluded.
        $filters = [];
        $item = $this->createMock(SplitRefund::class);
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(
            static function ($field, $cond) use (&$filters, $collection) {
                $filters[$field] = $cond;
                return $collection;
            }
        );
        $collection->method('getItems')->willReturn([$item]);

        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);

        $ledger = new RefundLedger(
            $factory,
            $this->createMock(SplitRefundFactory::class),
            $this->createMock(SplitRefundResource::class)
        );

        $this->assertSame([$item], $ledger->releaseBlockingLegs(7));
        $this->assertSame(7, $filters['segment_id']);
        $this->assertSame(
            ['in' => [SplitRefund::STATE_PENDING, SplitRefund::STATE_QUEUED, SplitRefund::STATE_HELD]],
            $filters['state']
        );
    }
}
