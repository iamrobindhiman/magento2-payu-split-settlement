<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Cron;

use RKD\PayuSplit\Split\Service\DeliveryCandidateLoader;
use RKD\PayuSplit\Split\Service\DeliverySegmentStamper;
use Fab\ShiprocketMarketplace\Api\DeliveryStatusProviderInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * The third settlement scan (matrix §5, A6): per-vendor delivery poll. For each undelivered
 * vendor segment on an already-split order it asks Shiprocket whether that seller's shipment
 * is delivered and, if so, stamps the segment's items (delivered_at + return window) so A5c's
 * per-vendor eligibility can fire.
 *
 * Per-vendor independence is the S-19 fix: each candidate is resolved on its own, so one
 * vendor that never delivers (RTO/lost) can't deadlock another vendor's release. Each
 * candidate runs in its own try/catch — a Shiprocket hiccup on one shipment never stalls the
 * batch, and delivery stamping is write-once so the next run is a safe retry.
 */
class DeliveryPollScan
{
    /**
     * @param DeliveryCandidateLoader $loader
     * @param DeliveryStatusProviderInterface $deliveryStatusProvider
     * @param DeliverySegmentStamper $stamper
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly DeliveryCandidateLoader $loader,
        private readonly DeliveryStatusProviderInterface $deliveryStatusProvider,
        private readonly DeliverySegmentStamper $stamper,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->loader->loadCandidates() as $candidate) {
            try {
                $status = $this->deliveryStatusProvider->getDeliveryStatus(
                    $candidate->orderId,
                    $candidate->sellerId
                );
                if ($status === null || !$status->isDelivered()) {
                    continue;
                }

                // v1 conservatism: stamp at DETECTION time (poll-now, GMT), not the Shiprocket
                // payload timestamp. poll-now is always >= the real delivery instant, so the
                // return window can only close LATE — never early (the customer-safe direction;
                // a too-early window would let A5c release the vendor before the customer can
                // return). The payload's reported delivery date is of unverified timezone/format
                // (no real delivered response validated yet), so we LOG it for calibration rather
                // than trust it for the money-gating window. Switch to the payload timestamp only
                // once it is validated against a real Shiprocket delivered response.
                $payloadDate = $status->getDeliveredAt();
                if ($payloadDate !== null) {
                    $this->logger->info(sprintf(
                        'RKD_PayuSplit: delivery detected for segment %d (order %d, seller %d); '
                        . 'Shiprocket reported "%s", stamping at poll-now (calibration).',
                        $candidate->segmentId,
                        $candidate->orderId,
                        $candidate->sellerId,
                        $payloadDate
                    ));
                }

                $this->stamper->stamp($candidate->segmentId, $this->dateTime->gmtDate());
            } catch (\Throwable $t) {
                $this->logger->error(
                    sprintf(
                        'RKD_PayuSplit: delivery poll error for segment %d (order %d, seller %d) — %s',
                        $candidate->segmentId,
                        $candidate->orderId,
                        $candidate->sellerId,
                        $t->getMessage()
                    ),
                    ['exception' => $t]
                );
            }
        }
    }
}
