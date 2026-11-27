<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Diagnostic\Util;

/**
 * Centralised redaction. Three trust tiers:
 *  - NEVER log: salt, merchant key — replaced with ***present*** / ***absent***
 *  - PARTIAL log: hash — first 8 chars + length only (enough to compare without leaking)
 *  - FULL log gated by prodMode: firstname, email, phone — visible on local/staging,
 *    masked on prod.
 *
 * Stateless. Inject as a fresh instance per consumer; the prodMode flag is bound at
 * construction time from Config::isPiiRedactionEnabled().
 */
class PayloadRedactor
{
    private const SECRET_KEYS = ['salt', 'key', 'merchant_key'];
    private const PARTIAL_KEYS = ['hash'];
    private const PII_KEYS = ['firstname', 'email', 'phone', 'lastname', 'Lastname'];

    public function __construct(
        private readonly bool $prodMode
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function redact(array $payload): array
    {
        $out = [];
        foreach ($payload as $k => $v) {
            if (in_array($k, self::SECRET_KEYS, true)) {
                $out[$k] = ($v === null || $v === '') ? '***absent***' : '***present***';
                continue;
            }
            if (in_array($k, self::PARTIAL_KEYS, true)) {
                $out[$k] = $this->maskHash(is_string($v) ? $v : null);
                continue;
            }
            if ($this->prodMode && in_array($k, self::PII_KEYS, true)) {
                $out[$k] = '***redacted***';
                continue;
            }
            $out[$k] = $v;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $secrets
     * @return array<string, string>
     */
    public function redactSecrets(array $secrets): array
    {
        $out = [];
        foreach ($secrets as $k => $v) {
            $out[$k] = ($v === null || $v === '') ? '***absent***' : '***present***';
        }
        return $out;
    }

    public function maskHash(?string $hash): string
    {
        if ($hash === null || $hash === '') {
            return '***absent***';
        }
        return substr($hash, 0, 8) . '...len=' . strlen($hash);
    }
}
