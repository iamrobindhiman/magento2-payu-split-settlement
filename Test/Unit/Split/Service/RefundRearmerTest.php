<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund as SplitRefundResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\Collection;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitRefund\CollectionFactory;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Service\RefundRearmer;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Break-glass re-arm of a FROZEN refund (A7-3) — the money-critical action. A segment's refund
 * gets frozen (a HELD leg) after the R-6 retry cap or an R-7 response anomaly; re-arm voids the
 * HELD + FAILED legs to REARMED so the refund scan re-selects the segment and the executor
 * re-fires on the SAME intent token (PayU var2-dedup is the double-refund backstop).
 *
 * The hard safety rule: it must REFUSE if the segment carries any SUCCESS / QUEUED / PENDING
 * leg — that refund is already done or in flight, and re-firing it risks a double-refund. It
 * also refuses when nothing is actually frozen (no HELD leg). SUCCESS/QUEUED/PENDING legs are
 * never transitioned.
 */
class RefundRearmerTest extends TestCase
{
    /**
     * @param string $state
     * @return SplitRefund One leg stubbed at the given state.
     */
    private function leg(string $state): SplitRefund
    {
        $leg = $this->createMock(SplitRefund::class);
        $leg->method('getState')->willReturn($state);
        return $leg;
    }

    /**
     * @param SplitRefund[] $legs
     * @return RefundRearmer
     */
    private function rearmerFor(array $legs, SplitRefundResource $resource): RefundRearmer
    {
        $collection = $this->createMock(Collection::class);
        $collection->method('addFieldToFilter')->willReturnSelf();
        $collection->method('getItems')->willReturn($legs);
        $factory = $this->createMock(CollectionFactory::class);
        $factory->method('create')->willReturn($collection);
        // The void loop runs inside a DB transaction; stub the connection (no-op begin/commit).
        $resource->method('getConnection')->willReturn($this->createMock(AdapterInterface::class));

        return new RefundRearmer($factory, $resource);
    }

    public function testVoidsHeldAndFailedLegsToRearmed(): void
    {
        $held = $this->leg(SplitRefund::STATE_HELD);
        $failed = $this->leg(SplitRefund::STATE_FAILED);
        $held->expects($this->once())->method('setState')->with(SplitRefund::STATE_REARMED);
        $failed->expects($this->once())->method('setState')->with(SplitRefund::STATE_REARMED);

        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->exactly(2))->method('save');

        $this->assertSame(2, $this->rearmerFor([$held, $failed], $resource)->rearm(8));
    }

    public function testRefusesWhenAPendingRefundLegExists(): void
    {
        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->rearmerFor([$this->leg(SplitRefund::STATE_PENDING), $this->leg(SplitRefund::STATE_HELD)], $resource)
            ->rearm(8);
    }

    public function testRefusesWhenASuccessfulRefundLegExists(): void
    {
        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->rearmerFor([$this->leg(SplitRefund::STATE_SUCCESS), $this->leg(SplitRefund::STATE_HELD)], $resource)
            ->rearm(8);
    }

    public function testRefusesWhenAnInFlightRefundLegExists(): void
    {
        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->rearmerFor([$this->leg(SplitRefund::STATE_QUEUED), $this->leg(SplitRefund::STATE_HELD)], $resource)
            ->rearm(8);
    }

    public function testRefusesWhenNothingIsFrozen(): void
    {
        // Only FAILED legs (no HELD): the scan already retries FAILED-only segments, so there
        // is nothing frozen to re-arm.
        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->rearmerFor([$this->leg(SplitRefund::STATE_FAILED)], $resource)->rearm(8);
    }

    public function testRefusesWhenThereAreNoLegs(): void
    {
        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        $this->rearmerFor([], $resource)->rearm(8);
    }

    public function testLeavesAlreadyRearmedLegsUntouched(): void
    {
        // A prior re-arm left REARMED legs; only the new HELD leg should be transitioned.
        $rearmed = $this->leg(SplitRefund::STATE_REARMED);
        $held = $this->leg(SplitRefund::STATE_HELD);
        $rearmed->expects($this->never())->method('setState');
        $held->expects($this->once())->method('setState')->with(SplitRefund::STATE_REARMED);

        $resource = $this->createMock(SplitRefundResource::class);
        $resource->expects($this->once())->method('save')->with($held);

        $this->rearmerFor([$rearmed, $held], $resource)->rearm(8);
    }
}
