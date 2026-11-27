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
 * vendor segment on an already-split order it asks Shiprocket which of that seller's order items
 * are delivered (across ALL the seller's parcels) and stamps ONLY those items (delivered_at +
 * return window) so A5c's per-vendor eligibility can fire once every item is delivered. Stamping
 * per item — not the whole segment off one delivered parcel — is the F1 over-release fix.
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
                $deliveredQtyByItem = $this->deliveryStatusProvider->getDeliveredQtyByOrderItem(
                    $candidate->orderId,
                    $candidate->sellerId
                );
                if ($deliveredQtyByItem === []) {
                    continue;
                }

                // Stamp at DETECTION time (poll-now, GMT), not the Shiprocket payload timestamp:
                // poll-now is always >= the real delivery instant, so the return window can only
                // close LATE — never early (the customer-safe direction; a too-early window would
                // let A5c release the vendor before the customer can return). Only the delivered
                // quantities inside a delivered parcel are passed through (F1/F1-qty) — a seller's
                // still-undelivered items, and the undelivered units of a partially-shipped line, stay
                // ungated, so the segment cannot release until every line is fully delivered.
                $this->stamper->stampItems(
                    $candidate->segmentId,
                    $deliveredQtyByItem,
                    $this->dateTime->gmtDate()
                );
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: stamped delivery for %d line(s) of segment %d (order %d, seller %d).',
                    count($deliveredQtyByItem),
                    $candidate->segmentId,
                    $candidate->orderId,
                    $candidate->sellerId
                ));
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
