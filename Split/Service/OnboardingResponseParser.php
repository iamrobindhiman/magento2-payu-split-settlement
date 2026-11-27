<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ChildMerchant;
use RKD\PayuSplit\Split\Model\ChildMerchantBank;
use RKD\PayuSplit\Split\Model\ChildMerchantStatus;
use RKD\PayuSplit\Split\Model\OnboardingApiResponse;

/**
 * Turns a PayU onboarding response into the handful of facts we act on.
 *
 * Pure, so every shape below is asserted in unit tests against responses actually
 * recorded from PayU UAT (Test/Unit/Split/_fixtures/onboarding/) rather than against
 * the samples in PayU's documentation, which differ from what the API returns.
 *
 * Three shapes here are not guessable and each cost a wrong turn:
 *   - the uuid is at product_account.uuid, NOT at merchant.uuid
 *   - the split payee key is at product_account.product_account_detail.pg_key,
 *     three levels down and not at top level
 *   - kyc_status is an OBJECT {status, kyc_status, ckyc_status}, not a string
 */
class OnboardingResponseParser
{
    /**
     * Read the merchant record out of a create / update / fetch response.
     *
     * @param OnboardingApiResponse $response
     * @return ChildMerchant|null Null when the response carried no usable account
     */
    public function parseChildMerchant(OnboardingApiResponse $response): ?ChildMerchant
    {
        $account = $response->productAccount();
        if ($account === null) {
            return null;
        }

        $uuid = $this->stringOrNull($account, 'uuid');
        if ($uuid === null) {
            return null;
        }

        return new ChildMerchant(
            $uuid,
            $this->readMid($account),
            $this->readPgKey($account),
            $this->stringOrNull($account, 'merchant_type'),
            $this->readStatus($account)
        );
    }

    /**
     * The status snapshot. Every field here is documented on ChildMerchantStatus as
     * to why it is NOT an activation signal — read that before using one as such.
     *
     * @param array<string, mixed> $account
     * @return ChildMerchantStatus
     */
    private function readStatus(array $account): ChildMerchantStatus
    {
        return new ChildMerchantStatus(
            $this->readKycStatus($account),
            $this->stringOrNull($account, 'document_status'),
            $this->stringOrNull($account, 'onboarding_status'),
            $this->stringOrNull($account, 'settlement_status'),
            $this->boolValue($account, 'onboarding_completed'),
            $this->boolValue($account, 'is_service_agreement_accepted'),
            $this->boolValue($account, 'is_service_agreement_esigned'),
            $this->readBank($account)
        );
    }

    /**
     * bank_detail is null until an account is attached, so an absent block is the
     * normal state of a freshly created child rather than a parse failure.
     *
     * @param array<string, mixed> $account
     * @return ChildMerchantBank
     */
    private function readBank(array $account): ChildMerchantBank
    {
        $bank = isset($account['bank_detail']) && is_array($account['bank_detail'])
            ? $account['bank_detail']
            : [];

        return new ChildMerchantBank(
            $this->stringOrNull($bank, 'bank_verification_status'),
            $this->stringOrNull($bank, 'penny_deposit_status'),
            $this->intValue($account, 'bank_update_attempt_count'),
            $this->intValue($account, 'bank_update_attempt_left')
        );
    }

    /**
     * Read the sub-account listing.
     *
     * Deliberately NOT parsed into ChildMerchant. The listing returns only eight
     * fields per row — no kyc_status, no bank status, no agreement status, no
     * merchant_type. Casting that into a ChildMerchant would manufacture nulls that
     * read as "not parented" and "no bank", which is worse than having no answer.
     * Use fetchChildMerchant() when status is what you need.
     *
     * @param OnboardingApiResponse $response
     * @return array<int, array{uuid: string, mid: string, name: string, email: string,
     *     pgKey: string|null, onboardingCompleted: bool}>
     */
    public function parseSubAccountList(OnboardingApiResponse $response): array
    {
        $rows = $response->body()['child_merchants']['product_accounts'] ?? null;
        if (!is_array($rows)) {
            return [];
        }

        $parsed = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $uuid = $this->stringOrNull($row, 'uuid');
            if ($uuid === null) {
                continue;
            }
            $parsed[] = [
                'uuid' => $uuid,
                'mid' => $this->readMid($row),
                'name' => (string)($this->stringOrNull($row, 'name') ?? ''),
                'email' => (string)($this->stringOrNull($row, 'email') ?? ''),
                'pgKey' => $this->readPgKey($row),
                'onboardingCompleted' => $this->boolValue($row, 'onboarding_completed'),
            ];
        }

        return $parsed;
    }

    /**
     * The MID appears as "mid" on both create and listing rows, and additionally as
     * "identifier" on the full record. Same number; read whichever is present.
     *
     * @param array<string, mixed> $account
     * @return string
     */
    private function readMid(array $account): string
    {
        foreach (['mid', 'identifier'] as $key) {
            if (isset($account[$key]) && is_scalar($account[$key]) && (string)$account[$key] !== '') {
                return (string)$account[$key];
            }
        }
        return '';
    }

    /**
     * @param array<string, mixed> $account
     * @return string|null
     */
    private function readPgKey(array $account): ?string
    {
        $detail = $account['product_account_detail'] ?? null;
        if (!is_array($detail)) {
            return null;
        }
        return $this->stringOrNull($detail, 'pg_key');
    }

    /**
     * kyc_status is an object on the full record. Tolerate a bare string too, so a
     * shape change on PayU's side degrades to "we read something" rather than a crash.
     *
     * @param array<string, mixed> $account
     * @return string|null
     */
    private function readKycStatus(array $account): ?string
    {
        $kyc = $account['kyc_status'] ?? null;
        if (is_array($kyc)) {
            return $this->stringOrNull($kyc, 'status') ?? $this->stringOrNull($kyc, 'kyc_status');
        }
        return is_scalar($kyc) && (string)$kyc !== '' ? (string)$kyc : null;
    }

    /**
     * @param array<string, mixed> $source
     * @param string $key
     * @return string|null
     */
    private function stringOrNull(array $source, string $key): ?string
    {
        $value = $source[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }
        $value = (string)$value;
        return $value === '' ? null : $value;
    }

    /**
     * PayU sends true on the full record and 1 on listing rows for the same field.
     *
     * @param array<string, mixed> $source
     * @param string $key
     * @return bool
     */
    private function boolValue(array $source, string $key): bool
    {
        $value = $source[$key] ?? false;
        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param array<string, mixed> $source
     * @param string $key
     * @return int
     */
    private function intValue(array $source, string $key): int
    {
        $value = $source[$key] ?? 0;
        return is_numeric($value) ? (int)$value : 0;
    }
}
