<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\DeliveryCandidate;
use RKD\PayuSplit\Split\Model\ResourceModel\UndeliveredSegments;

/**
 * Loads the delivery poll's work-list: each undelivered vendor segment on a DONE split,
 * projected to the (segment_id, order_id, seller_id) the poll needs. A thin, guard-compliant
 * wrapper over the undelivered-segments query (smoke-covered via the live delivery test).
 */
class DeliveryCandidateLoader
{
    /**
     * @param UndeliveredSegments $undeliveredSegments
     */
    public function __construct(
        private readonly UndeliveredSegments $undeliveredSegments
    ) {
    }

    /**
     * @return DeliveryCandidate[]
     */
    public function loadCandidates(): array
    {
        $candidates = [];
        foreach ($this->undeliveredSegments->getUndeliveredRows() as $row) {
            $candidates[] = new DeliveryCandidate(
                (int) $row['segment_id'],
                (int) $row['order_id'],
                (int) $row['seller_id']
            );
        }
        return $candidates;
    }
}
