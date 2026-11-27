<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\PayuApiResponse;
use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Service\CreditMemoRefundReader;
use RKD\PayuSplit\Split\Service\ReleaseProcessor;
use RKD\PayuSplit\Split\Service\ReleaseRaceGuard;
use RKD\PayuSplit\Split\Service\RefundLedger;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentItemLoader;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The release executor is the keystone money mover: for an eligible held segment it CLAIMS
 * the release with an atomic compare-and-set (state ELIGIBLE→RELEASE_REQUESTED + attempts++ in
 * one conditional UPDATE, so two overlapping ticks can't both fire), then fires exactly one
 * release_settlement. It deliberately stops at RELEASE_REQUESTED — settlement is async (T+1)
 * and the sync response is ambiguous, so RELEASED is reached only by the later readback recon
 * (A5c-5). Tests pin the gates (delivery/window via SegmentEligibility, holds, a HELD/pending
 * refund leg = INV-19, parent-last = INV-14), the CAS claim (write-ahead BEFORE the gateway
 * call; a concurrent claim does not double-fire), idempotency, and the guard rails.
 */
class ReleaseProcessorTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    /**
     * @param array<string, object> $overrides
     * @return array{0: ReleaseProcessor, 1: array<string, object>}
     */
    private function make(array $overrides = []): array
    {
        $connection = $overrides['connection'] ?? $this->createMock(AdapterInterface::class);
        unset($overrides['connection']);
        $segmentResource = $this->createMock(SplitSegmentResource::class);
        $segmentResource->method('getConnection')->willReturn($connection);
        $segmentResource->method('getMainTable')->willReturn('rkd_payu_split_segment');

        $deps = [
            'gateway' => $this->createMock(PayuSplitClient::class),
            'repository' => $this->createMock(SplitOrderRepositoryInterface::class),
            'eligibility' => new SegmentEligibility(),
            'itemLoader' => $this->createMock(SegmentItemLoader::class),
            'holdLoader' => $this->createMock(SegmentHoldLoader::class),
            'holdWriter' => $this->createMock(SegmentHoldWriter::class),
            'refundLedger' => $this->createMock(RefundLedger::class),
            'creditMemoReader' => $this->createMock(CreditMemoRefundReader::class),
            'segmentLoader' => $this->createMock(SegmentLoader::class),
            'segmentResource' => $segmentResource,
            'connection' => $connection,
            'sellerOrderStatus' => $this->createMock(SellerOrderStatus::class),
            'dateTime' => $this->createMock(DateTime::class),
            'logger' => $this->createMock(LoggerInterface::class),
        ];
        foreach ($overrides as $k => $v) {
            $deps[$k] = $v;
        }
        $deps['dateTime']->method('gmtDate')->willReturn('2026-06-20 00:00:00');

        // The post-claim race re-check is the SHARED ReleaseRaceGuard (gate item F2). Build the real
        // guard from the same mocks so the existing raced-in tests keep exercising the actual logic.
        $raceGuard = $deps['raceGuard'] ?? new ReleaseRaceGuard(
            $deps['holdLoader'],
            $deps['refundLedger'],
            $deps['itemLoader'],
            $deps['creditMemoReader'],
            $deps['sellerOrderStatus']
        );

        $processor = new ReleaseProcessor(
            $deps['gateway'],
            $deps['repository'],
            $deps['eligibility'],
            $deps['itemLoader'],
            $deps['holdLoader'],
            $deps['holdWriter'],
            $deps['refundLedger'],
            $deps['creditMemoReader'],
            $deps['segmentLoader'],
            $deps['segmentResource'],
            $deps['sellerOrderStatus'],
            $raceGuard,
            $deps['dateTime'],
            $deps['logger']
        );
        return [$processor, $deps];
    }

    private function header(string $mihpayid = '100000000000000003'): SplitOrder
    {
        /** @var SplitOrder $h */
        $h = $this->om->getObject(SplitOrder::class);
        $h->setData('mihpayid', $mihpayid);
        return $h;
    }

    private function vendorSegment(string $state = SplitSegment::STATE_ELIGIBLE): SplitSegment
    {
        /** @var SplitSegment $s */
        $s = $this->om->getObject(SplitSegment::class);
        $s->setId(7);
        $s->setData('order_id', 42);
        $s->setData('seller_id', 18);
        $s->setData('segment_type', SplitSegment::TYPE_VENDOR);
        $s->setData('child_mid', '9000001');
        $s->setData('amount_paise', 51136);
        $s->setData('release_attempts', 0);
        $s->setState($state);
        return $s;
    }

    private function deliveredItem(): SplitSegmentItem
    {
        /** @var SplitSegmentItem $it */
        $it = $this->om->getObject(SplitSegmentItem::class);
        $it->setData('order_item_id', 52);
        $it->setData('delivered_at', '2026-06-10 00:00:00');
        $it->setData('window_closes_at', '2026-06-17 00:00:00'); // past nowGmt 2026-06-20
        $it->setData('return_state', SplitSegmentItem::RETURN_NONE);
        return $it;
    }

    private function notReleased(): PayuApiResponse
    {
        return $this->createConfiguredMock(PayuApiResponse::class, ['isAlreadyReleased' => false]);
    }

    public function testEligibleVendorSegmentIsClaimedAndFiredOnce(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->with(42)->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->with(7)->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->with(7)->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->with(7)->willReturn([]);

        // Atomic CAS claim: conditional UPDATE flipping out of the releasable states, 1 row claimed.
        $deps['connection']->expects($this->once())->method('update')
            ->with(
                'rkd_payu_split_segment',
                $this->callback(fn($bind) => $bind['state'] === SplitSegment::STATE_RELEASE_REQUESTED),
                $this->callback(fn($where) => isset($where['segment_id = ?']) && (int) $where['segment_id = ?'] === 7)
            )
            ->willReturn(1);

        $deps['gateway']->expects($this->once())->method('releaseSettlement')
            ->with('100000000000000003', '9000001')->willReturn($this->notReleased());

        $processor->release($segment);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testOutOfBandCreditMemoFreezesOtherwiseEligibleSegmentAndDoesNotFire(): void
    {
        // Gate item #5: the segment is fully release-eligible (delivered, window closed, no hold,
        // not cancelled, no in-flight refund) EXCEPT an out-of-band Magento credit memo already
        // refunded the customer (base_amount_refunded > 0) WITHOUT going through the var8 split-
        // refund path. release_settlement disburses the whole held balance, so releasing would
        // over-pay the vendor. The segment must be FROZEN (durable hold + CRITICAL alarm), never
        // claimed and never fired.
        $segment = $this->vendorSegment();
        $cmReader = $this->createMock(CreditMemoRefundReader::class);
        $cmReader->method('refundedPaiseForItems')->with([52])->willReturn(2000); // ₹20 out-of-band
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        [$processor, $deps] = $this->make(['creditMemoReader' => $cmReader, 'holdWriter' => $holdWriter]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(false);

        $holdWriter->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_FROZEN, $this->stringContains('credit memo'));
        $deps['logger']->expects($this->atLeastOnce())->method('critical');
        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testOrderLevelOutOfBandRefundFreezesEligibleVendorSegment(): void
    {
        // F3: an out-of-band credit memo issued via the Adjustment Refund box (or a shipping-only
        // refund) leaves every item's base_amount_refunded at 0, so the per-item #5 read is 0 — yet
        // the customer WAS refunded. The order-level read must catch it and FREEZE, never release.
        $segment = $this->vendorSegment();
        $cmReader = $this->createMock(CreditMemoRefundReader::class);
        $cmReader->method('refundedPaiseForItems')->willReturn(0);              // nothing per-item
        $cmReader->method('orderLevelRefundPaise')->with(42)->willReturn(9000); // ₹90 adjustment/shipping
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        [$processor, $deps] = $this->make(['creditMemoReader' => $cmReader, 'holdWriter' => $holdWriter]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(false);

        $holdWriter->expects($this->once())->method('place')
            ->with(7, SplitHold::TYPE_FROZEN, $this->stringContains('credit memo'));
        $deps['logger']->expects($this->atLeastOnce())->method('critical');
        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testOrderLevelOutOfBandRefundFreezesParentCommission(): void
    {
        // F3 (parent): the parent commission has no items, so ONLY the order-level read applies. An
        // out-of-band adjustment/shipping refund must freeze it, never release the whole held pool.
        /** @var SplitSegment $parent */
        $parent = $this->om->getObject(SplitSegment::class);
        $parent->setId(8);
        $parent->setData('order_id', 42);
        $parent->setData('seller_id', 0);
        $parent->setData('segment_type', SplitSegment::TYPE_COMMISSION);
        $parent->setData('child_mid', '5000000');
        $parent->setData('release_attempts', 0);
        $parent->setState(SplitSegment::STATE_ELIGIBLE);

        $cmReader = $this->createMock(CreditMemoRefundReader::class);
        $cmReader->method('orderLevelRefundPaise')->with(42)->willReturn(5000);
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        [$processor, $deps] = $this->make(['creditMemoReader' => $cmReader, 'holdWriter' => $holdWriter]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $holdWriter->expects($this->once())->method('place')
            ->with(8, SplitHold::TYPE_FROZEN, $this->stringContains('order-level refund'));
        $deps['logger']->expects($this->atLeastOnce())->method('critical');
        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($parent);
    }

    public function testCleanSegmentWithNoCreditMemoIsNotFrozenAndFires(): void
    {
        // Regression: with base_amount_refunded == 0 (the normal case — refunds run via var8, never
        // a Magento credit memo), the #5 freeze must NOT trip; the segment claims and fires as
        // before. The default CreditMemoRefundReader mock returns 0.
        $segment = $this->vendorSegment();
        $holdWriter = $this->createMock(SegmentHoldWriter::class);
        [$processor, $deps] = $this->make(['holdWriter' => $holdWriter]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['connection']->method('update')->willReturn(1);

        $holdWriter->expects($this->never())->method('place');
        $deps['gateway']->expects($this->once())->method('releaseSettlement')->willReturn($this->notReleased());

        $processor->release($segment);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testCreditMemoRacingInAfterClaimRollsBackAndDoesNotFire(): void
    {
        // An out-of-band credit memo landing in the gate→claim window must abort the fire. The
        // recon readback (INV-18) cannot catch an OFFLINE credit memo — PayU still holds the full
        // amount so settled == amount − refunded_paise and the mismatch never trips — so the
        // post-claim re-check is the only thing that can stop the over-release. The claim is rolled
        // back (state restored); the next tick's pre-claim gate then freezes it.
        $segment = $this->vendorSegment(SplitSegment::STATE_ELIGIBLE);
        $cmReader = $this->createMock(CreditMemoRefundReader::class);
        // 0 pre-claim (gate passes) → 2000 post-claim (the credit memo raced in).
        $cmReader->method('refundedPaiseForItems')->willReturnOnConsecutiveCalls(0, 2000);
        [$processor, $deps] = $this->make(['creditMemoReader' => $cmReader]);
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(false);

        $updates = [];
        $deps['connection']->method('update')->willReturnCallback(function ($table, $bind, $where) use (&$updates) {
            $updates[] = $bind;
            return 1;
        });
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);

        // Two updates: the claim then the rollback. The rollback restores the prior state.
        $this->assertCount(2, $updates);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $updates[0]['state']);
        $this->assertSame(SplitSegment::STATE_ELIGIBLE, $updates[1]['state']);
        $this->assertSame(SplitSegment::STATE_ELIGIBLE, $segment->getState());
    }

    public function testConcurrentClaimDoesNotDoubleFire(): void
    {
        // CAS returns 0 rows → another tick already claimed this segment → must NOT fire.
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['connection']->method('update')->willReturn(0);

        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testUndeliveredItemBlocksRelease(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        /** @var SplitSegmentItem $undelivered */
        $undelivered = $this->om->getObject(SplitSegmentItem::class);
        $undelivered->setData('delivered_at', null);
        $deps['itemLoader']->method('loadItems')->willReturn([$undelivered]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testHeldRefundLegBlocksRelease(): void
    {
        // INV-19: a non-FAILED refund leg (incl. HELD) on the segment must block release.
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')
            ->willReturn([$this->createMock(\RKD\PayuSplit\Split\Model\SplitRefund::class)]);

        $deps['gateway']->expects($this->never())->method('releaseSettlement');
        $processor->release($segment);
    }

    public function testHoldBlocksRelease(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn(['RETURN_OPEN']);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $deps['gateway']->expects($this->never())->method('releaseSettlement');
        $processor->release($segment);
    }

    public function testCancelledSellerPortionBlocksRelease(): void
    {
        // Gate blocker #1: a cancelled seller portion (marketplace_orders.is_canceled = 1) makes
        // the segment a REFUND candidate, never a release candidate. Releasing it would strand the
        // customer's refund (the refund executor permanently refuses a released segment). The
        // release gate must honour the same flag RefundableSegments keys off.
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->with(42, 18)->willReturn(true);

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testCancelRacingInAfterClaimRollsBackAndDoesNotFire(): void
    {
        // Closes the scan→claim TOCTOU: the pre-claim gate passes (no cancel/hold yet), the CAS
        // claim succeeds, but a cancel lands BEFORE we fire — placing a hold the post-claim
        // re-check sees. The claim must be rolled back (state restored, attempts decremented) and
        // the gateway must NOT fire, leaving the segment for the refund executor.
        $segment = $this->vendorSegment(SplitSegment::STATE_ELIGIBLE);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        // [] pre-claim (gate passes) → ['CANCELED'] post-claim (race detected).
        $deps['holdLoader']->method('holdTypes')->willReturnOnConsecutiveCalls([], ['CANCELED']);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(false);

        $updates = [];
        $deps['connection']->method('update')->willReturnCallback(function ($table, $bind, $where) use (&$updates) {
            $updates[] = ['bind' => $bind, 'where' => $where];
            return 1;
        });
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);

        // Two updates: the claim then the rollback. The rollback restores the prior state.
        $this->assertCount(2, $updates);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $updates[0]['bind']['state']);
        $this->assertSame(SplitSegment::STATE_ELIGIBLE, $updates[1]['bind']['state']);
        $this->assertSame(SplitSegment::STATE_ELIGIBLE, $segment->getState());
    }

    public function testReturnOpeningAfterClaimRollsBackAndDoesNotFire(): void
    {
        // A return opened in the gate→claim window flips an item to RETURN_OPEN. The post-claim
        // re-check must read return_state (not just holds/cancel/refund) and roll the claim back.
        $segment = $this->vendorSegment(SplitSegment::STATE_ELIGIBLE);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        /** @var SplitSegmentItem $openItem */
        $openItem = $this->om->getObject(SplitSegmentItem::class);
        $openItem->setData('delivered_at', '2026-06-10 00:00:00');
        $openItem->setData('window_closes_at', '2026-06-17 00:00:00');
        $openItem->setData('return_state', SplitSegmentItem::RETURN_OPEN);
        // delivered+RETURN_NONE pre-claim (gate passes) → RETURN_OPEN post-claim (race detected).
        $deps['itemLoader']->method('loadItems')
            ->willReturnOnConsecutiveCalls([$this->deliveredItem()], [$openItem]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(false);

        $updates = [];
        $deps['connection']->method('update')->willReturnCallback(function ($table, $bind, $where) use (&$updates) {
            $updates[] = $bind;
            return 1;
        });
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);

        $this->assertCount(2, $updates);
        $this->assertSame(SplitSegment::STATE_ELIGIBLE, $updates[1]['state']);
        $this->assertSame(SplitSegment::STATE_ELIGIBLE, $segment->getState());
    }

    public function testRollbackAffectingZeroRowsLeavesModelClaimed(): void
    {
        // If another path moved the row out of RELEASE_REQUESTED before our rollback ran, the
        // conditional UPDATE affects 0 rows — the in-memory model must NOT be reset to the prior
        // state (it would then lie about a state the DB no longer holds).
        $segment = $this->vendorSegment(SplitSegment::STATE_ELIGIBLE);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        // [] pre-claim → ['CANCELED'] post-claim triggers the rollback attempt.
        $deps['holdLoader']->method('holdTypes')->willReturnOnConsecutiveCalls([], ['CANCELED']);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(false);
        // claim affects 1; rollback affects 0 (row already moved by a concurrent path).
        $deps['connection']->method('update')->willReturnOnConsecutiveCalls(1, 0);
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);

        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testParentReleaseIsNotBlockedByIsCanceledFlag(): void
    {
        // Regression guard: the is_canceled block applies to VENDOR segments only. The parent
        // commission is gated by parent-last (INV-14) + the fact that each var8 deducts the
        // cancelled vendor's commission from the parent's held pool. Blocking the parent on
        // is_canceled would PERMANENTLY strand the commission (the flag never clears). So even
        // with a cancelled sibling, an otherwise-eligible parent must still release.
        /** @var SplitSegment $parent */
        $parent = $this->om->getObject(SplitSegment::class);
        $parent->setId(8);
        $parent->setData('order_id', 42);
        $parent->setData('seller_id', 0);
        $parent->setData('segment_type', SplitSegment::TYPE_COMMISSION);
        $parent->setData('child_mid', '5000000');
        $parent->setData('release_attempts', 0);
        $parent->setState(SplitSegment::STATE_ELIGIBLE);

        $releasedVendor = $this->vendorSegment(SplitSegment::STATE_RELEASED);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['segmentLoader']->method('loadByOrderId')->willReturn([$parent, $releasedVendor]);
        // Even if this WERE consulted for the parent, it would say cancelled — it must be ignored.
        $deps['sellerOrderStatus']->method('isSellerCanceled')->willReturn(true);
        $deps['connection']->method('update')->willReturn(1);
        $deps['gateway']->expects($this->once())->method('releaseSettlement')
            ->with('100000000000000003', '5000000')->willReturn($this->notReleased());

        $processor->release($parent);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $parent->getState());
    }

    public function testParentReleasesOnlyAfterAllVendorsTerminal(): void
    {
        // INV-14: the commission segment releases LAST.
        /** @var SplitSegment $parent */
        $parent = $this->om->getObject(SplitSegment::class);
        $parent->setId(8);
        $parent->setData('order_id', 42);
        $parent->setData('seller_id', 0);
        $parent->setData('segment_type', SplitSegment::TYPE_COMMISSION);
        $parent->setData('child_mid', '5000000');
        $parent->setData('release_attempts', 0);
        $parent->setState(SplitSegment::STATE_ELIGIBLE);

        // one vendor still PENDING → parent must NOT release
        $pendingVendor = $this->vendorSegment(SplitSegment::STATE_PENDING);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['segmentLoader']->method('loadByOrderId')->with(42)->willReturn([$parent, $pendingVendor]);
        $deps['gateway']->expects($this->never())->method('releaseSettlement');
        $processor->release($parent);

        // now all vendors terminal → parent releases
        $releasedVendor = $this->vendorSegment(SplitSegment::STATE_RELEASED);
        [$processor2, $deps2] = $this->make();
        $deps2['repository']->method('getByOrderId')->willReturn($this->header());
        $deps2['holdLoader']->method('holdTypes')->willReturn([]);
        $deps2['refundLedger']->method('activeLegs')->willReturn([]);
        $deps2['segmentLoader']->method('loadByOrderId')->willReturn([$parent, $releasedVendor]);
        $deps2['connection']->method('update')->willReturn(1);
        $deps2['gateway']->expects($this->once())->method('releaseSettlement')
            ->with('100000000000000003', '5000000')->willReturn($this->notReleased());

        $parent->setState(SplitSegment::STATE_ELIGIBLE);
        $processor2->release($parent);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $parent->getState());
    }

    public function testClaimIsPersistedBeforeGatewayCall(): void
    {
        // Crash-safety: the CAS claim must persist BEFORE the release fires, so a crash during
        // the call leaves a recoverable record (recon readback resolves it).
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);

        $order = [];
        $deps['connection']->method('update')->willReturnCallback(function () use (&$order) {
            $order[] = 'claim';
            return 1;
        });
        $deps['gateway']->method('releaseSettlement')->willReturnCallback(function () use (&$order) {
            $order[] = 'release';
            return $this->notReleased();
        });

        $processor->release($segment);
        $this->assertSame(['claim', 'release'], $order);
    }

    public function testTransportErrorLeavesSegmentReleaseRequested(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());
        $deps['itemLoader']->method('loadItems')->willReturn([$this->deliveredItem()]);
        $deps['holdLoader']->method('holdTypes')->willReturn([]);
        $deps['refundLedger']->method('activeLegs')->willReturn([]);
        $deps['connection']->method('update')->willReturn(1);
        $deps['gateway']->method('releaseSettlement')->willThrowException(new PayuSplitRequestException('timeout'));

        // No rethrow — the write-ahead claim is the durable record; recon re-fires.
        $processor->release($segment);
        $this->assertSame(SplitSegment::STATE_RELEASE_REQUESTED, $segment->getState());
    }

    public function testNullChildMidSkipsWithoutClaimingOrFiring(): void
    {
        $segment = $this->vendorSegment();
        $segment->setData('child_mid', null);
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header());

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testNoMihpayidSkipsWithoutClaimingOrFiring(): void
    {
        $segment = $this->vendorSegment();
        [$processor, $deps] = $this->make();
        $deps['repository']->method('getByOrderId')->willReturn($this->header(''));

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }

    public function testAlreadyRequestedSegmentIsNotReFired(): void
    {
        $segment = $this->vendorSegment(SplitSegment::STATE_RELEASE_REQUESTED);
        [$processor, $deps] = $this->make();

        $deps['connection']->expects($this->never())->method('update');
        $deps['gateway']->expects($this->never())->method('releaseSettlement');

        $processor->release($segment);
    }
}
