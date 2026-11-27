<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SplitOrderRepositoryInterface;
use RKD\PayuSplit\Split\Model\ResourceModel\SplitSegment as SplitSegmentResource;
use RKD\PayuSplit\Split\Model\SplitSegment;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\Event\ManagerInterface as EventManager;
use Magento\Framework\Stdlib\DateTime\DateTime;
use Psr\Log\LoggerInterface;
use Webkul\Marketplace\Model\ResourceModel\Saleperpartner as SaleperpartnerResource;
use Webkul\Marketplace\Model\ResourceModel\Saleperpartner\CollectionFactory as SaleperpartnerCollectionFactory;
use Webkul\Marketplace\Model\ResourceModel\Saleslist as SaleslistResource;
use Webkul\Marketplace\Model\ResourceModel\Saleslist\CollectionFactory as SaleslistCollectionFactory;
use Webkul\Marketplace\Model\ResourceModel\Sellertransaction as SellertransactionResource;
use Webkul\Marketplace\Model\SaleperpartnerFactory;
use Webkul\Marketplace\Model\SellertransactionFactory;

/**
 * A5c-4 — mirrors a confirmed PayU release into Webkul's seller ledger so the seller dashboard
 * shows the vendor paid, and Webkul's native "Pay Seller" then naturally skips the now-paid rows
 * (INV-17 anti-double-pay: paid_status flips to 1). Driven by the rkd_payu_segment_released event
 * AND by a pull-reconcile scan (a lost event self-heals), both idempotent on webkul_synced_at.
 *
 * Webkul stores money as rupee decimals, so all ledger arithmetic here is in rupee floats — the
 * SAME representation Webkul's own Payseller uses (mirrored byte-for-byte, including its
 * non-negative amount_remain guard) so this never drifts from Webkul's books. The amount recorded
 * is the PayU RELEASED NET (released_paise = what actually settled to the vendor), converted to
 * rupees at the Webkul boundary. Unlike Webkul's own non-atomic payout, every write here is wrapped
 * in ONE DB transaction (the three Webkul tables + the segment stamp share the default connection),
 * so a crash can never leave a half-mirrored ledger.
 *
 * Launch-gated: a segment only reaches RELEASED when the release path is enabled at O-11, so this
 * never runs in UAT.
 */
class WebkulSettlementSync
{
    /** Webkul saleslist paid_status: 0 = pending/payable, 1 = paid (int literals, as Webkul uses). */
    private const SALESLIST_PAID = 1;
    private const SALESLIST_PENDING = 0;
    /**
     * Webkul cpprostatus COMPLETE — the ONLY state a saleslist row is actually payable in. Webkul's
     * collection filter is the looser `!= 0`, but its real mark-paid guard (Payseller line 289) is
     * `cpprostatus == 1`. We match that exact guard so a HOLD(2)/REFUNDED(3)/CANCELED(4) row can
     * never be flipped paid by the mirror.
     */
    private const CPPROSTATUS_COMPLETE = 1;

