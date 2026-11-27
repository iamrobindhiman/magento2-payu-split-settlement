<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * A vendor's settlement account, decrypted, for the few lines between reading it and
 * sending it to PayU.
 *
 * Kept off ChildMerchantDraft on purpose (R-2, N-5): the draft travels through the whole
 * create path, this only exists for the bank call. __debugInfo() masks the account number
 * so a var_dump or an exception dump cannot print it.
 */
class SettlementBankDetail
{
    /**
     * @param string $accountNumber Plaintext; '' when missing or unreadable
     * @param string $ifsc
     * @param string $holderName
     */
    public function __construct(
        private readonly string $accountNumber,
        private readonly string $ifsc,
        private readonly string $holderName
    ) {
    }

    /**
     * @return string
     */
    public function getAccountNumber(): string
    {
        return trim($this->accountNumber);
    }

    /**
     * @return string
     */
    public function getIfsc(): string
    {
        return strtoupper(trim($this->ifsc));
    }

    /**
     * @return string
     */
    public function getHolderName(): string
    {
        return trim($this->holderName);
    }

    /**
     * @return array<string, string>
     */
    public function __debugInfo(): array
    {
        $account = $this->getAccountNumber();
        return [
            'accountNumber' => $account === '' ? '' : '****' . substr($account, -4),
            'ifsc' => $this->getIfsc(),
            'holderName' => $this->getHolderName(),
        ];
    }
}
