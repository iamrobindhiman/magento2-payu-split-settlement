<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Model\SplitHold;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Service\SegmentHoldWriter;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #1, part (b) — synchronous cancel hold on a Webkul SELLER cancel.
 *
 * mp_order_cancel_after is Webkul's custom event and the only place the cancelling seller_id is
 * carried (see Fab\OrderCancellation\Observer\MpOrderCancelAfter). It fires on partial AND full
 * seller cancels. This observer holds the cancelling seller's vendor segment CANCELED so the
 * release gate (which excludes held segments) leaves it for the refund executor.
 *
 * A PayU vendor segment is one row per (order, seller), so a partial item cancel still holds the
 * whole segment — deliberately conservative (delays release, never over-pays): the refund executor
 * refunds the cancelled portion and any live items un-strand once the segment terminalises. The
 * body is Throwable-guarded so a hold failure never breaks Webkul's cancel flow (the release scan's
 * own is_canceled re-check is the backstop).
 */
class HoldSegmentOnSellerCancel implements ObserverInterface
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
            $event = $observer->getEvent();
            $order = $event->getData('order');
            $sellerId = (int) $event->getData('seller_id');
            if ($order === null || $sellerId <= 0) {
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
                if ((int) $segment->getSellerId() !== $sellerId) {
                    continue;
                }
                if (in_array($segment->getState(), SplitSegment::TERMINAL_STATES, true)) {
                    continue;
                }
                $this->holdWriter->place(
                    (int) $segment->getId(),
                    SplitHold::TYPE_CANCELED,
                    sprintf('Seller %d cancelled their portion of order %d', $sellerId, $orderId)
                );
            }
        } catch (\Throwable $t) {
            $this->logger->error(
                'RKD_PayuSplit: HoldSegmentOnSellerCancel failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }
}
