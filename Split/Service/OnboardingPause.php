<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\FlagManager;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The whole-scan pause set when PayU rejects our onboarding credentials.
 *
 * Every vendor would fail the same way, so the scan stops for an hour instead of walking the whole
 * queue into FAILED one vendor at a time. Kept in a flag, not per vendor: it is a fact about our
 * credentials, and the cron checks it before touching anyone.
 */
class OnboardingPause
{
    public const FLAG = 'rkd_payu_onboarding_paused_until';
    private const SECONDS = 3600;

    /**
     * @param FlagManager $flagManager
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @return bool
     */
    public function isActive(): bool
    {
        $until = (string)$this->flagManager->getFlagData(self::FLAG);
        return $until !== '' && $until > $this->dateTime->gmtDate('Y-m-d H:i:s');
    }

    /**
     * Pause (or extend the pause) for an hour from now.
     *
     * @return string The UTC time the pause ends
     */
    public function start(): string
    {
        $until = $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + self::SECONDS);
        $this->flagManager->saveFlag(self::FLAG, $until);
        return $until;
    }

    /**
     * Lift the pause after a run that got an answer from PayU: that proves the credentials work
     * again, so there is no reason to sit out the rest of the hour. A run from QUEUED or CREATED
     * always reads from PayU (the listing or the child); one from BANK_ATTACHED makes no call and
     * proves nothing.
     *
     * Never throws: it runs after a vendor's run has already succeeded, and a flag-table error must
     * not be recorded against that vendor. A pause that could not be lifted ends on its own.
     *
     * @param string $startState The state the vendor's run began in
     * @return bool Whether a pause was in force and has been lifted
     */
    public function liftAfterRunFrom(string $startState): bool
    {
        if (!in_array($startState, [Submerchant::STATE_QUEUED, Submerchant::STATE_CREATED], true)) {
            return false;
        }
        try {
            if (!$this->isActive()) {
                return false;
            }
            $this->flagManager->deleteFlag(self::FLAG);
            return true;
        } catch (\Throwable $t) {
            return false;
        }
    }
}
