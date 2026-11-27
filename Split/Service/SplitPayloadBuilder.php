<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use Magento\Framework\Serialize\Serializer\Json;

/**
 * Turns an OrderSplitPlan (Slice A2) into the PayU payment_split `var1` JSON for a
 * split-after ("absolute") request. Each vendor segment becomes a splitInfo entry
 * keyed by its PayU child merchant key; the platform remainder becomes a segment
 * keyed by the parent (aggregator) merchant key — PayU reports that one as
 * splitType "commission" (proven live, experiment E-05). Every aggregatorSubAmt is
 * a two-decimal rupee string and they sum to the order grand total, satisfying
 * PayU's absolute-split rule (sum of segments == transaction amount, else AGG-108).
 *
 * The returned string is the exact value to hash AND to send as var1 — build once,
 * hash that string, post that string.
 */
class SplitPayloadBuilder
{
    /**
     * @param Json $json
     */
    public function __construct(
        private readonly Json $json
    ) {
    }

    /**
     * @param OrderSplitPlan $plan Validated vendor segments + resolved child keys
     * @param string $payuId PayU transaction id (mihpayid) of the captured payment
     * @param string $parentMerchantKey Parent (aggregator) merchant key for the platform segment
     * @return string The raw `var1` JSON string
     * @throws SplitCalculationException When a vendor segment has no resolved child key
     */
    public function build(OrderSplitPlan $plan, string $payuId, string $parentMerchantKey): string
    {
        $sellerKeyMap = $plan->sellerKeyMap();
        $splitInfo = [];

        foreach ($plan->result()->vendorSegments() as $sellerId => $amount) {
            // Defense-in-depth: the A1 calculator already excludes zero-payout vendors, but
            // never emit a "0.00" (or negative) segment even if that ever changes. Skipping a
            // zero leaves the sum invariant intact (segments still total the grand amount).
            if (!$amount->isPositive()) {
                continue;
            }
            if (!isset($sellerKeyMap[$sellerId])) {
                throw new SplitCalculationException(
                    sprintf('No PayU child merchant key resolved for seller %d.', $sellerId)
                );
            }
            $splitInfo[$sellerKeyMap[$sellerId]] = [
                'aggregatorSubTxnId' => $plan->orderIncrementId() . '-' . $sellerId,
                'aggregatorSubAmt' => $amount->toRupeeString(),
            ];
        }

        // The platform keeps shipping + commission + any sub-paise rounding. Sending it
        // as a segment under the parent key makes the segments sum to the grand total.
        // A zero platform share (100% to a vendor) gets no segment — no "0.00" instruction.
        $platformAmount = $plan->result()->platformAmount();
        if ($platformAmount->isPositive()) {
            $splitInfo[$parentMerchantKey] = [
                'aggregatorSubTxnId' => $plan->orderIncrementId() . '-PLATFORM',
                'aggregatorSubAmt' => $platformAmount->toRupeeString(),
            ];
        }

        return $this->json->serialize([
            'type' => 'absolute',
            'payuId' => $payuId,
            'splitInfo' => $splitInfo,
        ]);
    }
}
