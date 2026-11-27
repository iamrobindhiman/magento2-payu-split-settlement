<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Exception\PayuSplitRequestException;
use RKD\PayuSplit\Split\Gateway\PayuSplitClient;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SegmentEligibility;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;

/**
 * The release keystone (A5c-2). For one eligible held segment it writes a RELEASE_REQUESTED
 * marker AHEAD of firing release_settlement (crash-safety), then fires exactly one release
 * request to PayU. It deliberately stops at RELEASE_REQUESTED: PayU settlement is async (T+1)
 * and the sync response is ambiguous (status:0 means both success and a generic error per
 * E-07), so RELEASED is only ever reached by the later readback recon (A5c-5) that confirms
 * the settled amount (INV-18). Idempotency rests on PayU's own "already accepted" — a re-fire
 * for an (mihpayid, childMid) already released is a benign no-op.
 *
 * Gates (refuse to even request a release):
 *  - vendor: SegmentEligibility — every item delivered + past its window, no open return, no
 *    active hold, and NO non-FAILED refund leg on the segment (INV-19: a PENDING/QUEUED/HELD
 *    refund blocks release);
 *  - parent commission (seller_id 0): releases LAST (INV-14) — only once EVERY vendor segment
 *    of the order is terminal, and the parent itself carries no hold and no in-flight refund.
 */
