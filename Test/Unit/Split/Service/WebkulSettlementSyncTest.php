<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\WebkulSettlementSync;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Model\ResourceModel\Saleperpartner as SaleperpartnerResource;
use Webkul\Marketplace\Model\ResourceModel\Saleperpartner\Collection as SaleperpartnerCollection;
use Webkul\Marketplace\Model\ResourceModel\Saleperpartner\CollectionFactory as SaleperpartnerCollectionFactory;
use Webkul\Marketplace\Model\ResourceModel\Saleslist as SaleslistResource;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\Collection as SaleslistCollection;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory as SaleslistCollectionFactory;
use Webkul\Marketplace\Model\ResourceModel\Sellertransaction as SellertransactionResource;
use Webkul\Marketplace\Model\Saleperpartner as SaleperpartnerModel;
use Webkul\Marketplace\Model\SaleperpartnerFactory;
use Webkul\Marketplace\Model\Saleslist;
use Webkul\Marketplace\Model\Sellertransaction;
use Webkul\Marketplace\Model\SellertransactionFactory;

/**
 * The Webkul ledger mirror (A5c-4). Tests pin: the happy-path writes (payout-log row with the PayU
 * released net, saleslist rows flipped to paid + trans_id, saleperpartner running-balance arithmetic
 * mirrored from Webkul incl. its non-negative guard), the single transaction (commit on success,
 * rollBack on any write error with the segment left unmirrored), idempotency (not-RELEASED /
 * already-synced / commission-parent no-op), and the deterministic payout id.
 */
class WebkulSettlementSyncTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: WebkulSettlementSync, 1: array<string, object>}
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
            'saleperpartnerCollectionFactory' => $this->createMock(SaleperpartnerCollectionFactory::class),
            'saleperpartnerFactory' => $this->createMock(SaleperpartnerFactory::class),
            'saleperpartnerResource' => $this->createMock(SaleperpartnerResource::class),
            'sellertransactionFactory' => $this->createMock(SellertransactionFactory::class),
            'sellertransactionResource' => $this->createMock(SellertransactionResource::class),
            'segmentResource' => $this->createMock(SplitSegmentResource::class),
            'repository' => $this->createMock(SplitOrderRepositoryInterface::class),
            'dateTime' => $this->createMock(DateTime::class),
            'eventManager' => $this->createMock(EventManager::class),
            'logger' => $this->createMock(LoggerInterface::class),
            'connection' => $connection,
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }
        $deps['dateTime']->method('gmtDate')->willReturn('2026-06-20 00:00:00');

        $sync = new WebkulSettlementSync(
            $deps['saleslistCollectionFactory'],
            $deps['saleslistResource'],
            $deps['saleperpartnerCollectionFactory'],
            $deps['saleperpartnerFactory'],
            $deps['saleperpartnerResource'],
            $deps['sellertransactionFactory'],
            $deps['sellertransactionResource'],
            $deps['segmentResource'],
            $deps['repository'],
            $deps['dateTime'],
            $deps['eventManager'],
            $deps['logger']
        );
        return [$sync, $deps];
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
            'released_paise' => 66932,
            'state' => SplitSegment::STATE_RELEASED,
            'webkul_synced_at' => null,
        ], $data);
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setData($merged);
        $segment->setId((int) $merged['segment_id']);
        return $segment;
    }

    private function header(string $mihpayid = '100000000000000001'): SplitOrder
    {
        $header = $this->om->getObject(SplitOrder::class);
        $header->setData(['order_id' => 42, 'mihpayid' => $mihpayid]);
        return $header;
    }

    /**
     * @param SaleslistCollectionFactory $factory
     * @param array<int, \PHPUnit\Framework\MockObject\MockObject> $rows
     * @return void
     */
    /** @var array<string, mixed> Filters applied to the last saleslist collection (regression capture). */
    private array $saleslistFilters = [];

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

    /**
     * @param SaleperpartnerCollectionFactory $factory
     * @param array<int, \PHPUnit\Framework\MockObject\MockObject> $rows
     * @return void
     */
    private function stubSaleperpartnerCollection($factory, array $rows): void
    {
        $collection = $this->createMock(SaleperpartnerCollection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn($rows);
        $factory->method('create')->willReturn($collection);
    }

    public function testHappyPathMirrorsReleaseIntoWebkulLedger(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment(['released_paise' => 66932]); // ₹669.32
        $deps['repository']->method('getByOrderId')->with(42)->willReturn($this->header());

        // sellertransaction: created, saved, id read back = 99.
        $sellerTrans = $this->createMock(Sellertransaction::class);
        $sellerTrans->expects($this->once())->method('setTransactionAmount')->with(669.32);
        $sellerTrans->expects($this->once())->method('setTransactionId')->with('payu-100000000000000001-18');
        $sellerTrans->method('getId')->willReturn(99);
        $deps['sellertransactionFactory']->method('create')->willReturn($sellerTrans);

        // two payable saleslist rows -> flipped paid + trans_id 99.
        $row1 = $this->createMock(Saleslist::class);
        $row1->expects($this->once())->method('setPaidStatus')->with(1);
        $row1->expects($this->once())->method('setTransId')->with(99);
        $row1->method('getId')->willReturn(501);
        $row2 = $this->createMock(Saleslist::class);
        $row2->expects($this->once())->method('setPaidStatus')->with(1);
        $row2->expects($this->once())->method('setTransId')->with(99);
        $row2->method('getId')->willReturn(502);
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], [$row1, $row2]);
        $deps['saleslistResource']->expects($this->exactly(2))->method('save');

        // saleperpartner: existing row id 5; remain 1000, received 500 -> remain 330.68, received 1169.32.
        $existing = $this->createMock(SaleperpartnerModel::class);
        $existing->method('getId')->willReturn(5);
        $this->stubSaleperpartnerCollection($deps['saleperpartnerCollectionFactory'], [$existing]);
        $balanceRow = $this->createMock(SaleperpartnerModel::class);
        $balanceRow->method('getAmountRemain')->willReturn(1000.0);
        $balanceRow->method('getAmountReceived')->willReturn(500.0);
        $balanceRow->expects($this->once())->method('setLastAmountPaid')->with(669.32);
        $balanceRow->expects($this->once())->method('setAmountReceived')->with(1169.32);
        $balanceRow->expects($this->once())->method('setAmountRemain')->with(330.68);
        $deps['saleperpartnerFactory']->method('create')->willReturn($balanceRow);

        $deps['connection']->method('update')->willReturn(1); // CAS claim wins
        $deps['connection']->expects($this->once())->method('beginTransaction');
        $deps['connection']->expects($this->once())->method('commit');
        $deps['connection']->expects($this->never())->method('rollBack');
        $deps['eventManager']->expects($this->once())->method('dispatch')
            ->with('mp_pay_seller', $this->callback(fn($d) => $d[0]['seller_id'] === 18 && $d[0]['mp_trans_row_id'] === 99));

        $sync->sync($segment);

        $this->assertSame('2026-06-20 00:00:00', $segment->getData('webkul_synced_at'));
        // INV-17 / Webkul parity: only COMPLETE + unpaid rows of THIS (order, seller) are marked.
        $this->assertSame(42, $this->saleslistFilters['order_id']);
        $this->assertSame(18, $this->saleslistFilters['seller_id']);
        $this->assertSame(0, $this->saleslistFilters['paid_status']);
        $this->assertSame(1, $this->saleslistFilters['cpprostatus']);
    }

    public function testAmountRemainNeverGoesNegative(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment(['released_paise' => 66932]); // net 669.32
        $deps['repository']->method('getByOrderId')->willReturn($this->header());

        $sellerTrans = $this->createMock(Sellertransaction::class);
        $sellerTrans->method('getId')->willReturn(99);
        $deps['sellertransactionFactory']->method('create')->willReturn($sellerTrans);
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], []);

        $existing = $this->createMock(SaleperpartnerModel::class);
        $existing->method('getId')->willReturn(5);
        $this->stubSaleperpartnerCollection($deps['saleperpartnerCollectionFactory'], [$existing]);
        $balanceRow = $this->createMock(SaleperpartnerModel::class);
        $balanceRow->method('getAmountRemain')->willReturn(100.0); // < net 669.32
        $balanceRow->method('getAmountReceived')->willReturn(0.0);
        // remain floors at 0, never negative (Webkul's guard).
        $balanceRow->expects($this->once())->method('setAmountRemain')->with(0.0);
        $balanceRow->expects($this->once())->method('setAmountReceived')->with(669.32);
        $deps['saleperpartnerFactory']->method('create')->willReturn($balanceRow);

        $deps['connection']->method('update')->willReturn(1); // CAS claim wins
        $deps['connection']->expects($this->once())->method('commit');

        $sync->sync($segment);
    }

    public function testRollsBackAndLeavesUnsyncedOnWriteError(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $sellerTrans = $this->createMock(Sellertransaction::class);
        $sellerTrans->method('getId')->willReturn(99);
        $deps['sellertransactionFactory']->method('create')->willReturn($sellerTrans);
        // The sellertransaction save blows up mid-transaction.
        $deps['sellertransactionResource']->method('save')->willThrowException(new \RuntimeException('db gone'));

        $deps['connection']->method('update')->willReturn(1); // claim wins, then the write throws
        $deps['connection']->expects($this->once())->method('beginTransaction');
        $deps['connection']->expects($this->once())->method('rollBack');
        $deps['connection']->expects($this->never())->method('commit');
        $deps['segmentResource']->expects($this->never())->method('save');
        $deps['eventManager']->expects($this->never())->method('dispatch');
        $deps['logger']->expects($this->once())->method('error')->with($this->stringContains('rolled back'));

        $sync->sync($segment);

        $this->assertNull($segment->getWebkulSyncedAt(), 'segment must stay unmirrored after a rollback');
    }

    public function testNotReleasedSegmentIsNoOp(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment(['state' => SplitSegment::STATE_RELEASE_REQUESTED]);

        $deps['connection']->expects($this->never())->method('beginTransaction');
        $deps['segmentResource']->expects($this->never())->method('save');
        $deps['eventManager']->expects($this->never())->method('dispatch');

        $sync->sync($segment);
    }

    public function testAlreadySyncedSegmentIsNoOp(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment(['webkul_synced_at' => '2026-06-19 00:00:00']);

        $deps['connection']->expects($this->never())->method('beginTransaction');
        $deps['eventManager']->expects($this->never())->method('dispatch');

        $sync->sync($segment);
    }

    public function testCommissionParentIsStampedSyncedWithoutWebkulWrites(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment([
            'segment_id' => 3,
            'seller_id' => 0,
            'segment_type' => SplitSegment::TYPE_COMMISSION,
        ]);

        // No Webkul ledger writes, no transaction, no event — just the synced-marker CAS claim.
        $deps['connection']->expects($this->once())->method('update')->willReturn(1);
        $deps['connection']->expects($this->never())->method('beginTransaction');
        $deps['sellertransactionResource']->expects($this->never())->method('save');
        $deps['eventManager']->expects($this->never())->method('dispatch');

        $sync->sync($segment);

        $this->assertSame('2026-06-20 00:00:00', $segment->getData('webkul_synced_at'));
    }

    public function testConcurrentMirrorClaimLostIsNoOp(): void
    {
        // A concurrent run already claimed the segment: the CAS update affects 0 rows, so this run
        // must write nothing — no payout row, no balance change, no event (no double-pay).
        [$sync, $deps] = $this->make();
        $segment = $this->segment();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['connection']->method('update')->willReturn(0); // lost the claim

        $deps['connection']->expects($this->once())->method('beginTransaction');
        $deps['connection']->expects($this->once())->method('rollBack');
        $deps['connection']->expects($this->never())->method('commit');
        $deps['sellertransactionResource']->expects($this->never())->method('save');
        $deps['saleslistResource']->expects($this->never())->method('save');
        $deps['eventManager']->expects($this->never())->method('dispatch');

        $sync->sync($segment);

        $this->assertNull($segment->getWebkulSyncedAt());
    }

    public function testPayoutIdFallsBackToSegmentWhenNoMihpayid(): void
    {
        [$sync, $deps] = $this->make();
        $segment = $this->segment(['segment_id' => 7]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header('')); // no mihpayid

        $sellerTrans = $this->createMock(Sellertransaction::class);
        $sellerTrans->expects($this->once())->method('setTransactionId')->with('payu-seg-7');
        $sellerTrans->method('getId')->willReturn(99);
        $deps['sellertransactionFactory']->method('create')->willReturn($sellerTrans);
        $this->stubSaleslistCollection($deps['saleslistCollectionFactory'], []);
        $this->stubSaleperpartnerCollection($deps['saleperpartnerCollectionFactory'], []);
        $deps['saleperpartnerFactory']->method('create')->willReturn($this->createMock(SaleperpartnerModel::class));
        $deps['connection']->method('update')->willReturn(1); // CAS claim wins

        $sync->sync($segment);
    }
}
