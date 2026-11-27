<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Observer\QueueSubmerchantOnApproval;
use RKD\PayuSplit\Split\Service\OnboardingQueue;
use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The approval hook runs inside Webkul's mass-approve loop, which has no per-item error
 * handling. If it throws, every vendor after this one in the batch goes unapproved (N-1).
 */
class QueueSubmerchantOnApprovalTest extends TestCase
{
    public function testAnApprovedVendorIsQueued(): void
    {
        $queue = $this->createMock(OnboardingQueue::class);
        $queue->expects(self::once())->method('enqueue')->with(42)->willReturn(OnboardingQueue::RESULT_QUEUED);

        $this->observer(true, $queue)->execute($this->event(42));
    }

    public function testNothingHappensWhileSwitchedOff(): void
    {
        $queue = $this->createMock(OnboardingQueue::class);
        $queue->expects(self::never())->method('enqueue');

        $this->observer(false, $queue)->execute($this->event(42));
    }

    public function testAFailureNeverEscapesIntoTheApprovalLoop(): void
    {
        $queue = $this->createMock(OnboardingQueue::class);
        $queue->method('enqueue')->willThrowException(new \RuntimeException('database gone'));

        $this->observer(true, $queue)->execute($this->event(42));
        $this->addToAssertionCount(1);
    }

    private function observer(bool $enabled, OnboardingQueue $queue): QueueSubmerchantOnApproval
    {
        $config = $this->createMock(OnboardingConfig::class);
        $config->method('isEnabled')->willReturn($enabled);
        return new QueueSubmerchantOnApproval($config, $queue, $this->createMock(LoggerInterface::class));
    }

    private function event(int $sellerId): Observer
    {
        $event = new Event(['seller' => new DataObject(['id' => $sellerId])]);
        return new Observer(['event' => $event]);
    }
}
