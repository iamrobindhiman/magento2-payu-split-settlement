<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * What PayU holds and thinks about a child merchant's settlement account.
 *
 * The attempt counters are the load-bearing part. PayU accepts the bank detail ONCE
 * and then answers 422 "Bank details already submitted"; the counters are the only
 * authoritative answer to "has this already been sent?", and they come from PayU
 * rather than from our own state column. Our row can be wrong — a crash between
 * PayU's write and ours leaves us believing no bank was sent when one was. Theirs
 * cannot (N-4).
 */
class ChildMerchantBank
{
    private const VERIFIED = 'Success';

    /**
     * @param string|null $verificationStatus "Pending" at attach, "Success" once the penny drop lands
     * @param string|null $pennyDepositStatus "Not Initiated" at attach, then "Success"
     * @param int $updateAttemptCount PayU's count of bank submissions made
     * @param int $updateAttemptLeft Submissions remaining (observed: 11 at creation, 10 after one)
     */
    public function __construct(
        private readonly ?string $verificationStatus = null,
        private readonly ?string $pennyDepositStatus = null,
        private readonly int $updateAttemptCount = 0,
        private readonly int $updateAttemptLeft = 0
    ) {
    }

    /**
     * @return string|null
     */
    public function getVerificationStatus(): ?string
    {
        return $this->verificationStatus;
    }

    /**
     * @return string|null
     */
    public function getPennyDepositStatus(): ?string
    {
        return $this->pennyDepositStatus;
    }

    /**
     * @return int
     */
    public function getUpdateAttemptCount(): int
    {
        return $this->updateAttemptCount;
    }

    /**
     * @return int
     */
    public function getUpdateAttemptLeft(): int
    {
        return $this->updateAttemptLeft;
    }

    /**
     * Whether PayU already holds an account for this child — ask before sending one.
     *
     * @return bool
     */
    public function isAttached(): bool
    {
        return $this->updateAttemptCount > 0;
    }

    /**
     * Whether PayU's penny drop confirmed the account. Necessary for readiness but,
     * on its own, nowhere near sufficient — see ChildMerchantStatus.
     *
     * @return bool
     */
    public function isVerified(): bool
    {
        return $this->verificationStatus === self::VERIFIED;
    }
}
