<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The computed refund for one cancelled/returned vendor segment: the two var8 legs
 * proven by sandbox E-12 — the vendor child leg (amount, debits that vendor's
 * escrowed segment) and the parent-commission leg (aggregatorRefundAmount, debits the
 * marketplace's commission). Refunding this vendor never claws from another (INV-10).
 * All amounts are integer paise; rupee strings are produced only for the wire payload.
 * Immutable.
 */
class RefundPlan
{
    /**
     * @param string $childKey PayU child merchant key of the cancelled vendor's segment
     * @param int $amountPaise Vendor child leg, paise (debits the vendor's escrowed segment)
     * @param int $aggregatorPaise Parent-commission leg, paise (the aggregatorRefundAmount)
     */
    public function __construct(
        public readonly string $childKey,
        public readonly int $amountPaise,
        public readonly int $aggregatorPaise
    ) {
    }

    /**
     * Total the customer is refunded for this segment (var3 contribution), paise.
     *
     * @return int
     */
    public function totalPaise(): int
    {
        return $this->amountPaise + $this->aggregatorPaise;
    }

    /**
     * The var8 entry for this child key (E-12 shape), amounts as 2-decimal rupee strings.
     *
     * @return array{amount: string, aggregatorRefundAmount: string}
     */
    public function toVar8Entry(): array
    {
        return [
            'amount' => Money::fromPaise($this->amountPaise)->toRupeeString(),
            'aggregatorRefundAmount' => Money::fromPaise($this->aggregatorPaise)->toRupeeString(),
        ];
    }
}
