<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\SplitCalculationException;
use RKD\PayuSplit\Split\Exception\SubmerchantNotMappedException;
use RKD\PayuSplit\Split\Model\Money;
use RKD\PayuSplit\Split\Model\OrderSplitPlan;
use RKD\PayuSplit\Split\Model\SplitInput;
use Magento\Sales\Api\OrderRepositoryInterface;

/**
 * Assembles a paid order into a validated split plan (Slice A2): reads the
 * order's grand total + per-seller earnings, runs the SplitCalculator, and
 * resolves a PayU child key for every vendor that ends up in the split.
 * Pure data assembly — no PayU call (that is Slice A3).
 */
class OrderSplitDataProvider
{
    /**
     * @param OrderRepositoryInterface $orderRepository
     * @param SellerEarningsReader $earningsReader
     * @param SubmerchantRepositoryInterface $submerchantRepository
     * @param SplitCalculator $calculator
     * @param OrderItemEarningsReader $itemEarningsReader
     */
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly SellerEarningsReader $earningsReader,
        private readonly SubmerchantRepositoryInterface $submerchantRepository,
        private readonly SplitCalculator $calculator,
        private readonly OrderItemEarningsReader $itemEarningsReader
    ) {
    }

    /**
     * @param int $orderId
     * @return OrderSplitPlan
     */
    public function buildForOrder(int $orderId): OrderSplitPlan
    {
        $order = $this->orderRepository->get($orderId);

        // Guard the grand total before it reaches the value object: an unpaid or
        // cancelled order can return null/0, which we surface as a typed,
        // order-contextual error rather than a bare InvalidArgumentException.
        $rawGrandTotal = $order->getGrandTotal();
        if ($rawGrandTotal === null || (float) $rawGrandTotal <= 0.0) {
            throw new SplitCalculationException(
                sprintf('Order %d has no positive grand total to split.', $orderId)
            );
        }
        $grandTotal = Money::fromRupees((string) $rawGrandTotal);
        $shares = $this->earningsReader->read($orderId);

        $result = $this->calculator->calculate(new SplitInput($grandTotal, $shares));

        // Resolve a PayU child key for every vendor that is actually in the split.
        // Zero-payout sellers are dropped by the calculator, so they need no key.
        $sellerKeyMap = [];
        $vendorSellerIds = [];
        foreach (array_keys($result->vendorSegments()) as $sellerId) {
            $sellerId = (int) $sellerId;
            $vendorSellerIds[$sellerId] = true;
            $submerchant = $this->submerchantRepository->getBySellerId($sellerId);
            if ($submerchant === null || !$submerchant->isActive()) {
                throw new SubmerchantNotMappedException(
                    sprintf('Seller %d is not mapped to an active PayU sub-merchant.', $sellerId)
                );
            }
            $sellerKeyMap[$sellerId] = $submerchant->getMerchantKey();
        }

        // Per-item gating rows, grouped by seller. Only vendors in the split get
        // itemised (the platform remainder is settled as one commission segment and
        // releases last, so it needs no per-item delivery gate in v1).
        $sellerItems = [];
        foreach ($this->itemEarningsReader->read($orderId) as $item) {
            if (isset($vendorSellerIds[$item->sellerId])) {
                $sellerItems[$item->sellerId][] = $item;
            }
        }

        return new OrderSplitPlan(
            $orderId,
            (string) $order->getIncrementId(),
            $result,
            $sellerKeyMap,
            $sellerItems
        );
    }
}
