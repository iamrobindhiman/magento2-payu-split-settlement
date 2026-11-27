<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Model\ResourceModel\Saleslist as SaleslistResource;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory as SaleslistCollectionFactory;
use Webkul\Marketplace\Model\Saleslist;

/**
 * Gate blocker #4 — closes a PayU-refunded vendor segment in Webkul's seller ledger.
 *
 * When the A5b refund executor refunds the customer (var8) for a cancelled vendor segment, the
 * vendor is owed nothing — yet Webkul's own cancel path leaves that vendor's payable saleslist rows
 * untouched (Controller\Order\Cancel::updateSellerOrderStatus only closes cpprostatus != 1 rows, so
 * an invoiced COMPLETE row stays paid_status=0 = payable). Webkul's native "Pay Seller" would then
 * pay a vendor whose customer was refunded — an over-pay. The A5c-4b suppressor guards the manual
 * payout at runtime; this is the durable source-of-truth correction the matrix mandates alongside it:
 * flip those payable rows to REFUNDED so they leave Webkul's payable set permanently.
 *
 * This is the mirror image of WebkulSettlementSync (A5c-4a) but it NEVER pays: no sellertransaction
 * payout-log row, no amount_received credit, no amount_remain change (the paid_status flip is the
 * necessary-and-sufficient over-pay guard — Webkul's payout reads saleslist, not the per-seller
 * running balance; leaving amount_remain avoids a double-decrement against Webkul's own cancel
 * observer and can never cause a payment, since the rows are now non-payable). The only ledger write
 * is the saleslist close, all wrapped in ONE transaction with the idempotency claim.
 *
 * Idempotency + concurrency: a webkul_synced_at CAS claim (the SAME marker the RELEASED mirror uses —
 * a segment is either RELEASED-mirrored or REFUNDED-closed, never both, so one marker is safe). A
 * re-fired event or a reconcile re-run is a safe no-op. A failed close rolls back and leaves the
 * segment unsynced for the reconcile scan to retry.
 *
 * SCOPE: the A5b cancellation path (whole-seller cancellations) drives this — every refunded segment
 * here corresponds to a fully-cancelled seller, so closing ALL the seller's payable rows is correct.
 * v2 partial returns (a PARTIAL_REFUNDED that leaves some items still shipped/payable) MUST refine
 * this to a per-item close rather than reusing the whole-seller close.
 */
class WebkulRefundCloser
{
    /**
     * Webkul saleslist status values. Webkul's Saleslist::PAID_STATUS_* are STRING literals
     * ('0'/'1'/'3'); they flow only into addFieldToFilter (a string-safe SQL WHERE, which MySQL
     * coerces against the int column) and setPaidStatus (which persists the same value Webkul does)
     * — never into a strict int comparison. Mirrors Webkul's own payout guard.
     */
    private const SALESLIST_PENDING = Saleslist::PAID_STATUS_PENDING;
    private const SALESLIST_COMPLETE = Saleslist::PAID_STATUS_COMPLETE;
    private const SALESLIST_REFUNDED = Saleslist::PAID_STATUS_REFUNDED;

