<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Service;

/**
 * Computes the response hash PayU's gateway sends back, per PayU's *current* spec.
 * Single source of truth for the formula — both the diagnostic logger and the
 * split-aware validation plugin route through this class.
 *
 * Reference: https://docs.payu.in/docs/hashing-request-and-response
 *
 * Formula:
 *   classic:  sha512(SALT|status||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
 *   split:    sha512(SALT|status|splitInfo||||||udf5|udf4|udf3|udf2|udf1|email|firstname|productinfo|amount|txnid|key)
 *
 *   With additional_charges (either form):
 *             prepend "additional_charges|" to the string above.
 *
 * Note: PayU's current docs collapse udf10..udf6 into 5 empty positions in the response
 * hash regardless of whether splitInfo is present. The upstream PayUIndia\Payu\Model\Payu
 * still feeds udf10..udf6 actual values — for Magento payloads where those are empty
 * (the common case) the strings are identical, but if any of udf6..udf10 ever carry a
 * value, upstream will mismatch even classic responses.
 */
class ResponseHashCalculator
{
    private const REVERSE_FIELDS = [
        'udf5', 'udf4', 'udf3', 'udf2', 'udf1',
        'email', 'firstname', 'productinfo', 'amount', 'txnid',
    ];

    /**
     * @param array<string, mixed> $payload
     */
    public function compute(array $payload, string $salt, string $merchantKey): string
    {
        $reverseChain = '';
        foreach (self::REVERSE_FIELDS as $field) {
            $reverseChain .= $this->stringValue($payload, $field) . '|';
        }
        $reverseChain .= $merchantKey;

        $status = $this->stringValue($payload, 'status');

        if (array_key_exists('splitInfo', $payload)) {
            $splitInfo = $this->stringValue($payload, 'splitInfo');
            $hashString = $salt . '|' . $status . '|' . $splitInfo . '||||||' . $reverseChain;
        } else {
            $hashString = $salt . '|' . $status . '||||||' . $reverseChain;
        }

        $additionalCharges = $this->extractAdditionalCharges($payload);
        if ($additionalCharges !== null) {
            $hashString = $additionalCharges . '|' . $hashString;
        }

        return strtolower(hash('sha512', $hashString));
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function stringValue(array $payload, string $key): string
    {
        if (!array_key_exists($key, $payload)) {
            return '';
        }
        $v = $payload[$key];
        if ($v === null) {
            return '';
        }
        return (string)$v;
    }

    /**
     * Mirror upstream's `if ($additionalCharges > 0)` guard. Accepts both PayU's
     * documented snake_case key and the camelCase alias the upstream module reads
     * (snake_case wins if both are present).
     *
     * @param array<string, mixed> $payload
     */
    private function extractAdditionalCharges(array $payload): ?string
    {
        $raw = $payload['additional_charges'] ?? $payload['additionalCharges'] ?? null;
        if ($raw === null || $raw === '') {
            return null;
        }
        if ((float)$raw <= 0) {
            return null;
        }
        return (string)$raw;
    }
}
