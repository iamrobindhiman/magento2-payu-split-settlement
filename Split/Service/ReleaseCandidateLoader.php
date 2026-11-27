<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\EligibleSegments;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use RKD\PayuSplit\Split\Model\SplitSegmentFactory;

/**
 * Loads the release scan's work-list: hydrates each coarse-eligible segment row into a
 * SplitSegment model for the release processor. BOTH vendor segments (delivery-gated) and parent
 * commission segments (parent-last, gated in the processor) are returned as one list — the
 * processor decides each. A row whose segment no longer loads (deleted underneath the scan) is
 * skipped. A thin, guard-compliant wrapper over the eligible-segments queries.
 */
class ReleaseCandidateLoader
{
    /**
     * @param EligibleSegments $eligibleSegments
     * @param SplitSegmentFactory $segmentFactory
     * @param SplitSegmentResource $segmentResource
     */
    public function __construct(
        private readonly EligibleSegments $eligibleSegments,
        private readonly SplitSegmentFactory $segmentFactory,
        private readonly SplitSegmentResource $segmentResource
    ) {
    }

    /**
     * @return SplitSegment[] Vendor + parent commission release candidates
     */
    public function loadCandidates(): array
    {
        $rows = array_merge(
            $this->eligibleSegments->getReleasableVendorRows(),
            $this->eligibleSegments->getReleasableParentRows()
        );

        $candidates = [];
        foreach ($rows as $row) {
            $segment = $this->segmentFactory->create();
            $this->segmentResource->load($segment, (int) $row['segment_id']);
            if ((int) $segment->getId() === 0) {
                continue;
            }
            $candidates[] = $segment;
        }
        return $candidates;
    }
}
