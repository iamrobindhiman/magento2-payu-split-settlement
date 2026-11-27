<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Test\Unit\Split\Cron;

use RKD\PayuSplit\Split\Cron\DeliveryPollScan;
use RKD\PayuSplit\Split\Model\DeliveryCandidate;
use RKD\PayuSplit\Split\Service\DeliveryCandidateLoader;
use RKD\PayuSplit\Split\Service\DeliverySegmentStamper;
use Fab\ShiprocketMarketplace\Api\DeliveryStatusProviderInterface;
use Fab\ShiprocketMarketplace\Model\Tracking\DeliveryStatus;
use Magento\Framework\Stdlib\DateTime\DateTime;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The delivery poll: for each undelivered vendor segment on a DONE split, ask Shiprocket if
 * its shipment is delivered and, if so, stamp it. The S-19 fix lives here — each candidate is
 * resolved independently, so one vendor that never delivers can NOT block another vendor's
 * segment from being stamped (and later released). A per-candidate try/catch isolates failures
 * so one bad shipment never stalls the batch.
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

    public function testDeliveredCandidateIsStampedAtPollNowNotPayloadDate(): void
    {
        // v1 conservatism: stamp at detection time (poll-now GMT), NOT the Shiprocket payload
        // timestamp (unverified tz/format). poll-now is always >= real delivery, so the return
        // window can only close LATE — never early (customer-safe). The payload date is only
        // logged for future calibration.
        $this->loader->method('loadCandidates')->willReturn([new DeliveryCandidate(9, 41, 18)]);
        $this->provider->method('getDeliveryStatus')->with(41, 18)
            ->willReturn(new DeliveryStatus(true, '2026-06-10 14:30:00', 'Delivered'));
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        $this->stamper->expects($this->once())->method('stamp')->with(9, '2026-06-15 09:00:00');

        $this->scan()->execute();
    }

    public function testUndeliveredSiblingDoesNotBlockDeliveredVendor(): void
    {
        // S-19: vendor A (seg 9) delivered, vendor B (seg 10) not delivered. Only A is stamped;
        // B staying undelivered must NOT prevent A advancing.
        $this->loader->method('loadCandidates')->willReturn([
            new DeliveryCandidate(9, 41, 18),
            new DeliveryCandidate(10, 41, 20),
        ]);
        $this->provider->method('getDeliveryStatus')->willReturnMap([
            [41, 18, new DeliveryStatus(true, '2026-06-10 14:30:00', 'Delivered')],
            [41, 20, new DeliveryStatus(false, null, 'In Transit')],
        ]);
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        $this->stamper->expects($this->once())->method('stamp')->with(9, '2026-06-15 09:00:00');

        $this->scan()->execute();
    }

    public function testNoTrackableShipmentIsSkipped(): void
    {
        $this->loader->method('loadCandidates')->willReturn([new DeliveryCandidate(9, 41, 18)]);
        $this->provider->method('getDeliveryStatus')->willReturn(null);

        $this->stamper->expects($this->never())->method('stamp');

        $this->scan()->execute();
    }

    public function testDeliveredWithoutPayloadDateStillStampsAtPollNow(): void
    {
        $this->loader->method('loadCandidates')->willReturn([new DeliveryCandidate(9, 41, 18)]);
        $this->provider->method('getDeliveryStatus')->willReturn(new DeliveryStatus(true, null, 'Delivered'));
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        $this->stamper->expects($this->once())->method('stamp')->with(9, '2026-06-15 09:00:00');

        $this->scan()->execute();
    }

    public function testPerCandidateFailureIsIsolated(): void
    {
        $this->loader->method('loadCandidates')->willReturn([
            new DeliveryCandidate(9, 41, 18),
            new DeliveryCandidate(10, 42, 20),
        ]);
        $this->provider->method('getDeliveryStatus')
            ->willReturn(new DeliveryStatus(true, '2026-06-10 14:30:00', 'Delivered'));
        $this->dateTime->method('gmtDate')->willReturn('2026-06-15 09:00:00');

        // First stamp throws; the batch must continue to the second.
        $this->stamper->expects($this->exactly(2))->method('stamp')
            ->willReturnOnConsecutiveCalls(
                $this->throwException(new \RuntimeException('boom')),
                null
            );
        $this->logger->expects($this->once())->method('error');

        $this->scan()->execute();
    }
}
