<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Diagnostic\Plugin;

use RKD\PayuSplit\Diagnostic\Util\PayloadRedactor;
use RKD\PayuSplit\Model\Config;
use RKD\PayuSplit\Service\ResponseHashCalculator;
use PayUIndia\Payu\Model\Payu;
use Psr\Log\LoggerInterface;

/**
 * Hook 2 — wraps PayUIndia\Payu\Model\Payu::validateResponse.
 *
 * Upstream returns false silently from 6 distinct paths (status/key/hash-missing/
 * hash-mismatch/etc) and reads udf1..udf10 unconditionally — PHP 8 raises a Warning
 * on undefined-index, which can surface as ErrorException depending on error_reporting.
 *
 * This plugin: wraps proceed() in try/catch, classifies the failure reason, logs
 * everything we can see without leaking salt/key/hash, and returns false on caught
 * undefined-index errors (matching upstream's silent-false contract).
 */
class ValidateResponsePlugin
{
    public function __construct(
        private readonly Config $config,
        private readonly PayloadRedactor $redactor,
        private readonly LoggerInterface $logger,
        private readonly ResponseHashCalculator $calculator
    ) {
    }

    /**
     * @param Payu $subject
     * @param \Closure $proceed
     * @param array<string, mixed> $returnParams
     * @return bool
     */
    public function aroundValidateResponse(Payu $subject, \Closure $proceed, array $returnParams): bool
    {
        if (!$this->config->isLoggingEnabled()) {
            return (bool)$proceed($returnParams);
        }

        $context = [
            'payload' => $this->redactor->redact($returnParams),
            'payload_keys' => array_keys($returnParams),
        ] + $this->hashDiagnosticContext($subject, $returnParams);

        try {
            $result = (bool)$proceed($returnParams);
        } catch (\Throwable $t) {
            $reason = $this->classifyThrowable($t);
            $this->logger->warning('validateResponse returned false (caught throwable)', $context + [
                'result' => false,
                'failure_reason' => $reason,
                'exception' => $t->getMessage(),
            ]);
            return false;
        }

        if ($result) {
            $this->logger->info('validateResponse succeeded', $context + ['result' => true]);
            return true;
        }

        $reason = $this->classifyFailure($subject, $returnParams);
        $this->logger->warning('validateResponse returned false', $context + [
            'result' => false,
            'failure_reason' => $reason,
        ]);

        return false;
    }

    /**
     * Compute the hash WE would expect (per PayU's current spec, split-aware) and
     * include first-16-char prefixes of both computed and received in the log
     * context. Future hash bugs become a one-line diagnosis instead of needing
     * a recompute script.
     *
     * @param array<string, mixed> $returnParams
     * @return array{computed_hash_prefix: string, received_hash_prefix: string, hash_formula: string}
     */
    private function hashDiagnosticContext(Payu $subject, array $returnParams): array
    {
        $salt = (string)$subject->getConfigData('salt');
        $key  = (string)$subject->getConfigData('merchant_key');

        try {
            $computed = $this->calculator->compute($returnParams, $salt, $key);
        } catch (\Throwable $t) {
            $computed = '';
        }

        $received = isset($returnParams['hash']) && is_string($returnParams['hash'])
            ? strtolower($returnParams['hash'])
            : '';

        return [
            'computed_hash_prefix' => $computed === '' ? '***calc-failed***' : substr($computed, 0, 16),
            'received_hash_prefix' => $received === '' ? '***absent***' : substr($received, 0, 16),
            'hash_formula' => array_key_exists('splitInfo', $returnParams) ? 'split' : 'classic',
        ];
    }

    /**
     * Mirror upstream's check order to figure out which line returned false.
     * @param array<string, mixed> $p
     */
    private function classifyFailure(Payu $subject, array $p): string
    {
        if (($p['status'] ?? null) !== 'success') {
            return 'status_not_success';
        }
        $expectedKey = $subject->getConfigData('merchant_key');
        if (($p['key'] ?? null) !== $expectedKey) {
            return 'key_mismatch';
        }
        if (!isset($p['hash'])) {
            return 'hash_missing';
        }
        // Differentiate split-formula failures from classic-formula failures so
        // the log distinguishes "merchant has split enabled" from generic
        // hash divergence. The Split\Plugin\ResponseHashPlugin (sortOrder 20)
        // is what actually returned false in the split case — but it returns
        // silently, so this outer plugin owns the failure classification.
        if (array_key_exists('splitInfo', $p)) {
            return 'hash_mismatch_split';
        }
        return 'hash_mismatch';
    }

    private function classifyThrowable(\Throwable $t): string
    {
        $msg = $t->getMessage();
        if (preg_match('/undefined (array key|index)\s+["\']?(udf\d+)/i', $msg)) {
            return 'undefined_udf_key';
        }
        if (stripos($msg, 'undefined') !== false) {
            return 'undefined_array_key';
        }
        return 'unexpected_throwable';
    }
}
