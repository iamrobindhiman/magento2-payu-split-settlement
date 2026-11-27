<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

/**
 * Makes an onboarding error safe to store in last_error and to show to staff (N-5).
 *
 * PayU's own error text is stored and shown. If it ever echoes a PAN or an account number back, it
 * must not travel any further than this, so both are redacted before anything else sees them.
 */
class OnboardingErrorText
{
    private const MAX_LENGTH = 1000;

    /**
     * Bounded by "not a letter or digit" rather than \b, which treats "_" as a word character and
     * so missed account_no_123456789012; a PAN is matched in any case (wave-2 N4).
     */
    private const REDACTIONS = [
        '/(?<![A-Za-z0-9])[A-Z]{5}\d{4}[A-Z](?![A-Za-z0-9])/i' => '[PAN]',
        '/(?<!\d)\d{9,18}(?!\d)/' => '[number]',
    ];

    /**
     * @param string $message
     * @return string
     */
    public static function clean(string $message): string
    {
        $message = (string)preg_replace(array_keys(self::REDACTIONS), array_values(self::REDACTIONS), $message);
        return mb_strlen($message) > self::MAX_LENGTH
            ? mb_substr($message, 0, self::MAX_LENGTH - 1) . '…'
            : $message;
    }
}
