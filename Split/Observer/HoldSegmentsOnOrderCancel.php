<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #1, part (b) — synchronous cancel hold on FULL order cancel.
 *
 * The release gate (A5c) is otherwise blind to cancellations: a cancel landing after a segment
 * is delivery-eligible would let a release cron tick fire and release the vendor's escrow, after
 * which the refund executor PERMANENTLY refuses the owed customer refund (over-pay + customer
 * strand). This observer slams the door at cancel TIME: it places a CANCELED hold on every
 * non-terminal vendor segment of the order, and both the release scan pre-filter (EligibleSegments)
 * and the ReleaseProcessor gate already exclude any held segment — so the segment is left for the
 * refund executor, not the release executor.
 *
 * Two guards, mirrored from Fab\OrderCancellation\Observer\OrderCancelAfter:
 *   1. Only act on a FULL cancel (state === canceled). Webkul fires order_cancel_after on partial
 *      seller cancels too (see lesson_webkul_event_dispatch_order); on a partial the other sellers'
 *      segments are NOT cancelled, so the per-seller HoldSegmentOnSellerCancel handles that case.
 *   2. Never break the cancel flow — the whole body is Throwable-guarded; a hold failure is logged
 *      and the cancel proceeds (the release scan's own is_canceled re-check is the backstop).
 */
class HoldSegmentsOnOrderCancel implements ObserverInterface
{
    /**
     * @param SegmentLoader $segmentLoader
     * @param SegmentHoldWriter $holdWriter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SegmentLoader $segmentLoader,
        private readonly SegmentHoldWriter $holdWriter,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer): void
    {
        try {
            $order = $observer->getEvent()->getData('order');
            if ($order === null) {
                return;
            }

            // Guard 1: act only on a full cancel; partial cancels are per-seller.
            if ($order->getData('state') !== Order::STATE_CANCELED) {
                return;
            }

            $orderId = (int) $order->getData('entity_id');
            if ($orderId <= 0) {
                return;
            }

            foreach ($this->segmentLoader->loadByOrderId($orderId) as $segment) {
                if ($segment->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
                    continue;
                }
                if (in_array($segment->getState(), SplitSegment::TERMINAL_STATES, true)) {
                    continue;
                }
                $this->holdWriter->place(
                    (int) $segment->getId(),
                    SplitHold::TYPE_CANCELED,
                    sprintf('Order %d fully cancelled', $orderId)
                );
            }
        } catch (\Throwable $t) {
            $this->logger->error(
                'RKD_PayuSplit: HoldSegmentsOnOrderCancel failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }
}
