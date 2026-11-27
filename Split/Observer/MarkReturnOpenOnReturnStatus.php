<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Observer;

use RKD\PayuSplit\Split\Model\ResourceModel\ReturnRequestItems;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use RKD\PayuSplit\Split\Service\SegmentLoader;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Gate blocker #1, part (c) — the producer for the per-item RETURN_OPEN gate.
 *
 * SegmentEligibility already refuses to release a segment whose item is RETURN_OPEN, but nothing
 * ever wrote that value (SplitOrderManager seeds every item RETURN_NONE and leaves it). This
 * observer bridges Fab_Returns into the release gate: on fab_returns_status_changed it flips the
 * affected segment's items so a live return holds the vendor's escrow until the return resolves.
 *
 * Status → return_state:
 *   - submitted / under_review / approved → RETURN_OPEN (block). Approved still owes a customer
 *     refund that has not executed yet (returns-refund is deferred to Fab_Returns v2 / A5b returns
 *     scope), so it MUST stay blocking — better a recoverable strand than an over-pay.
 *   - rejected → RETURN_REJECTED (un-block). Nothing is owed, so the vendor must not be stranded.
 *
 * Decoupling: reads the request via duck-typed getters and its items by table name (ReturnRequestItems),
 * so RKD_PayuSplit carries no hard class dependency on Fab_Returns — the event only fires when Fab_Returns
 * is installed. No-op for non-PayU orders (no matching segment). Throwable-guarded so a failure here
 * never breaks the returns flow (the cancel/hold path and is_canceled gate remain the backstops).
 */
class MarkReturnOpenOnReturnStatus implements ObserverInterface
{
    /** Live return statuses that must block release (string-coupled to Fab\Returns\Model\ReturnStatus). */
    private const OPEN_STATUSES = ['submitted', 'under_review', 'approved'];
    /** Terminal-denied status that un-blocks release. */
    private const REJECTED_STATUS = 'rejected';

    /**
     * @param SegmentLoader $segmentLoader
     * @param ReturnRequestItems $itemReader
     * @param SplitSegmentItemResource $itemWriter
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SegmentLoader $segmentLoader,
        private readonly ReturnRequestItems $itemReader,
        private readonly SplitSegmentItemResource $itemWriter,
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
            $request = $observer->getEvent()->getData('return_request');
            if ($request === null) {
                return;
            }

            $returnState = $this->mapStatus((string) $observer->getEvent()->getData('to_status'));
            if ($returnState === null) {
                return;
            }

            $orderId = (int) $request->getOrderId();
            $sellerId = (int) $request->getSellerId();
            $requestId = (int) $request->getEntityId();

            $segment = $this->findVendorSegment($orderId, $sellerId);
            if ($segment === null) {
                // Not a PayU-split order (or no segment for this seller) — nothing to gate.
                return;
            }

            $orderItemIds = $this->itemReader->getOrderItemIds($requestId);
            if ($orderItemIds === []) {
                return;
            }

            $affected = $this->itemWriter->markReturnState((int) $segment->getId(), $orderItemIds, $returnState);

            $this->logger->info(sprintf(
                'RKD_PayuSplit: return %d (%s) set %d item(s) of segment %d to %s.',
                $requestId,
                $returnState,
                $affected,
                (int) $segment->getId(),
                $returnState
            ));
        } catch (\Throwable $t) {
            $this->logger->error(
                'RKD_PayuSplit: MarkReturnOpenOnReturnStatus failed — ' . $t->getMessage(),
                ['exception' => $t]
            );
        }
    }

    /**
     * @param string $toStatus
     * @return string|null The SplitSegmentItem::RETURN_* value, or null when the status is irrelevant
     */
    private function mapStatus(string $toStatus): ?string
    {
        if (in_array($toStatus, self::OPEN_STATUSES, true)) {
            return SplitSegmentItem::RETURN_OPEN;
        }
        if ($toStatus === self::REJECTED_STATUS) {
            return SplitSegmentItem::RETURN_REJECTED;
        }
        return null;
    }

    /**
     * @param int $orderId
     * @param int $sellerId
     * @return SplitSegment|null
     */
    private function findVendorSegment(int $orderId, int $sellerId): ?SplitSegment
    {
        foreach ($this->segmentLoader->loadByOrderId($orderId) as $segment) {
            if ($segment->getSegmentType() === SplitSegment::TYPE_VENDOR
                && (int) $segment->getSellerId() === $sellerId
            ) {
                return $segment;
            }
        }
        return null;
    }
}
