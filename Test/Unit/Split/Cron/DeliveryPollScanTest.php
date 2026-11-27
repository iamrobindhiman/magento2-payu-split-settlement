<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\DeliveryPollScan;
use RKD\PayuSplit\Split\Model\DeliveryCandidate;
use RKD\PayuSplit\Split\Service\DeliveryCandidateLoader;
use RKD\PayuSplit\Split\Service\DeliverySegmentStamper;
use Fab\ShiprocketMarketplace\Api\DeliveryStatusProviderInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The delivery poll: for each undelivered vendor segment on a DONE split, ask Shiprocket which of
 * that seller's order items are delivered (across all its parcels) and stamp ONLY those. The S-19
 * fix lives here — each candidate is resolved independently, so one vendor that never delivers can
 * NOT block another vendor's segment. Stamping per delivered item (not the whole segment off one
 * delivered parcel) is the F1 over-release fix. A per-candidate try/catch isolates failures.
 */
class DeliveryPollScanTest extends TestCase
{
    private $loader;
    private $provider;
    private $stamper;
    private $dateTime;
    private $logger;

    protected function setUp(): void
    {
        $this->loader = $this->createMock(DeliveryCandidateLoader::class);
        $this->provider = $this->createMock(DeliveryStatusProviderInterface::class);
        $this->stamper = $this->createMock(DeliverySegmentStamper::class);
        $this->dateTime = $this->createMock(DateTime::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function scan(): DeliveryPollScan
    {
        return new DeliveryPollScan(
            $this->loader,
            $this->provider,
            $this->stamper,
            $this->dateTime,
            $this->logger
        );
    }

    public function testDeliveredItemsAreStampedAtPollNowNotPayloadDate(): void
    {
        // Stamp at detection time (poll-now GMT) — poll-now >= real delivery, so the return window
        // can only close LATE (customer-safe). Only the delivered quantities are passed through.
        $this->loader->method('loadCandidates')->willReturn([new DeliveryCandidate(9, 41, 18)]);
        $this->provider->method('getDeliveredQtyByOrderItem')->with(41, 18)->willReturn([52 => 1, 53 => 2]);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        $this->stamper->expects($this->once())->method('stampItems')
            ->with(9, [52 => 1, 53 => 2], '2026-06-15 09:00:00');

        $this->scan()->execute();
    }

    public function testUndeliveredSiblingDoesNotBlockDeliveredVendor(): void
    {
        // S-19: vendor A (seg 9) has a delivered item, vendor B (seg 10) has none delivered. Only A
        // is stamped; B staying undelivered must NOT prevent A advancing.
        $this->loader->method('loadCandidates')->willReturn([
            new DeliveryCandidate(9, 41, 18),
            new DeliveryCandidate(10, 41, 20),
        ]);
        $this->provider->method('getDeliveredQtyByOrderItem')->willReturnMap([
            [41, 18, [52 => 1]],
            [41, 20, []],
        ]);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        $this->stamper->expects($this->once())->method('stampItems')->with(9, [52 => 1], '2026-06-15 09:00:00');

        $this->scan()->execute();
    }

    public function testNoDeliveredItemsIsSkipped(): void
    {
        $this->loader->method('loadCandidates')->willReturn([new DeliveryCandidate(9, 41, 18)]);
        $this->provider->method('getDeliveredQtyByOrderItem')->willReturn([]);

        $this->stamper->expects($this->never())->method('stampItems');

        $this->scan()->execute();
    }

    public function testPerCandidateFailureIsIsolated(): void
    {
        $this->loader->method('loadCandidates')->willReturn([
            new DeliveryCandidate(9, 41, 18),
            new DeliveryCandidate(10, 42, 20),
        ]);
        $this->provider->method('getDeliveredQtyByOrderItem')->willReturn([52 => 1]);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        // First stamp throws; the batch must continue to the second.
        $this->stamper->expects($this->exactly(2))->method('stampItems')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('boom')),
                null
            );
        $this->logger->expects($this->once())->method('error');

        $this->scan()->execute();
    }
}
