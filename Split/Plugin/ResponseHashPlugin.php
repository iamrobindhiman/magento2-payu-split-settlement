<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Plugin;

use RKD\PayuSplit\Service\ResponseHashCalculator;
use PayUIndia\Payu\Model\Payu;
use Psr\Log\LoggerInterface;

/**
 * Around-plugin on PayUIndia\Payu\Model\Payu::validateResponse.
 *
 * Why this exists
 * ---------------
 * When the merchant has split-settlement enabled on PayU's side, the response
 * carries a `splitInfo` field AND PayU's response hash formula switches to include
 * `splitInfo` after `status`. The upstream module is unaware — it always uses the
 * classic formula, so 100% of transactions fail with `hash_mismatch` once split
 * is enabled.
 *
 * Behaviour
 * ---------
 * - splitInfo absent → defer to upstream (`proceed()`) untouched
 * - splitInfo present →
 *     1. Run the same pre-checks upstream does (status==success, key match, hash
 *        present). Mismatch → return false without proceeding.
 *     2. Compute the hash with the split-aware formula via ResponseHashCalculator.
 *     3. Compare with `hash_equals`. Return true on match, false on mismatch.
 *     4. On mismatch, emit a one-line diagnostic warning (failure_reason =
 *        hash_mismatch_split) so the log distinguishes split-formula failures
 *        from classic-formula failures.
 *
 * Plugin ordering
 * ---------------
 * Wired with sortOrder=20, so the diagnostic ValidateResponsePlugin (sortOrder=10)
 * remains the OUTER wrapper and observes the final result. The diagnostic plugin
 * owns all logging — this plugin returns silently to avoid double-logging.
 *
 * The logger dependency is retained for emergency diagnostics and to satisfy the
 * CodingStandardsGuardTest "every plugin declares LoggerInterface" rule.
 */
class ResponseHashPlugin
{
    public function __construct(
        private readonly ResponseHashCalculator $calculator,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $returnParams
     */
    public function aroundValidateResponse(Payu $subject, \Closure $proceed, array $returnParams): bool
    {
        if (!array_key_exists('splitInfo', $returnParams)) {
            return (bool)$proceed($returnParams);
        }

        if (($returnParams['status'] ?? null) !== 'success') {
            return false;
        }

        $expectedKey = (string)$subject->getConfigData('merchant_key');
        if (($returnParams['key'] ?? null) !== $expectedKey) {
            return false;
        }

        if (!isset($returnParams['hash'])) {
            return false;
        }

        $salt = (string)$subject->getConfigData('salt');
        $computed = $this->calculator->compute($returnParams, $salt, $expectedKey);
        $received = strtolower((string)$returnParams['hash']);

        return hash_equals($computed, $received);
    }
}
