<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Service\SplitRearmer;
use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;

/**
 * Re-arming a split is the A7-3 break-glass recovery for a header the trigger scan gave up on
 * (terminal FAILED). It only flips FAILED → PENDING so the next scan re-attempts; it issues NO
 * PayU call itself, and the re-attempt is safe because SplitRequester::request() does
 * get_split_info first (a split that actually succeeded resolves to DONE via AGG-104, never a
 * second payment_split). Refusing any non-FAILED state keeps the action from disturbing a
 * healthy or in-flight split.
 */
class SplitRearmerTest extends TestCase
{
    public function testRearmsAFailedHeaderBackToPending(): void
    {
        $header = $this->createMock(SplitOrder::class);
        $header->method('getSplitState')->willReturn(SplitOrder::STATE_FAILED);
        $header->expects($this->once())->method('setSplitState')->with(SplitOrder::STATE_PENDING);

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->expects($this->once())->method('save')->with($header);

        (new SplitRearmer($repository))->rearm($header);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonFailedStateProvider(): array
    {
        return [
            'pending'      => [SplitOrder::STATE_PENDING],
            'eligible'     => [SplitOrder::STATE_ELIGIBLE],
            'requested'    => [SplitOrder::STATE_REQUESTED],
            'done'         => [SplitOrder::STATE_DONE],
            'not_required' => [SplitOrder::STATE_NOT_REQUIRED],
            'skipped'      => [SplitOrder::STATE_SKIPPED],
        ];
    }

    /**
     * @dataProvider nonFailedStateProvider
     * @param string $state
     */
    public function testRefusesToRearmANonFailedHeader(string $state): void
    {
        $header = $this->createMock(SplitOrder::class);
        $header->method('getSplitState')->willReturn($state);
        $header->expects($this->never())->method('setSplitState');

        $repository = $this->createMock(SplitOrderRepositoryInterface::class);
        $repository->expects($this->never())->method('save');

        $this->expectException(LocalizedException::class);
        (new SplitRearmer($repository))->rearm($header);
    }
}
