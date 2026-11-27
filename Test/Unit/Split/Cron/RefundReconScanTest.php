<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\RefundReconScan;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Service\RefundLegLoader;
use RKD\PayuSplit\Split\Service\RefundReconciler;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The refund-status reconciliation scan (gate item #6). Ungated (refunds run pre-release, like the
 * refund executor scan): it loads the pollable refund legs and hands each to the RefundReconciler.
 * Per-leg try/catch isolation — one failing leg never stalls the batch.
 */
class RefundReconScanTest extends TestCase
{
    private function leg(int $id): SplitRefund
    {
        $om = new ObjectManager($this);
        /** @var SplitRefund $leg */
        $leg = $om->getObject(SplitRefund::class);
        $leg->setData(['order_id' => 42]);
        $leg->setId($id);
        return $leg;
    }

    public function testReconcilesEachLeg(): void
    {
        $legs = [$this->leg(5), $this->leg(6)];
        $loader = $this->createMock(RefundLegLoader::class);
        $loader->method('loadPollable')->willReturn($legs);
        $reconciler = $this->createMock(RefundReconciler::class);
        $reconciler->expects($this->exactly(2))->method('reconcile');

        (new RefundReconScan($loader, $reconciler, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testOneLegThrowingDoesNotStallTheBatch(): void
    {
        $legs = [$this->leg(5), $this->leg(6)];
        $loader = $this->createMock(RefundLegLoader::class);
        $loader->method('loadPollable')->willReturn($legs);

        $reconciler = $this->createMock(RefundReconciler::class);
        $seen = [];
        $reconciler->method('reconcile')->willReturnCallback(function (SplitRefund $leg) use (&$seen) {
            $seen[] = (int) $leg->getId();
            if ((int) $leg->getId() === 5) {
                throw new \RuntimeException('gateway hiccup');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error');

        (new RefundReconScan($loader, $reconciler, $logger))->execute();

        // Both legs were attempted despite the first throwing.
        $this->assertSame([5, 6], $seen);
    }
}