class ReleaseProcessor
{
    /**
     * @param PayuSplitClient $gateway
     * @param SplitOrderRepositoryInterface $repository
     * @param SegmentEligibility $eligibility
     * @param SegmentItemLoader $itemLoader
     * @param SegmentHoldLoader $holdLoader
     * @param RefundLedger $refundLedger
     * @param SegmentLoader $segmentLoader
     * @param SplitSegmentResource $segmentResource
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly PayuSplitClient $gateway,
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly SegmentEligibility $eligibility,
        private readonly SegmentItemLoader $itemLoader,
        private readonly SegmentHoldLoader $holdLoader,
        private readonly RefundLedger $refundLedger,
        private readonly SegmentLoader $segmentLoader,
        private readonly SplitSegmentResource $segmentResource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param SplitSegment $segment
     * @return void
     */
    public function release(SplitSegment $segment): void
    {
        $segmentId = (int) $segment->getId();

        // Idempotency: a segment already requested/released is never re-fired by this scan
        // (the recon path owns RELEASE_REQUESTED → RELEASED).
        if (in_array($segment->getState(), [SplitSegment::STATE_RELEASE_REQUESTED, SplitSegment::STATE_RELEASED], true)) {
            return;
        }

        $header = $this->repository->getByOrderId($segment->getOrderId());
        $mihpayid = $header !== null ? (string) $header->getMihpayid() : '';
        if ($mihpayid === '') {
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: cannot release segment %d — order %d has no mihpayid yet.',
                $segmentId,
                $segment->getOrderId()
            ));
            return;
        }

        $childMid = $segment->getChildMid();
        if ($childMid === null) {
            // An unmapped/missing child MID means we cannot target the release. Surface it.
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: cannot release segment %d (order %d, seller %d) — no child MID mapped.',
                $segmentId,
                $segment->getOrderId(),
                $segment->getSellerId()
            ));
            return;
        }

        if (!$this->isReleasable($segment)) {
            return;
        }

        // Write-ahead AS AN ATOMIC CLAIM (CAS): flip ELIGIBLE→RELEASE_REQUESTED + bump attempts
        // in one conditional UPDATE so two overlapping cron ticks cannot both fire a release for
        // the same segment (release_settlement has no amount param — it releases the whole held
        // balance, so a double-fire is a double-release; we must not rely on PayU's unverified
        // concurrent dedup alone). 0 rows affected ⇒ another run already claimed it ⇒ bail before
        // firing. This also persists the intent BEFORE the gateway call (crash-recovery: a crash
        // mid-call leaves a recoverable RELEASE_REQUESTED record the recon readback resolves).
        $connection = $this->segmentResource->getConnection();
        $claimed = (int) $connection->update(
            $this->segmentResource->getMainTable(),
            [
                'state' => SplitSegment::STATE_RELEASE_REQUESTED,
                // Strand-age anchor for the A5c-5 recon: the instant we requested the release,
                // so a segment that never reconciles to RELEASED can be alarmed by age.
                'release_requested_at' => $this->dateTime->gmtDate(),
                'release_attempts' => new Expression('release_attempts + 1'),
            ],
            [
                'segment_id = ?' => $segmentId,
                'state NOT IN (?)' => [SplitSegment::STATE_RELEASE_REQUESTED, SplitSegment::STATE_RELEASED],
            ]
        );
        if ($claimed === 0) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d already claimed for release by a concurrent run — not re-firing.',
                $segmentId
            ));
            return;
        }
        // Reflect the claimed state on the in-memory model for callers/logging.
        $segment->setState(SplitSegment::STATE_RELEASE_REQUESTED);

        try {
            $response = $this->gateway->releaseSettlement($mihpayid, $childMid);
            if ($response->isAlreadyReleased()) {
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: release for segment %d already accepted by PayU (idempotent).',
                    $segmentId
                ));
            } else {
                // Sync response is not authoritative (async settlement); the recon readback
                // (INV-18) promotes RELEASE_REQUESTED → RELEASED once the amount actually settles.
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: release requested for segment %d (mid %s); awaiting settlement readback.',
                    $segmentId,
                    $childMid
                ));
            }
        } catch (PayuSplitRequestException $e) {
            // Transport failure — the write-ahead RELEASE_REQUESTED is the durable record; the
            // recon path (A5c-5) must re-fire (PayU dedups an already-accepted release). Until
            // recon exists, a transport-failed release is a STRANDED-requested segment the main
            // scan won't re-select (RELEASE_REQUESTED is excluded) — log CRITICAL so it is
            // queryable/alarmable, not buried at error level. Never rethrow: the scan isolates
            // per segment regardless.
            $this->logger->critical(sprintf(
                'RKD_PayuSplit: release transport error for segment %d — left RELEASE_REQUESTED, needs recon re-fire — %s',
                $segmentId,
                $e->getMessage()
            ));
        }
    }

    /**
     * @param SplitSegment $segment
     * @return bool
     */
    private function isReleasable(SplitSegment $segment): bool
    {
        $segmentId = (int) $segment->getId();

        if ($segment->getSegmentType() === SplitSegment::TYPE_COMMISSION) {
            return $this->isParentReleasable($segment);
        }

        $gates = [];
        foreach ($this->itemLoader->loadItems($segmentId) as $item) {
            $gates[] = $item->toGate();
        }
        $result = $this->eligibility->evaluate(
            $segment->getState(),
            $gates,
            $this->holdLoader->holdTypes($segmentId),
            $this->refundLedger->activeLegs($segmentId) !== [],
            $this->dateTime->gmtDate()
        );
        if (!$result->eligible) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: segment %d not yet releasable — %s.',
                $segmentId,
                (string) $result->reason
            ));
            return false;
        }
        return true;
    }

    /**
     * Parent-last (INV-14): the commission segment releases only once EVERY vendor segment of
     * the order is terminal, and the parent itself is unheld and free of an in-flight refund.
     *
     * @param SplitSegment $parent
     * @return bool
     */
    private function isParentReleasable(SplitSegment $parent): bool
    {
        $segmentId = (int) $parent->getId();

        if ($this->holdLoader->holdTypes($segmentId) !== []) {
            $this->logger->info(sprintf('RKD_PayuSplit: parent segment %d is held — release deferred.', $segmentId));
            return false;
        }
        if ($this->refundLedger->activeLegs($segmentId) !== []) {
            $this->logger->info(sprintf(
                'RKD_PayuSplit: parent segment %d has an in-flight commission refund — release deferred (INV-19).',
                $segmentId
            ));
            return false;
        }

        foreach ($this->segmentLoader->loadByOrderId($parent->getOrderId()) as $sibling) {
            if ($sibling->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
                continue;
            }
            if (!in_array($sibling->getState(), SplitSegment::TERMINAL_STATES, true)) {
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: parent segment %d waits — vendor segment %d is %s (not terminal). INV-14.',
                    $segmentId,
                    (int) $sibling->getId(),
                    $sibling->getState()
                ));
                return false;
            }
        }
        return true;
    }
}
