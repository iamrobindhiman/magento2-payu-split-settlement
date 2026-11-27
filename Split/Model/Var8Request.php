<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * A ready-to-send PayU cancel_refund_transaction request: the var8 JSON payload (one or
 * more child-keyed legs, coalesced for a single cron tick under O-3) and the total
 * refund amount. The gateway takes var3 (total) as a 2-decimal rupee string and var8 as
 * the JSON; this VO carries the total in paise so the ledger and the wire string both
 * derive from one integer source. Immutable.
 */
class Var8Request
{
    /**
     * @param string $json The var8 payload: {childKey:{amount,aggregatorRefundAmount}} in rupees
     * @param int $totalPaise Combined refund total across all legs, paise (var3 source)
     */
    public function __construct(
        private readonly string $json,
        private readonly int $totalPaise
    ) {
    }

    /**
     * @return string The var8 JSON to post
     */
    public function json(): string
    {
        return $this->json;
    }

    /**
     * @return int Combined refund total, paise
     */
    public function totalPaise(): int
    {
        return $this->totalPaise;
    }

    /**
     * @return string var3: the combined total as a 2-decimal rupee string
     */
    public function totalRupees(): string
    {
        return Money::fromPaise($this->totalPaise)->toRupeeString();
    }
}
