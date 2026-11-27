<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\WebkulRefundCloser;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Model\ResourceModel\Saleslist as SaleslistResource;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\Collection as SaleslistCollection;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory as SaleslistCollectionFactory;
use Webkul\Marketplace\Model\Saleslist;

/**
 * Gate blocker #4 — the Webkul ledger CLOSE for a PayU-refunded vendor segment. The customer has
 * been refunded (var8), so the vendor is owed nothing: every payable saleslist row for the
 * (order, seller) must be flipped non-payable so Webkul's native "Pay Seller" can never overpay a
 * refunded vendor. Unlike the RELEASED mirror this NEVER pays — no payout-log row, no balance
 * credit — it only closes. Tests pin: the close flips ONLY the payable rows (paid_status=0 &
 * cpprostatus=1) to REFUNDED, the single transaction (commit on success / rollBack leaving the
 * segment unsynced on error), idempotency (already-synced / non-refund-state / commission-parent /
 * CAS-lost no-op), and that it credits the seller nothing.
 */
class WebkulRefundCloserTest extends TestCase
{
    private ObjectManager $om;

    /** @var array<string, mixed> Filters applied to the last saleslist collection (regression capture). */
    private array $saleslistFilters = [];

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: WebkulRefundCloser, 1: array<string, object>}
     */
    private function make(array $overrides = []): array
    {
        $connection = $overrides['connection'] ?? $this->createMock(AdapterInterface::class);
        unset($overrides['connection']);
        $saleslistResource = $this->createMock(SaleslistResource::class);
        $saleslistResource->method('getConnection')->willReturn($connection);

        $deps = [
            'saleslistCollectionFactory' => $this->createMock(SaleslistCollectionFactory::class),
            'saleslistResource' => $saleslistResource,
            'segmentResource' => $this->createMock(SplitSegmentResource::class),
            'dateTime' => $this->createMock(DateTime::class),
            'logger' => $this->createMock(LoggerInterface::class),
            'connection' => $connection,
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }
        $deps['dateTime']->method('gmtDate')->willReturn('2026-06-20 00:00:00');

        $closer = new WebkulRefundCloser(
            $deps['saleslistCollectionFactory'],
            $deps['saleslistResource'],
            $deps['segmentResource'],
            $deps['dateTime'],
            $deps['logger']
        );
        return [$closer, $deps];
    }

    /**
     * @param array<string, mixed> $data
     * @return SplitSegment
     */
    private function segment(array $data = []): SplitSegment
    {
        $merged = array_merge([
            'segment_id' => 7,
            'order_id' => 42,
            'seller_id' => 18,
            'segment_type' => SplitSegment::TYPE_VENDOR,
            'state' => SplitSegment::STATE_REFUNDED,
            'webkul_synced_at' => null,
        ], $data);
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setData($merged);
        $segment->setId((int) $merged['segment_id']);
        return $segment;
    }

    /**
     * @param SaleslistCollectionFactory $factory
     * @param array<int, \PHPUnit\Framework\MockObject\MockObject> $rows
     * @return void
     */
    private function stubSaleslistCollection($factory, array $rows): void
    {
        $this->saleslistFilters = [];
        $collection = $this->createMock(SaleslistCollection::class);
        $collection->method('addFieldToFilter')->willReturnCallback(function ($field, $cond) use ($collection) {
            $this->saleslistFilters[$field] = $cond;
            return $collection;
        });
        $collection->method('getItems')->willReturn($rows);
        $factory->method('create')->willReturn($collection);
    }

    public function testClosesPayableRowsForARefundedVendor(): void
    {
        [$closer, $deps] = $this->make();
        $segment = $this->segment();

        // two payable rows -> flipped to REFUNDED(3), no trans_id.
        $row1 = $this->createMock(Saleslist::class);
        $row1->expects($this->once())->method('setPaidStatus')->with(Saleslist::PAID_STATUS_REFUNDED);
        $row1->expects($this->never())->method('setTransId');
        $row1->method('getId')->willReturn(501);
        $row2 = $this->createMock(Saleslist::class);
        $row2->expects($this->once())->method('setPaidStatus')->with(Saleslist::PAID_STATUS_REFUNDED);
        $row2->method('getId')->willReturn(502);
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], [$row1, $row2]);
        $deps['saleslistResource']->expects($this->exactly(2))->method('save');

        $deps['connection']->method('update')->willReturn(1); // CAS claim wins
        $deps['connection']->expects($this->once())->method('beginTransaction');
        $deps['connection']->expects($this->once())->method('commit');
        $deps['connection']->expects($this->never())->method('rollBack');

        $closer->closeRefunded($segment);

        $this->assertSame('2026-06-20 00:00:00', $segment->getData('webkul_synced_at'));
        // Only COMPLETE + unpaid rows of THIS (order, seller) are closed (Webkul's payable set).
        $this->assertSame(42, $this->saleslistFilters['order_id']);
        $this->assertSame(18, $this->saleslistFilters['seller_id']);
        $this->assertSame(Saleslist::PAID_STATUS_PENDING, $this->saleslistFilters['paid_status']);
        $this->assertSame(Saleslist::PAID_STATUS_COMPLETE, $this->saleslistFilters['cpprostatus']);
    }

    public function testPartialRefundedVendorIsAlsoClosed(): void
    {
        // In v1 cancellation scope a PARTIAL_REFUNDED vendor segment is a fully-cancelled vendor whose
        // customer was refunded the (discounted) paid amount — the vendor is still owed nothing.
        [$closer, $deps] = $this->make();
        $segment = $this->segment(['state' => SplitSegment::STATE_PARTIAL_REFUNDED]);
        $row = $this->createMock(Saleslist::class);
        $row->expects($this->once())->method('setPaidStatus')->with(Saleslist::PAID_STATUS_REFUNDED);
        $row->method('getId')->willReturn(501);
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], [$row]);

        $deps['connection']->method('update')->willReturn(1);
        $deps['connection']->expects($this->once())->method('commit');

        $closer->closeRefunded($segment);

        $this->assertSame('2026-06-20 00:00:00', $segment->getData('webkul_synced_at'));
    }

    public function testCommissionParentIsANoOp(): void
    {
        // The marketplace parent (commission, seller 0) has no Webkul seller ledger — never close it,
        // and it is never selected by the reconcile loader (vendor-only), so do not even claim it.
        [$closer, $deps] = $this->make();
        $segment = $this->segment([
            'segment_id' => 3,
            'seller_id' => 0,
            'segment_type' => SplitSegment::TYPE_COMMISSION,
            'state' => SplitSegment::STATE_PARTIAL_REFUNDED,
        ]);

        $deps['connection']->expects($this->never())->method('update');
        $deps['connection']->expects($this->never())->method('beginTransaction');
        $deps['saleslistResource']->expects($this->never())->method('save');

        $closer->closeRefunded($segment);

        $this->assertNull($segment->getWebkulSyncedAt());
    }

    public function testNonRefundStateIsANoOp(): void
    {
        [$closer, $deps] = $this->make();
        $segment = $this->segment(['state' => SplitSegment::STATE_REFUND_PENDING]);

        $deps['connection']->expects($this->never())->method('beginTransaction');
        $deps['saleslistResource']->expects($this->never())->method('save');

        $closer->closeRefunded($segment);
    }

    public function testAlreadySyncedSegmentIsANoOp(): void
    {
        [$closer, $deps] = $this->make();
        $segment = $this->segment(['webkul_synced_at' => '2026-06-19 00:00:00']);

        $deps['connection']->expects($this->never())->method('beginTransaction');
        $deps['saleslistResource']->expects($this->never())->method('save');

        $closer->closeRefunded($segment);
    }

    public function testConcurrentCloseClaimLostIsANoOp(): void
    {
        // A concurrent run already claimed the segment: the CAS update affects 0 rows, so this run
        // must write nothing and leave the segment unsynced.
        [$closer, $deps] = $this->make();
        $segment = $this->segment();
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], []);
        $deps['connection']->method('update')->willReturn(0); // lost the claim

        $deps['connection']->expects($this->once())->method('beginTransaction');
        $deps['connection']->expects($this->once())->method('rollBack');
        $deps['connection']->expects($this->never())->method('commit');
        $deps['saleslistResource']->expects($this->never())->method('save');

        $closer->closeRefunded($segment);

        $this->assertNull($segment->getWebkulSyncedAt());
    }

    public function testRollsBackAndLeavesUnsyncedOnWriteError(): void
    {
        [$closer, $deps] = $this->make();
        $segment = $this->segment();
        $row = $this->createMock(Saleslist::class);
        $row->method('getId')->willReturn(501);
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], [$row]);
        $deps['saleslistResource']->method('save')->willThrowException(new \RuntimeException('db gone'));

        $deps['connection']->method('update')->willReturn(1); // claim wins, then the write throws
        $deps['connection']->expects($this->once())->method('beginTransaction');
        $deps['connection']->expects($this->once())->method('rollBack');
        $deps['connection']->expects($this->never())->method('commit');
        $deps['logger']->expects($this->once())->method('error')->with($this->stringContains('rolled back'));

        $closer->closeRefunded($segment);

        $this->assertNull($segment->getWebkulSyncedAt(), 'segment must stay unsynced after a rollback');
    }
}