    /**
     * @param SaleslistCollectionFactory $saleslistCollectionFactory
     * @param SaleslistResource $saleslistResource
     * @param SaleperpartnerCollectionFactory $saleperpartnerCollectionFactory
     * @param SaleperpartnerFactory $saleperpartnerFactory
     * @param SaleperpartnerResource $saleperpartnerResource
     * @param SellertransactionFactory $sellertransactionFactory
     * @param SellertransactionResource $sellertransactionResource
     * @param SplitSegmentResource $segmentResource
     * @param SplitOrderRepositoryInterface $repository
     * @param DateTime $dateTime
     * @param EventManager $eventManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        private readonly SaleslistCollectionFactory $saleslistCollectionFactory,
        private readonly SaleslistResource $saleslistResource,
        private readonly SaleperpartnerCollectionFactory $saleperpartnerCollectionFactory,
        private readonly SaleperpartnerFactory $saleperpartnerFactory,
        private readonly SaleperpartnerResource $saleperpartnerResource,
        private readonly SellertransactionFactory $sellertransactionFactory,
        private readonly SellertransactionResource $sellertransactionResource,
        private readonly SplitSegmentResource $segmentResource,
        private readonly SplitOrderRepositoryInterface $repository,
        private readonly DateTime $dateTime,
        private readonly EventManager $eventManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param SplitSegment $segment
     * @return void
     */
    public function sync(SplitSegment $segment): void
    {
        $segmentId = (int) $segment->getId();

        // Scope + idempotency: only a RELEASED segment that has not already been mirrored. A
        // re-fired event or a reconcile re-run is a safe no-op.
        if ($segment->getState() !== SplitSegment::STATE_RELEASED) {
            return;
        }
        if ($segment->getWebkulSyncedAt() !== null) {
            return;
        }

        $now = $this->dateTime->gmtDate();
        $connection = $this->saleslistResource->getConnection();

        // The marketplace parent (commission, seller_id 0) has no Webkul seller ledger of its own —
        // its commission is already accounted in Webkul at order time. Claim the sync marker (no
        // Webkul writes) so the reconcile scan stops re-selecting it.
        if ($segment->getSegmentType() === SplitSegment::TYPE_COMMISSION || $segment->getSellerId() === 0) {
            if ($this->claimSync($connection, $segmentId, $now)) {
                $segment->setData('webkul_synced_at', $now);
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: segment %d is the marketplace commission — no Webkul payout to mirror, marked synced.',
                    $segmentId
                ));
            }
            return;
        }

        $orderId = $segment->getOrderId();
        $sellerId = $segment->getSellerId();
        // PayU released net (paise) → rupees at the Webkul boundary (Webkul stores rupee decimals).
        $netRupees = $segment->getReleasedPaise() / 100;
        $transactionId = $this->buildTransactionId($segment);

        $connection->beginTransaction();
        try {
            // CAS claim FIRST, inside the transaction: a conditional UPDATE that stamps
            // webkul_synced_at only if still NULL. This serialises concurrent mirrors (the observer
            // racing the reconcile scan, or two reconcile ticks) — the row lock blocks the second
            // run until the first commits, after which it sees the marker set, affects 0 rows, and
            // bails WITHOUT a second payout. Because the claim lives in the SAME transaction as the
            // ledger writes, a rollback unstamps it, so a failed mirror is retried, never stranded
            // synced-but-unpaid. This is the idempotency guarantee (a plain read-check is not
            // concurrency-safe).
            if (!$this->claimSync($connection, $segmentId, $now)) {
                $connection->rollBack();
                $this->logger->info(sprintf(
                    'RKD_PayuSplit: segment %d already claimed for Webkul mirror by a concurrent run — not re-mirroring.',
                    $segmentId
                ));
                return;
            }
            $transRowId = $this->writeSellerTransaction($segment, $sellerId, $netRupees, $transactionId, $now);
            $markedRowIds = $this->markSaleslistPaid($orderId, $sellerId, $transRowId);
            $this->applySellerBalance($sellerId, $netRupees, $now);
            $connection->commit();
            $segment->setData('webkul_synced_at', $now);
        } catch (\Throwable $t) {
            $connection->rollBack();
            // Leave the segment unmirrored — the pull-reconcile scan retries it. A failed mirror
            // never leaves a partial ledger (the whole transaction rolled back).
            $this->logger->error(
                sprintf(
                    'RKD_PayuSplit: Webkul ledger mirror failed for segment %d (order %d, seller %d) — '
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

        // Parity with Webkul's own payout: notify any seller-payout listener (post-commit).
        $this->dispatchPaySeller($transactionId, $transRowId, $markedRowIds, $orderId, $sellerId);
        $this->logger->info(sprintf(
            'RKD_PayuSplit: mirrored release of segment %d into Webkul ledger — seller %d paid %s (txn %s).',
            $segmentId,
            $sellerId,
            number_format($netRupees, 2, '.', ''),
            $transactionId
        ));
    }

    /**
     * Insert the Webkul payout-log row and return its entity id (saleslist.trans_id points at it).
     *
     * @param SplitSegment $segment
     * @param int $sellerId
     * @param float $netRupees
     * @param string $transactionId
     * @param string $now
     * @return int
     */
    private function writeSellerTransaction(
        SplitSegment $segment,
        int $sellerId,
        float $netRupees,
        string $transactionId,
        string $now
    ): int {
        $row = $this->sellertransactionFactory->create();
        $row->setTransactionId($transactionId);
        $row->setTransactionAmount($netRupees);
        $row->setType('PayU');
        $row->setMethod('PayU');
        $row->setSellerId($sellerId);
        $row->setCustomNote(sprintf(
            'PayU split-settlement release (order %d, segment %d).',
            $segment->getOrderId(),
            (int) $segment->getId()
        ));
        $row->setCreatedAt($now);
        $row->setSellerPendingNotification(1);
        $this->sellertransactionResource->save($row);
        return (int) $row->getId();
    }

    /**
     * Flip every payable saleslist row for the (order, seller) to paid + point trans_id at the
     * payout-log row. This is the primary INV-17 guard: Webkul's native payout filters paid_status=0,
     * so a paid row can never be paid again.
     *
     * @param int $orderId
     * @param int $sellerId
     * @param int $transRowId
     * @return int[] The saleslist row ids marked paid
     */
    private function markSaleslistPaid(int $orderId, int $sellerId, int $transRowId): array
    {
        $collection = $this->saleslistCollectionFactory->create();
        $collection->addFieldToFilter('order_id', $orderId);
        $collection->addFieldToFilter('seller_id', $sellerId);
        $collection->addFieldToFilter('paid_status', self::SALESLIST_PENDING);
        // cpprostatus == COMPLETE only (Webkul's actual mark-paid guard) — never a HOLD/REFUNDED/
        // CANCELED row, even if one were left paid_status=0 by a future Webkul feature.
        $collection->addFieldToFilter('cpprostatus', self::CPPROSTATUS_COMPLETE);

        $marked = [];
        foreach ($collection->getItems() as $row) {
            $row->setPaidStatus(self::SALESLIST_PAID);
            $row->setTransId($transRowId);
            $this->saleslistResource->save($row);
            $marked[] = (int) $row->getId();
        }
        return $marked;
    }

    /**
     * Apply the payout to the seller's running balance, mirroring Webkul Payseller's exact
     * arithmetic — including its guard that amount_remain is reduced ONLY when it covers the payout
     * (else it floors at 0, never negative). saleperpartner is per-seller, so there is one row.
     *
     * @param int $sellerId
     * @param float $netRupees
     * @param string $now
     * @return void
     */
    private function applySellerBalance(int $sellerId, float $netRupees, string $now): void
    {
        $collection = $this->saleperpartnerCollectionFactory->create();
        $collection->addFieldToFilter('seller_id', $sellerId);
        $rowId = 0;
        foreach ($collection->getItems() as $existing) {
            $rowId = (int) $existing->getId();
        }

        if ($rowId === 0) {
            // No accrual row for this seller (an edge case — a vendor with a split normally has one
            // from order-placement accrual). Record the payment minimally and alarm; the missing
            // total_sale/commission context is a Webkul accrual gap, not something we can invent.
            $row = $this->saleperpartnerFactory->create();
            $row->setSellerId($sellerId);
            $row->setLastAmountPaid($netRupees);
            $row->setAmountReceived($netRupees);
            $row->setAmountRemain(0);
            $row->setCreatedAt($now);
            $this->saleperpartnerResource->save($row);
            $this->logger->warning(sprintf(
                'RKD_PayuSplit: no marketplace_saleperpartner row for seller %d — created a minimal payout row.',
                $sellerId
            ));
            return;
        }

        $row = $this->saleperpartnerFactory->create();
        $this->saleperpartnerResource->load($row, $rowId);
        $remain = (float) $row->getAmountRemain();
        $received = (float) $row->getAmountReceived();
        $newRemain = ($remain >= $netRupees) ? $remain - $netRupees : 0.0;
        $row->setLastAmountPaid($netRupees);
        $row->setAmountReceived($received + $netRupees);
        $row->setAmountRemain($newRemain);
        $this->saleperpartnerResource->save($row);
    }

    /**
     * Compare-and-set the webkul_synced_at marker: a conditional UPDATE that stamps it only while
     * still NULL, returning whether THIS call won the claim. The single idempotency + concurrency
     * gate — a second concurrent run affects 0 rows and must not mirror.
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

    /**
     * A deterministic, per-segment-unique payout id (one mirror per segment). Ties the Webkul
     * payout-log row back to the PayU transaction for reconciliation.
     *
     * @param SplitSegment $segment
     * @return string
     */
    private function buildTransactionId(SplitSegment $segment): string
    {
        $header = $this->repository->getByOrderId($segment->getOrderId());
        $mihpayid = $header !== null ? (string) $header->getMihpayid() : '';
        if ($mihpayid !== '') {
            return sprintf('payu-%s-%d', $mihpayid, $segment->getSellerId());
        }
        return sprintf('payu-seg-%d', (int) $segment->getId());
    }

    /**
     * Dispatch Webkul's mp_pay_seller event so any seller-payout listener fires, matching Webkul's
     * own (double-wrapped [$payload]) dispatch shape for bug-for-bug parity.
     *
     * @param string $transactionId
     * @param int $transRowId
     * @param int[] $markedRowIds
     * @param int $orderId
     * @param int $sellerId
     * @return void
     */
    private function dispatchPaySeller(
        string $transactionId,
        int $transRowId,
        array $markedRowIds,
        int $orderId,
        int $sellerId
    ): void {
        $this->eventManager->dispatch('mp_pay_seller', [[
            'trans_id' => $transactionId,
            'mp_trans_row_id' => $transRowId,
            'mp_saleslist_row_id' => $markedRowIds[0] ?? 0,
            'id' => $orderId,
            'seller_id' => $sellerId,
        ]]);
    }
}
