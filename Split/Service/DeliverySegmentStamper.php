<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem as SplitSegmentItemResource;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegmentItem\CollectionFactory as ItemCollectionFactory;
use RKD\PayuSplit\Split\Model\SplitSegmentItem;
use Fab\Returns\Model\Config as ReturnsConfig;

/**
 * Stamps real delivery onto a vendor segment's item rows (A6): for each not-yet-delivered
 * item it records delivered_at and the derived window_closes_at (= delivered_at + the
 * configured return window). WRITE-ONCE — an item already stamped is never overwritten, so a
 * repeated poll is a no-op. After stamping it denormalises the segment's own
 * delivered_at/window_closes_at to the max across its items (set only once every item is
 * delivered) for admin display / fast scans; A5c's eligibility gate reads the item rows.
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
     * @param int $segmentId
     * @param string $deliveredAtGmt 'Y-m-d H:i:s' delivery instant (GMT)
     * @return void
     */
    public function stamp(int $segmentId, string $deliveredAtGmt): void
    {
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
            $stampedAny = false;
            foreach ($items as $item) {
                /** @var SplitSegmentItem $item */
                if ($item->getDeliveredAt() !== null) {
                    continue; // write-once: never overwrite an already-stamped delivery
                }
                $item->setData('delivered_at', $deliveredAtGmt);
                $item->setData('window_closes_at', $windowClosesAt);
                $this->itemResource->save($item);
                $stampedAny = true;
            }

            if ($stampedAny) {
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
