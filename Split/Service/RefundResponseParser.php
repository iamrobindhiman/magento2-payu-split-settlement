<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\RefundLeg;

/**
 * Interprets the decoded body of a PayU cancel_refund_transaction response (the raw
 * value off a PayuApiResponse) into one RefundLeg per merchant key. Unlike split /
 * release responses, a refund response is keyed by merchant key at the top level —
 * {"<childKey>":{status,request_id,…},"<parentKey>":{…}} — so the generic
 * scalar-flattening parser would drop the per-leg arrays. This reads them.
 */
class RefundResponseParser
{
    /**
     * @param mixed $raw The decoded response body (array keyed by merchant key)
     * @return RefundLeg[] One leg per merchant-key entry; empty when the body is not a leg map
     */
    public function parseLegs(mixed $raw): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $legs = [];
        foreach ($raw as $key => $leg) {
            // A leg is a string-keyed (merchant key) array value; a stray top-level
            // scalar (e.g. a global status field) is not a merchant leg.
            if (!is_string($key) || !is_array($leg)) {
                continue;
            }
            $legs[] = new RefundLeg(
                $key,
                isset($leg['status']) ? (int) $leg['status'] : 0,
                isset($leg['request_id']) && $leg['request_id'] !== '' ? (string) $leg['request_id'] : null,
                isset($leg['error_code']) ? (string) $leg['error_code'] : null,
                isset($leg['msg']) ? (string) $leg['msg'] : (string) ($leg['message'] ?? '')
            );
        }
        return $legs;
    }
}
