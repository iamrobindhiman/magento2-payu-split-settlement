<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\SplitOrder;
use RKD\PayuSplit\Split\Model\SplitResult;
use RKD\PayuSplit\Split\Model\SplitSegment;

/**
 * Rebuilds an OrderSplitPlan from the A4 payment-time snapshot — the header's
 * recorded txn total plus its persisted segments — NOT from live Webkul earnings
 * (matrix INV-8: the base is frozen at payment; a later cancellation must not
 * shrink it). The plan it returns is exactly what the A3 gateway's payment_split
 * consumes: vendor segments keyed by seller, the parent remainder, the grand
 * total, and the seller -> child-key map.
 *
 * A snapshot whose segments no longer sum to the recorded transaction is rejected
 * as corruption (INV-1) rather than POSTed — an under/over-sum split would draw
 * AGG-108 from PayU and could misallocate money.
 */
class SnapshotPlanReader
{
    /**
     * @param SegmentLoader $segmentLoader
     */
    public function __construct(
        private readonly SegmentLoader $segmentLoader
    ) {
    }

    /**
     * @param SplitOrder $header
     * @return OrderSplitPlan
     * @throws SplitCalculationException When the snapshot segments do not reconcile to the txn total
     */
    public function read(SplitOrder $header): OrderSplitPlan
    {
        $vendorSegments = [];
        $sellerKeyMap = [];
        $platformAmount = Money::zero();

        foreach ($this->segmentLoader->loadByOrderId($header->getOrderId()) as $segment) {
            if ($segment->getSegmentType() === SplitSegment::TYPE_COMMISSION) {
                $platformAmount = Money::fromPaise($segment->getAmountPaise());
                continue;
            }

            $sellerId = $segment->getSellerId();
            $vendorSegments[$sellerId] = Money::fromPaise($segment->getAmountPaise());

            $childKey = $segment->getChildMerchantKey();
            if ($childKey !== null) {
                $sellerKeyMap[$sellerId] = $childKey;
            }
        }

        $grandTotal = Money::fromPaise($header->getTxnAmountPaise());
        $result = new SplitResult($vendorSegments, $platformAmount, $grandTotal);

        if (!$result->total()->equals($grandTotal)) {
            throw new SplitCalculationException(sprintf(
                'Snapshot for order %d does not reconcile: segments total %d paise but txn is %d paise.',
                $header->getOrderId(),
                $result->total()->paise,
                $grandTotal->paise
            ));
        }

        return new OrderSplitPlan(
            $header->getOrderId(),
            $header->getOrderIncrementId(),
            $result,
            $sellerKeyMap
        );
    }
}
