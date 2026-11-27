<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Service\SplitStateMachine;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;

/**
 * The split header's state machine: PENDING → REQUESTED → DONE, plus FAILED and a
 * no-state-change "keep" for transient outcomes. Each transition stamps the right
 * timestamp and persists exactly once. Driven against a real SplitOrder model so
 * the assertions are on actual stored state, not mock call-counts.
 */
class SplitStateMachineTest extends TestCase
{
    private const NOW = '2026-06-11 10:00:00';

    private ObjectManager $objectManager;
    /** @var SplitOrderRepositoryInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $repository;
    private SplitStateMachine $stateMachine;

    protected function setUp(): void
    {
        $this->objectManager = new ObjectManager($this);
        $this->repository = $this->createMock(SplitOrderRepositoryInterface::class);

        $dateTime = $this->createMock(DateTime::class);
        $dateTime->method('gmtDate')->willReturn(self::NOW);

        $this->stateMachine = new SplitStateMachine($this->repository, $dateTime);
    }

    private function header(string $state): SplitOrder
    {
        /** @var SplitOrder $header */
        $header = $this->objectManager->getObject(SplitOrder::class);
        $header->setSplitState($state);
        return $header;
    }

    public function testMarkRequestedSetsStateTimestampAndSaves(): void
    {
        $header = $this->header(SplitOrder::STATE_PENDING);
        $this->repository->expects($this->once())->method('save')->with($header);

        $this->stateMachine->markRequested($header);

        $this->assertSame(SplitOrder::STATE_REQUESTED, $header->getSplitState());
        $this->assertSame(self::NOW, $header->getData('split_requested_at'));
    }

    public function testMarkDoneStampsDoneTimestampAndStoresReadback(): void
    {
        $header = $this->header(SplitOrder::STATE_REQUESTED);
        $header->setData('last_error', 'earlier transient note');
        $this->repository->expects($this->once())->method('save')->with($header);

        $this->stateMachine->markDone($header, '{"status":"1"}');

        $this->assertSame(SplitOrder::STATE_DONE, $header->getSplitState());
        $this->assertSame(self::NOW, $header->getData('split_done_at'));
        $this->assertSame('{"status":"1"}', $header->getData('payu_split_response'));
        $this->assertNull($header->getData('last_error'));
    }

    public function testMarkDoneWithNullReadbackLeavesResponseUntouched(): void
    {
        $header = $this->header(SplitOrder::STATE_REQUESTED);
        $header->setData('payu_split_response', 'prior readback');
        $this->repository->expects($this->once())->method('save')->with($header);

        $this->stateMachine->markDone($header, null);

        $this->assertSame(SplitOrder::STATE_DONE, $header->getSplitState());
        $this->assertSame('prior readback', $header->getData('payu_split_response'));
    }

    public function testMarkFailedSetsStateErrorAndSaves(): void
    {
        $header = $this->header(SplitOrder::STATE_REQUESTED);
        $this->repository->expects($this->once())->method('save')->with($header);

        $this->stateMachine->markFailed($header, 'AGG-108 sum mismatch');

        $this->assertSame(SplitOrder::STATE_FAILED, $header->getSplitState());
        $this->assertSame('AGG-108 sum mismatch', $header->getData('last_error'));
    }

    public function testRecordTransientRecordsReasonWithoutChangingState(): void
    {
        $header = $this->header(SplitOrder::STATE_REQUESTED);
        $this->repository->expects($this->once())->method('save')->with($header);

        $this->stateMachine->recordTransient($header, 'split pending — poll next run');

        $this->assertSame(SplitOrder::STATE_REQUESTED, $header->getSplitState());
        $this->assertSame('split pending — poll next run', $header->getData('last_error'));
    }
}