    /**
     * @param SaleslistCollectionFactory $saleslistCollectionFactory
     * @param SaleslistResource $saleslistResource
     * @param SplitSegmentResource $segmentResource
     * @param DateTime $dateTime
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SaleslistCollectionFactory $saleslistCollectionFactory,
        private readonly SaleslistResource $saleslistResource,
        private readonly SplitSegmentResource $segmentResource,
        private readonly DateTime $dateTime,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Close a refunded vendor segment in Webkul's ledger (flip its payable saleslist rows
     * non-payable). No-op for anything that is not an un-closed, refunded vendor segment.
     *
     * @param SplitSegment $segment
     * @return void
     */
    public function closeRefunded(SplitSegment $segment): void
    {
        $segmentId = (int) $segment->getId();

        // The marketplace parent (commission) has no Webkul seller ledger and is never in the
        // reconcile work-list (vendor-only) — never close it (defensive; nothing dispatches it).
        // Key on segment_type ONLY (how the parent is identified everywhere else): a seller_id-based
        // guard would silently skip — and, because this early return does not claim the marker,
        // permanently re-loop on the recon scan — any vendor segment whose seller_id were 0.
        if ($segment->getSegmentType() !== SplitSegment::TYPE_VENDOR) {
            return;
        }
        // Scope: only a segment the refund executor has terminalised. PARTIAL_REFUNDED is included —
        // in the cancellation scope it is a fully-cancelled vendor whose customer was refunded the
        // discounted paid amount, so the vendor is still owed nothing.
        if (!in_array(
            $segment->getState(),
            [SplitSegment::STATE_REFUNDED, SplitSegment::STATE_PARTIAL_REFUNDED],
            true
        )) {
            return;
        }
        // Idempotency: already closed (or mirrored) — a re-fired event / reconcile re-run is a no-op.
        if ($segment->getWebkulSyncedAt() !== null) {
            return;
        }

        $now = $this->dateTime->gmtDate();
        $connection = $this->saleslistResource->getConnection();
        $orderId = $segment->getOrderId();
        $sellerId = $segment->getSellerId();

        $connection->beginTransaction();
        try {
            // CAS claim FIRST, inside the transaction: stamp webkul_synced_at only while still NULL.
            // This serialises a concurrent close (the refund event racing the reconcile scan, or two
            // reconcile ticks). Because the claim shares the transaction with the saleslist writes, a
            // rollback unstamps it, so a failed close is retried, never stranded synced-but-open.
            if (!$this->claimSync($connection, $segmentId, $now)) {
                $connection->rollBack();
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: refunded segment %d already claimed for Webkul close by a concurrent run — skipping.',
                    $segmentId
                ));
                return;
            }
            $closedRowIds = $this->closeSaleslistRows($orderId, $sellerId);
            $connection->commit();
            $segment->setData('webkul_synced_at', $now);
        } catch (\Throwable $t) {
            $connection->rollBack();
            // Leave the segment unsynced — the reconcile scan retries it. A failed close never leaves
            // a partial ledger (the whole transaction rolled back).
            $this->logger->error(
                sprintf(
                    'RKD_PayuSplit: Webkul ledger close failed for refunded segment %d (order %d, seller %d) — '
                    . 'rolled back, left unsynced for reconcile — %s',
                    $segmentId,
                    $orderId,
                    $sellerId,
                    $t->getMessage()
                ),
                ['exception' => $t]
            );
            return;
        }

        $this->logger->info(sprintf(
            'RKD_PayuSplit: closed refunded segment %d in Webkul ledger — seller %d, order %d, %d payable row(s) '
            . 'marked non-payable (no payout).',
            $segmentId,
            $sellerId,
            $orderId,
            count($closedRowIds)
        ));
    }

    /**
     * Flip every still-payable saleslist row for the (order, seller) to REFUNDED. The payable set is
     * exactly what Webkul's native payout would grab — paid_status PENDING and cpprostatus COMPLETE
     * (Webkul's real mark-paid guard, Payseller line 289). Marking them REFUNDED removes them from
     * that set, so a refunded vendor can never be paid. No trans_id (no payout), no balance change.
     *
     * @param int $orderId
     * @param int $sellerId
     * @return int[] The saleslist row ids closed
     */
    private function closeSaleslistRows(int $orderId, int $sellerId): array
    {
        $collection = $this->saleslistCollectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        $collection->addFieldToFilter('seller_id', $sellerId);
        $collection->addFieldToFilter('paid_status', self::SALESLIST_PENDING);
        $collection->addFieldToFilter('cpprostatus', self::SALESLIST_COMPLETE);

        $closed = [];
        foreach ($collection->getItems() as $row) {
            $row->setPaidStatus(self::SALESLIST_REFUNDED);
            $this->saleslistResource->save($row);
            $closed[] = (int) $row->getId();
        }
        return $closed;
    }

    /**
     * Compare-and-set the webkul_synced_at marker: a conditional UPDATE that stamps it only while
     * still NULL, returning whether THIS call won the claim. The single idempotency + concurrency
     * gate — a second concurrent run affects 0 rows and must not close.
     *
     * @param AdapterInterface $connection
     * @param int $segmentId
     * @param string $now
     * @return bool True when this call claimed the segment (exactly-once owner)
     */
    private function claimSync(AdapterInterface $connection, int $segmentId, string $now): bool
    {
        $claimed = (int) $connection->update(
            $this->segmentResource->getMainTable(),
            ['webkul_synced_at' => $now],
            ['segment_id = ?' => $segmentId, 'webkul_synced_at IS NULL' => '']
        );
        return $claimed === 1;
    }
}
