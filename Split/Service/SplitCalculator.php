<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\SplitInput;
use RKD\PayuSplit\Split\Model\SplitResult;

/**
 * Turns a marketplace order's per-seller figures into a validated split.
 *
 * Money rules (confirmed 2026-06-08):
 *   - Each vendor receives their exact computed payout (item earnings, plus tax
 *     when tax_to_seller is set).
 *   - The platform receives the remainder: grand_total - sum(vendor payouts).
 *     This guarantees the segments sum to exactly what the customer paid, and any
 *     sub-paise rounding lands with the platform (never the vendor).
 *   - Affiliate commission is not modelled here; it is paid by the platform
 *     separately and comes out of the platform's share.
 *
 * Pure: no database, no PayU calls. Mapping seller_id -> PayU child merchant key
 * and the actual API call happen downstream (Slice A2).
 */
class SplitCalculator
{
    /**
     * @param SplitInput $input
     * @return SplitResult
     * @throws SplitCalculationException When the figures cannot form a valid split.
     */
    public function calculate(SplitInput $input): SplitResult
    {
        $shares = $input->sellerShares();
        if ($shares === []) {
            throw new SplitCalculationException('Cannot split an order with no sellers.');
        }

        if (!$input->grandTotal()->isPositive()) {
            throw new SplitCalculationException(
                sprintf('Order grand total must be positive (got %s).', $input->grandTotal()->toRupeeString())
            );
        }

        $vendorSegments = [];
        $vendorTotal = Money::zero();

        foreach ($shares as $share) {
            // seller_id 0 = admin/house products: they have no PayU child merchant and must
            // never become a vendor segment. Their revenue stays in the platform remainder.
            if ($share->sellerId <= 0) {
                throw new SplitCalculationException(
                    sprintf('Invalid seller id %d in split.', $share->sellerId)
                );
            }

            $payout = $share->payout();
            if ($payout->isNegative()) {
                throw new SplitCalculationException(
                    sprintf(
                        'Vendor %d has a negative payout (%s).',
                        $share->sellerId,
                        $payout->toRupeeString()
                    )
                );
            }

            $vendorTotal = $vendorTotal->add($payout);

            // A vendor owed exactly zero gets no split segment (no 0.00 instruction to PayU);
            // their zero contribution simply stays in the platform remainder.
            if (!$payout->isPositive()) {
                continue;
            }

            // A seller normally appears once; sum defensively if the same id recurs.
            $vendorSegments[$share->sellerId] = isset($vendorSegments[$share->sellerId])
                ? $vendorSegments[$share->sellerId]->add($payout)
                : $payout;
        }

        $platformAmount = $input->grandTotal()->subtract($vendorTotal);
        if ($platformAmount->isNegative()) {
            throw new SplitCalculationException(
                sprintf(
                    'Vendor shares (%s) exceed the order total (%s).',
                    $vendorTotal->toRupeeString(),
                    $input->grandTotal()->toRupeeString()
                )
            );
        }

        return new SplitResult($vendorSegments, $platformAmount, $input->grandTotal());
    }
}
