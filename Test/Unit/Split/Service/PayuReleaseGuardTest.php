<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\PayuReleaseGuard;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * A5c-4b — the predicate that decides whether PayU is managing a vendor's settlement for an
 * (order, seller). True means Webkul's native "Pay Seller" MUST be suppressed (INV-17 anti-
 * double-pay). The block is deliberately broad: any vendor segment on a DONE split, across the
 * whole lifecycle, EXCEPT NOT_IN_SPLIT (an unmapped vendor PayU never settled — Webkul must
 * still be able to pay them).
 */
class PayuReleaseGuardTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    private function segment(int $sellerId, string $type, string $state): SplitSegment
    {
        /** @var SplitSegment $seg */
        $seg = $this->om->getObject(SplitSegment::class);
        $seg->setData('seller_id', $sellerId);
        $seg->setData('segment_type', $type);
        $seg->setData('state', $state);
        return $seg;
    }

    private function header(string $state): SplitOrder
    {
        /** @var SplitOrder $h */
        $h = $this->om->getObject(SplitOrder::class);
        $h->setData('split_state', $state);
        return $h;
    }

    /**
     * @param SplitSegment[] $segments
     */
    private function guard(?SplitOrder $header, array $segments): PayuReleaseGuard
    {
        $repo = $this->createMock(SplitOrderRepositoryInterface::class);
        $repo->method('getByOrderId')->willReturn($header);
        $loader = $this->createMock(SegmentLoader::class);
        $loader->method('loadByOrderId')->willReturn($segments);
        return new PayuReleaseGuard($repo, $loader);
    }

    public function testManagedWhenVendorSegmentOnDoneSplit(): void
    {
        $guard = $this->guard(
            $this->header(SplitOrder::STATE_DONE),
            [$this->segment(18, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_AWAITING_DELIVERY)]
        );

        $this->assertTrue($guard->isManagedByPayu(42, 18));
    }

    public function testNotManagedWhenNoSplitHeader(): void
    {
        $guard = $this->guard(null, []);

        $this->assertFalse($guard->isManagedByPayu(42, 18));
    }

    /**
     * PayU owns (or is about to own) the vendor money across the whole ACTIVE split lifecycle, not
     * just at DONE: once a split is recorded it is heading for settlement, and the REQUESTED window
     * (split POSTed to PayU, our header awaiting cron reconciliation) is a real double-pay window if
     * a manual payout slips through before DONE. So all of PENDING/ELIGIBLE/REQUESTED/DONE block.
     *
     * @dataProvider activeSplitStates
     */
    public function testManagedAcrossActiveSplitStates(string $headerState): void
    {
        $guard = $this->guard(
            $this->header($headerState),
            [$this->segment(18, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_PENDING)]
        );

        $this->assertTrue($guard->isManagedByPayu(42, 18));
    }

    /** @return array<int, array{0: string}> */
    public static function activeSplitStates(): array
    {
        return [
            [SplitOrder::STATE_PENDING],
            [SplitOrder::STATE_ELIGIBLE],
            [SplitOrder::STATE_REQUESTED],
            [SplitOrder::STATE_DONE],
        ];
    }

    /**
     * Terminal "PayU will never settle this" headers — the marketplace owes the vendor through
     * Webkul's normal payout, so these must NOT block (else the vendor is stranded).
     *
     * @dataProvider terminalNonSettlingSplitStates
     */
    public function testNotManagedForTerminalNonSettlingSplitStates(string $headerState): void
    {
        $guard = $this->guard(
            $this->header($headerState),
            [$this->segment(18, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_PENDING)]
        );

        $this->assertFalse($guard->isManagedByPayu(42, 18));
    }

    /** @return array<int, array{0: string}> */
    public static function terminalNonSettlingSplitStates(): array
    {
        return [
            [SplitOrder::STATE_NOT_REQUIRED],
            [SplitOrder::STATE_FAILED],
            [SplitOrder::STATE_SKIPPED],
        ];
    }

    public function testNotManagedWhenSegmentBelongsToAnotherSeller(): void
    {
        $guard = $this->guard(
            $this->header(SplitOrder::STATE_DONE),
            [$this->segment(99, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_ELIGIBLE)]
        );

        $this->assertFalse($guard->isManagedByPayu(42, 18));
    }

    public function testCommissionSegmentNeverManagesAVendorPayout(): void
    {
        // The marketplace parent (commission) segment carries seller_id 0 + type commission and has
        // no Webkul seller payout of its own — it must never trigger a block.
        $guard = $this->guard(
            $this->header(SplitOrder::STATE_DONE),
            [$this->segment(0, SplitSegment::TYPE_COMMISSION, SplitSegment::STATE_ELIGIBLE)]
        );

        $this->assertFalse($guard->isManagedByPayu(42, 0));
    }

    public function testNotManagedWhenSegmentIsNotInSplit(): void
    {
        // NOT_IN_SPLIT = an unmapped vendor PayU never settled; the marketplace still owes them via
        // Webkul's normal payout, so this must NOT block (mirrors UndeliveredSegments' exclusion).
        $guard = $this->guard(
            $this->header(SplitOrder::STATE_DONE),
            [$this->segment(18, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_NOT_IN_SPLIT)]
        );

        $this->assertFalse($guard->isManagedByPayu(42, 18));
    }

    public function testManagedWhenManagedSegmentSitsAlongsideAnUnmanagedOne(): void
    {
        // One NOT_IN_SPLIT vendor row for a different seller must not mask a real managed segment.
        $guard = $this->guard(
            $this->header(SplitOrder::STATE_DONE),
            [
                $this->segment(99, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_NOT_IN_SPLIT),
                $this->segment(18, SplitSegment::TYPE_VENDOR, SplitSegment::STATE_RELEASED),
            ]
        );

        $this->assertTrue($guard->isManagedByPayu(42, 18));
    }

    /**
     * The block spans the whole managed lifecycle, not just RELEASED: PayU owns the money from the
     * moment the split is DONE (held), through release, refund and cancellation. WRITTEN_OFF is
     * intentionally still blocked here (conservative — surfaced for review).
     *
     * @dataProvider managedLifecycleStates
     */
    public function testManagedAcrossTheWholeLifecycle(string $state): void
    {
        $guard = $this->guard(
            $this->header(SplitOrder::STATE_DONE),
            [$this->segment(18, SplitSegment::TYPE_VENDOR, $state)]
        );

        $this->assertTrue($guard->isManagedByPayu(42, 18));
    }

    /** @return array<int, array{0: string}> */
    public static function managedLifecycleStates(): array
    {
        return [
            [SplitSegment::STATE_PENDING],
            [SplitSegment::STATE_AWAITING_DELIVERY],
            [SplitSegment::STATE_IN_WINDOW],
            [SplitSegment::STATE_ELIGIBLE],
            [SplitSegment::STATE_RELEASE_REQUESTED],
            [SplitSegment::STATE_RELEASED],
            [SplitSegment::STATE_REFUND_PENDING],
            [SplitSegment::STATE_PARTIAL_REFUNDED],
            [SplitSegment::STATE_REFUNDED],
            [SplitSegment::STATE_CANCELLED],
            [SplitSegment::STATE_WRITTEN_OFF],
        ];
    }
}
