<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\RefundCandidate;
use RKD\PayuSplit\Split\Model\ResourceModel\RefundableSegments;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitRefund;
use RKD\PayuSplit\Split\Model\SplitSegmentFactory;

/**
 * Loads the refund scan's work-list: hydrates each refundable segment row into a
 * SplitSegment model and pairs it with the refund reason derived from the cancel actor.
 * A thin, guard-compliant wrapper over the refundable-segments query (smoke-covered via
 * the live refund test).
 */
class RefundCandidateLoader
{
    /**
     * @param RefundableSegments $refundableSegments
     * @param SplitSegmentFactory $segmentFactory
     * @param SplitSegmentResource $segmentResource
     */
    public function __construct(
        private readonly RefundableSegments $refundableSegments,
        private readonly SplitSegmentFactory $segmentFactory,
        private readonly SplitSegmentResource $segmentResource
    ) {
    }

    /**
     * @return RefundCandidate[]
     */
    public function loadCandidates(): array
    {
        $candidates = [];
        foreach ($this->refundableSegments->getRefundableRows() as $row) {
            $segment = $this->segmentFactory->create();
            $this->segmentResource->load($segment, (int) $row['segment_id']);
            if ((int) $segment->getId() === 0) {
                continue;
            }
            $candidates[] = new RefundCandidate(
                $segment,
                $this->mapReason($row['cancelled_by_type'] ?? null)
            );
        }
        return $candidates;
    }

    /**
     * Map the Webkul cancel actor onto a refund reason. A seller-initiated cancel is a
     * vendor cancel; everything else (customer / admin / system) refunds the customer the
     * same way, so it carries the customer-cancel reason.
     *
     * @param string|null $cancelledByType
     * @return string
     */
    private function mapReason(?string $cancelledByType): string
    {
        return $cancelledByType === 'seller'
            ? SplitRefund::REASON_VENDOR_CANCEL
            : SplitRefund::REASON_CUSTOMER_CANCEL;
    }
}
