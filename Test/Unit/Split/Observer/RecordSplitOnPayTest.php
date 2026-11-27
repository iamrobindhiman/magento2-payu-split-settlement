<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Observer;

use RKD\PayuSplit\Split\Observer\RecordSplitOnPay;
use RKD\PayuSplit\Split\Service\SplitOrderManager;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The record-on-pay observer: records for PayU orders, ignores other methods and
 * empty payments, and — critically — never lets a recording failure break the
 * order flow (the manager throwing is swallowed and logged).
 */
class RecordSplitOnPayTest extends TestCase
{
    private $manager;
    private $logger;

    protected function setUp(): void
    {
        $this->manager = $this->createMock(SplitOrderManager::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function observerWith(?Payment $payment): Observer
    {
        $event = $this->createMock(Event::class);
        $event->method('getData')->with('payment')->willReturn($payment);
        $observer = $this->createMock(Observer::class);
        $observer->method('getEvent')->willReturn($event);
        return $observer;
    }

    private function payment(string $method, ?Order $order): Payment
    {
        $payment = $this->createMock(Payment::class);
        $payment->method('getMethod')->willReturn($method);
        $payment->method('getOrder')->willReturn($order);
        return $payment;
    }

    private function subject(): RecordSplitOnPay
    {
        return new RecordSplitOnPay($this->manager, $this->logger);
    }

    public function testRecordsForPayuOrder(): void
    {
        $order = $this->createMock(Order::class);
        $this->manager->expects($this->once())->method('recordPlan')->with($order);

        $this->subject()->execute($this->observerWith($this->payment('payu', $order)));
    }

    public function testIgnoresNonPayuMethod(): void
    {
        $order = $this->createMock(Order::class);
        $this->manager->expects($this->never())->method('recordPlan');

        $this->subject()->execute($this->observerWith($this->payment('checkmo', $order)));
    }

    public function testNoOpWhenNoPayment(): void
    {
        $this->manager->expects($this->never())->method('recordPlan');

        $this->subject()->execute($this->observerWith(null));
    }

    public function testSwallowsManagerExceptionAndLogs(): void
    {
        $order = $this->createMock(Order::class);
        $this->manager->method('recordPlan')->willThrowException(new \RuntimeException('db down'));
        $this->logger->expects($this->once())->method('error');

        // Must not throw — the order flow continues even if recording fails.
        $this->subject()->execute($this->observerWith($this->payment('payu', $order)));
    }
}
