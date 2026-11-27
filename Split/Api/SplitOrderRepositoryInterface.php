<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Api;

use RKD\PayuSplit\Split\Model\SplitOrder;

/**
 * Reads/writes the per-order split header (rkd_payu_split). Internal contract
 * (not a webapi); returns the concrete model by design, matching the module's
 * existing SubmerchantRepository style.
 */
interface SplitOrderRepositoryInterface
{
    /**
     * @param int $orderId Magento order entity id
     * @return SplitOrder|null Null when the order has no split header yet (used for the
     *         record-on-pay idempotency guard).
     */
    public function getByOrderId(int $orderId): ?SplitOrder;

    /**
     * @param SplitOrder $splitOrder
     * @return SplitOrder
     */
    public function save(SplitOrder $splitOrder): SplitOrder;
}
