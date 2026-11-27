<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\WebkulRefundCloseReconScan;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\UnsyncedRefundedLoader;
use RKD\PayuSplit\Split\Service\WebkulRefundCloser;
use Magento\Framework\TestFramework\Unit\Helper\ObjectManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The Webkul refund-close reconcile scan (gate blocker #4): the self-heal backstop for the
 * rkd_payu_segment_refunded event. Re-drives the close over every REFUNDED-but-unclosed vendor
 * segment with per-segment isolation. Unlike the release-path recon it is NOT gated by
 * release_enabled — refunds (and so their ledger close) happen pre-release, exactly like the refund
 * executor scan. Tests pin: every unsynced segment is closed; a throwing segment is logged and the
 * batch continues.
 */
class WebkulRefundCloseReconScanTest extends TestCase
{
    private ObjectManager $om;

    protected function setUp(): void
    {
        $this->om = new ObjectManager($this);
    }

    private function segment(int $id): SplitSegment
    {
        $segment = $this->om->getObject(SplitSegment::class);
        $segment->setData(['segment_id' => $id, 'order_id' => 40 + $id]);
        $segment->setId($id);
        return $segment;
    }

    public function testClosesEachUnsyncedSegment(): void
    {
        $loader = $this->createMock(UnsyncedRefundedLoader::class);
        $loader->method('loadUnsynced')->willReturn([$this->segment(1), $this->segment(2)]);
        $closer = $this->createMock(WebkulRefundCloser::class);

        $closer->expects($this->exactly(2))->method('closeRefunded');

        (new WebkulRefundCloseReconScan($loader, $closer, $this->createMock(LoggerInterface::class)))->execute();
    }

    public function testOneThrowingSegmentDoesNotStallTheBatch(): void
    {
        $loader = $this->createMock(UnsyncedRefundedLoader::class);
        $loader->method('loadUnsynced')->willReturn([$this->segment(1), $this->segment(2)]);

        $closer = $this->createMock(WebkulRefundCloser::class);
        $seen = [];
        $closer->method('closeRefunded')->willReturnCallback(function (SplitSegment $s) use (&$seen) {
            $seen[] = (int) $s->getId();
            if ((int) $s->getId() === 1) {
                throw new \RuntimeException('webkul down');
            }
        });
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('error')
            ->with($this->stringContains('Webkul refund-close reconcile error'));

        (new WebkulRefundCloseReconScan($loader, $closer, $logger))->execute();

        $this->assertSame([1, 2], $seen);
    }
}
