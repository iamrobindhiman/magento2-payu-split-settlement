<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SellerOrderStatus;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Service\CreditMemoRefundReader;
use RKD\PayuSplit\Split\Service\RefundLedger;
use RKD\PayuSplit\Split\Service\ReleaseRaceGuard;
use RKD\PayuSplit\Split\Service\SegmentHoldLoader;
use RKD\PayuSplit\Split\Service\SegmentItemLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The shared post-claim / pre-re-fire ineligibility re-check (gate item F2). Both release
 * disbursement sites — ReleaseProcessor's post-claim rollback and ReleaseReconciler's overdue
 * re-fire — delegate here, so a cancel / return / hold / refund / out-of-band credit memo landing
 * after a segment was decided releasable blocks the disbursement uniformly. Each blocking signal
 * must trip it; a clean segment must not; the parent commission is excluded from the seller-cancel
 * check (INV-10).
 */
class ReleaseRaceGuardTest extends TestCase
{
    private ObjectManager $om;
    private MockObject&SegmentHoldLoader $holdLoader;
    private MockObject&RefundLedger $refundLedger;
    private MockObject&SegmentItemLoader $itemLoader;
    private MockObject&CreditMemoRefundReader $creditMemoReader;
    private MockObject&SellerOrderStatus $sellerOrderStatus;
    private ReleaseRaceGuard $guard;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
        $this->holdLoader = $this->createMock(SegmentHoldLoader::class);
        $this->refundLedger = $this->createMock(RefundLedger::class);
        $this->itemLoader = $this->createMock(SegmentItemLoader::class);
        $this->creditMemoReader = $this->createMock(CreditMemoRefundReader::class);
        $this->sellerOrderStatus = $this->createMock(SellerOrderStatus::class);
        $this->guard = new ReleaseRaceGuard(
            $this->holdLoader,
            $this->refundLedger,
            $this->itemLoader,
            $this->creditMemoReader,
            $this->sellerOrderStatus
        );
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
        ], $data);
        /** @var SplitSegment $segment */
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setData($merged);
        $segment->setId((int) $merged['segment_id']);
        return $segment;
    }

    private function item(string $returnState, int $orderItemId = 52): SplitSegmentItem
    {
        /** @var SplitSegmentItem $item */
        $item = $this->om->getObject(SplitSegmentItem::class);
        $item->setData('order_item_id', $orderItemId);
        $item->setData('return_state', $returnState);
        return $item;
    }

    private function cleanDefaults(): void
    {
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('activeLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([$this->item(SplitSegmentItem::RETURN_NONE)]);
        $this->creditMemoReader->method('refundedPaiseForItems')->willReturn(0);
        $this->sellerOrderStatus->method('isSellerCanceled')->willReturn(false);
    }

    public function testCleanSegmentIsNotRaced(): void
    {
        $this->cleanDefaults();
        $this->assertFalse($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testAnyHoldTrips(): void
    {
        $this->holdLoader->method('holdTypes')->willReturn(['CANCELED']);
        $this->assertTrue($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testBlockingRefundLegTrips(): void
    {
        // #12: a release-BLOCKING refund leg (PENDING/QUEUED/HELD) racing in aborts the claim.
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('releaseBlockingLegs')->willReturn([$this->createMock(SplitRefund::class)]);
        $this->assertTrue($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testCompletedSuccessRefundLegDoesNotTrip(): void
    {
        // #12 crux: a COMPLETED (SUCCESS) refund is the normal state of a releasable PARTIAL_REFUNDED
        // remainder — it must NOT abort the release, or the claim/rollback would loop forever. The
        // guard reads releaseBlockingLegs (excludes SUCCESS); a segment whose only refund leg is
        // SUCCESS returns [] here, so nothing trips.
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('releaseBlockingLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([$this->item(SplitSegmentItem::RETURN_NONE)]);
        $this->creditMemoReader->method('refundedPaiseForItems')->willReturn(0);
        $this->sellerOrderStatus->method('isSellerCanceled')->willReturn(false);
        $this->assertFalse($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testOpenReturnItemTrips(): void
    {
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('activeLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([$this->item(SplitSegmentItem::RETURN_OPEN)]);
        $this->assertTrue($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testOutOfBandCreditMemoTrips(): void
    {
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('activeLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([$this->item(SplitSegmentItem::RETURN_NONE)]);
        $this->creditMemoReader->method('refundedPaiseForItems')->with([52])->willReturn(2000);
        $this->assertTrue($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testOrderLevelOutOfBandRefundTrips(): void
    {
        // F3: an order-level adjustment/shipping refund (zero per-item) must trip the guard too.
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('activeLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([$this->item(SplitSegmentItem::RETURN_NONE)]);
        $this->creditMemoReader->method('refundedPaiseForItems')->willReturn(0);
        $this->creditMemoReader->method('orderLevelRefundPaise')->with(42)->willReturn(6000);
        $this->assertTrue($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testSellerCancelTripsForVendor(): void
    {
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('activeLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([$this->item(SplitSegmentItem::RETURN_NONE)]);
        $this->creditMemoReader->method('refundedPaiseForItems')->willReturn(0);
        $this->sellerOrderStatus->method('isSellerCanceled')->with(42, 18)->willReturn(true);
        $this->assertTrue($this->guard->racedIntoIneligibility($this->segment()));
    }

    public function testParentCommissionIgnoresSellerCancel(): void
    {
        // The parent (seller_id 0) is excluded from the is_canceled check (INV-10) — the seller
        // status is never even consulted for it, so a cancelled sibling cannot block it here.
        $this->holdLoader->method('holdTypes')->willReturn([]);
        $this->refundLedger->method('activeLegs')->willReturn([]);
        $this->itemLoader->method('loadItems')->willReturn([]);
        $this->creditMemoReader->method('refundedPaiseForItems')->willReturn(0);
        $this->sellerOrderStatus->expects($this->never())->method('isSellerCanceled');

        $parent = $this->segment(['seller_id' => 0, 'segment_type' => SplitSegment::TYPE_COMMISSION]);
        $this->assertFalse($this->guard->racedIntoIneligibility($parent));
    }
}
