<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem\CollectionFactory as ItemCollectionFactory;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use Fab\Returns\Model\Config as ReturnsConfig;

/**
 * Stamps real delivery onto a vendor segment's item rows (A6): for each DELIVERED line (matched by
 * its sales_order_item id against the delivered-qty map the poll passes in) it accumulates the
 * delivered_qty, and once that reaches the line's ordered qty it records delivered_at and the
 * derived window_closes_at (= delivered_at + the configured return window). A partial-QUANTITY
 * shipment (ship 1 of qty 2) records the partial delivered_qty but leaves the line ungated — the
 * undelivered unit's escrow can never release off it (F1-qty). Lines NOT in a delivered shipment are
 * left undelivered, so a multi-parcel seller's segment becomes releasable only once every line has
 * been fully delivered (F1). delivered_qty accumulates via max() (a parcel can't un-deliver) → a
 * repeated poll is idempotent; delivered_at is WRITE-ONCE, so a re-poll after full delivery is a
 * no-op. After stamping it denormalises the segment's own delivered_at/window_closes_at to the max
 * across its items (set only once every item is fully delivered) for admin display / fast scans;
 * A5c's eligibility gate reads the item rows.
 *
 * All writes commit in one transaction. delivered_at/window math uses \DateTimeImmutable
 * (guard-safe — no date()/time()); money/time stays integer-paise-equivalent (exact seconds).
 */
class DeliverySegmentStamper
{
    /**
     * @param ItemCollectionFactory $itemCollectionFactory
     * @param SplitSegmentItemResource $itemResource
     * @param SegmentLoader $segmentLoader
     * @param SplitSegmentResource $segmentResource
     * @param ReturnsConfig $returnsConfig
     */
    public function __construct(
        private readonly ItemCollectionFactory $itemCollectionFactory,
        private readonly SplitSegmentItemResource $itemResource,
        private readonly SegmentLoader $segmentLoader,
        private readonly SplitSegmentResource $segmentResource,
        private readonly ReturnsConfig $returnsConfig
    ) {
    }

    /**
     * Accumulate delivered quantity onto ONLY the segment's item rows whose sales_order_item id is a
     * key in $deliveredQtyByOrderItem — the lines actually inside a delivered shipment (mapped to the
     * units delivered so far). A line is stamped delivered_at + window ONLY once its delivered_qty
     * reaches its ordered qty; a partial-QUANTITY delivery records the partial qty and leaves the line
     * ungated (F1-qty). A delivered parcel never touches a seller's other, still-undelivered lines
     * (F1). delivered_qty advances via max() (a parcel can't un-deliver); delivered_at is write-once,
     * so a repeated poll is idempotent.
     *
     * @param int $segmentId
     * @param array<int, int> $deliveredQtyByOrderItem sales_order_item id => units delivered so far
     * @param string $deliveredAtGmt 'Y-m-d H:i:s' delivery instant (GMT)
     * @return void
     */
    public function stampItems(int $segmentId, array $deliveredQtyByOrderItem, string $deliveredAtGmt): void
    {
        if ($deliveredQtyByOrderItem === []) {
            return;
        }

        $collection = $this->itemCollectionFactory->create();
        $collection->addFieldToFilter('segment_id', $segmentId);
        $items = $collection->getItems();
        if (!$items) {
            return;
        }

        $windowClosesAt = $this->windowClosesAt($deliveredAtGmt);

        $connection = $this->itemResource->getConnection();
        $connection->beginTransaction();
        try {
            $fullDeliveryOccurred = false;
            foreach ($items as $item) {
                /** @var SplitSegmentItem $item */
                if ($item->getDeliveredAt() !== null) {
                    continue; // write-once: a fully-delivered line is never re-touched
                }
                $orderItemId = (int) $item->getOrderItemId();
                if (!array_key_exists($orderItemId, $deliveredQtyByOrderItem)) {
                    continue; // not inside any delivered shipment — leave undelivered (F1)
                }

                // max() holds the high-water mark: a transient under-count from the provider can never
                // reduce a line's recorded delivered_qty (a delivered parcel can't un-deliver).
                $existingQty = $item->getDeliveredQty();
                $newQty = max($existingQty, (int) $deliveredQtyByOrderItem[$orderItemId]);
                $orderedQty = $item->getQty();
                $reachesFullQty = $orderedQty > 0 && $newQty >= $orderedQty;

                // Nothing to persist if the delivered qty didn't advance and the line isn't newly
                // complete — the idempotent re-poll / transient under-count path.
                if ($newQty === $existingQty && !$reachesFullQty) {
                    continue;
                }

                $item->setData('delivered_qty', $newQty);
                if ($reachesFullQty) {
                    // Full line delivered → stamp delivery + open the return window (write-once). The
                    // window starts at FULL-line delivery, never at the first partial parcel.
                    $item->setData('delivered_at', $deliveredAtGmt);
                    $item->setData('window_closes_at', $windowClosesAt);
                    $fullDeliveryOccurred = true;
                }
                $this->itemResource->save($item);
            }

            // The segment denorm only changes when a line becomes fully delivered; a partial-qty-only
            // update leaves it untouched (recompute early-returns while any item is undelivered anyway).
            if ($fullDeliveryOccurred) {
                $this->recomputeSegmentDerived($segmentId, $items);
            }

            $connection->commit();
        } catch (\Throwable $t) {
            $connection->rollBack();
            throw $t;
        }
    }

    /**
     * Denormalise the segment's delivered_at/window_closes_at to the max across its items,
     * but only once every item is delivered (a partially-delivered segment is not yet
     * "delivered"). Decorative — eligibility reads the item rows.
     *
     * @param int $segmentId
     * @param SplitSegmentItem[] $items
     * @return void
     */
    private function recomputeSegmentDerived(int $segmentId, array $items): void
    {
        $maxDelivered = null;
        $maxWindow = null;
        foreach ($items as $item) {
            $delivered = $item->getDeliveredAt();
            if ($delivered === null) {
                return; // not all items delivered yet → leave the segment underived
            }
            if ($maxDelivered === null || strcmp($delivered, $maxDelivered) > 0) {
                $maxDelivered = $delivered;
            }
            $window = $item->getWindowClosesAt();
            if ($window !== null && ($maxWindow === null || strcmp($window, $maxWindow) > 0)) {
                $maxWindow = $window;
            }
        }

        $segment = $this->segmentLoader->loadById($segmentId);
        if ($segment === null) {
            return;
        }
        $segment->setData('delivered_at', $maxDelivered);
        $segment->setData('window_closes_at', $maxWindow);
        $this->segmentResource->save($segment);
    }

    /**
     * @param string $deliveredAtGmt
     * @return string 'Y-m-d H:i:s'
     */
    private function windowClosesAt(string $deliveredAtGmt): string
    {
        // Floor at 1 day: even if returns are disabled (config = 0), hold the vendor's escrow at
        // least a day past delivery so a misconfigured/zero window can never green-light A5c to
        // release the instant delivery is detected. The configured return window governs above 1.
        $days = max(1, $this->returnsConfig->getReturnWindowDays());
        $delivered = new \DateTimeImmutable($deliveredAtGmt, new \DateTimeZone('UTC'));
        return $delivered->add(new \DateInterval('P' . $days . 'D'))->format('Y-m-d H:i:s');
    }
}
