<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

/**
 * Turns a raw rkd_payu_submerchant row into the display strings the A7-2 admin mapping grid
 * shows. Pure (no DB, no clock). Its security-relevant job is {@see self::maskSecret()}: the
 * PayU child merchant key and MID are routing identifiers, so the grid (a list view) reveals
 * only their last four characters — the full value appears only on the ACL-gated edit form.
 */
class SubmerchantRowPresenter
{
    /**
     * Number of trailing characters left visible when masking a secret.
     */
    private const VISIBLE_TAIL = 4;

    /**
     * Characters of last_error shown in the grid; the full text is on the edit form.
     */
    private const ERROR_PREVIEW_LENGTH = 140;

    /**
     * Mask a routing identifier to its last four characters, e.g. "MERCHANTKEY1234" →
     * "•••••••••••1234". A value of four characters or fewer is fully masked (nothing
     * meaningful to reveal); an empty / null value renders as an em dash.
     *
     * @param string|null $value
     * @return string
     */
    public function maskSecret(?string $value): string
    {
        $value = (string) $value;
        if ($value === '') {
            return '—';
        }

        $length = strlen($value);
        if ($length <= self::VISIBLE_TAIL) {
            return str_repeat('•', $length);
        }

        return str_repeat('•', $length - self::VISIBLE_TAIL) . substr($value, -self::VISIBLE_TAIL);
    }

    /**
     * Title-case the KYC status for display, e.g. "pending" → "Pending". Em dash when empty.
     *
     * @param string $status
     * @return string
     */
    public function kycLabel(string $status): string
    {
        return $status === '' ? '—' : ucfirst($status);
    }

    /**
     * Human label for the is_active flag.
     *
     * @param int $isActive
     * @return string
     */
    public function activeLabel(int $isActive): string
    {
        return $isActive === 1 ? 'Active' : 'Inactive';
    }

    /**
     * The last onboarding error, shortened for a list cell. Em dash when there is none.
     *
     * @param string|null $error
     * @return string
     */
    public function errorSummary(?string $error): string
    {
        $error = trim((string) $error);
        if ($error === '') {
            return '—';
        }
        return mb_strlen($error) > self::ERROR_PREVIEW_LENGTH
            ? mb_substr($error, 0, self::ERROR_PREVIEW_LENGTH - 1) . '…'
            : $error;
    }

    /**
     * Where the Schedule C e-sign stands, for the one state in which it matters.
     *
     * @param string $state
     * @param string|null $esignSentAt
     * @return string
     */
    public function esignLabel(string $state, ?string $esignSentAt): string
    {
        if ($esignSentAt !== null && $esignSentAt !== '') {
            return 'Sent ' . $esignSentAt . ' UTC';
        }
        return $state === 'AWAITING_ESIGN' ? 'To send' : '—';
    }
}
