<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Logger\Logger;
use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Exception\OnboardingLeaseLostException;
use RKD\PayuSplit\Split\Exception\OnboardingPayloadException;
use RKD\PayuSplit\Split\Exception\OnboardingStepFailedException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingCredentialsException;
use RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException;
use RKD\PayuSplit\Split\Model\OnboardingConfig;
use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Walks one approved vendor from QUEUED to AWAITING_ESIGN at PayU (S-3).
 *
 *   QUEUED         -> a child merchant exists for this vendor (adopted or created)
 *   CREATED        -> PayU holds the settlement bank account
 *   BANK_ATTACHED  -> handed to staff for the Schedule C click, and they are told
 *
 * This class owns the state machine: one run per vendor at a time (a compare-and-set lease),
 * the approval and role checks before any call, a save after every step so a crash resumes
 * from the last completed one, and what each failure means. The PayU calls themselves, and
 * the rules that keep them from doing damage, live in OnboardingSteps.
 *
 * Failures: a transport error or 5xx keeps the state and retries with backoff; after the last
 * retry, or on a 4xx, bad data, wrong credentials or an orphan, the vendor goes FAILED and
 * staff are alerted. Nothing here writes a PAN, bank number or token to a log or to
 * last_error (N-5).
 */
class OnboardingProcessor
{
    public const OUTCOME_DISABLED = 'disabled';
    public const OUTCOME_NOT_CONFIGURED = 'not-configured';
    public const OUTCOME_NO_ROW = 'no-onboarding-row';
    public const OUTCOME_NOTHING_TO_DO = 'nothing-to-do';
    public const OUTCOME_NOT_DUE = 'not-due-yet';
    public const OUTCOME_LOCKED = 'locked-by-another-run';
    public const OUTCOME_AWAITING_ESIGN = 'awaiting-esign';
    public const OUTCOME_RETRY_SCHEDULED = 'retry-scheduled';
    public const OUTCOME_FAILED = 'failed';
    public const OUTCOME_PAUSED = 'paused-configuration-error';

    /** Minutes to wait after the 1st, 2nd, ... consecutive retryable failure. */
    private const BACKOFF_MINUTES = [1, 5, 15, 60, 360];

    /** Every PayU call has a 45 s timeout and a run makes at most four; 10 minutes is ample. */
    private const LEASE_SECONDS = 600;

    /**
     * @param OnboardingConfig $config
     * @param SubmerchantRepositoryInterface $repository
     * @param OnboardingClaim $claim
     * @param VendorOnboardingDataReader $reader
     * @param OnboardingSteps $steps
     * @param OnboardingAlerter $alerter
     * @param DateTime $dateTime
     * @param Logger $logger
     * @param OnboardingPause $pause
     */
    public function __construct(
        private readonly OnboardingConfig $config,
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly OnboardingClaim $claim,
        private readonly VendorOnboardingDataReader $reader,
        private readonly OnboardingSteps $steps,
        private readonly OnboardingAlerter $alerter,
        private readonly DateTime $dateTime,
        private readonly Logger $logger,
        private readonly OnboardingPause $pause
    ) {
    }

    /**
     * Take one vendor as far as it can go right now.
     *
     * @param int $sellerId
     * @param bool $ignoreSchedule True from the CLI: run now even inside a backoff wait
     * @return string One of the OUTCOME_* constants
     */
    public function process(int $sellerId, bool $ignoreSchedule = false): string
    {
        $claimed = $this->claimRow($sellerId, $ignoreSchedule);
        if (is_string($claimed)) {
            return $claimed;
        }
        [$row, $lease] = $claimed;
        $entityId = (int)$row->getId();

        // Renew the lease right before each irreversible call. If another run has taken the
        // vendor meanwhile (PayU was slow and our lease expired), this throws and nothing is sent.
        $keepLease = function () use ($entityId, &$lease): void {
            $renewed = $this->leaseUntil($lease);
            if (!$this->claim->renew($entityId, $lease, $renewed)) {
                throw new OnboardingLeaseLostException('The onboarding lease was lost before a PayU call.');
            }
            $lease = $renewed;
        };

        $startState = $row->getOnboardingState();
        try {
            $outcome = $this->advance($row, $keepLease);
        } catch (OnboardingLeaseLostException $e) {
            $this->logger->warning('PayU onboarding: lease lost, stopping without a PayU call', [
                'seller_id' => $sellerId,
            ]);
            $lease = null;
            return self::OUTCOME_LOCKED;
        } catch (\Throwable $t) {
            return $this->outcomeOfFailure($row, $t);
        } finally {
            if ($lease !== null) {
                $this->releaseLease($entityId, $lease);
            }
        }

        $this->liftPause($startState);
        return $outcome;
    }

    /**
     * After a completed run, and outside process()'s try on purpose: lifting the pause must never
     * turn a vendor's successful run into a recorded failure (wave-3 C-L4). OnboardingPause does
     * not throw.
     *
     * @param string $startState
     * @return void
     */
    private function liftPause(string $startState): void
    {
        if ($this->pause->liftAfterRunFrom($startState)) {
            $this->logger->info('PayU onboarding: pause lifted — PayU accepted our credentials');
        }
    }

    /**
     * What a failure means for the vendor. The order matters: the credentials exception is a
     * subclass of the retryable one, and a bad secret must pause the scan, not retry one vendor.
     *
     * @param Submerchant $row
     * @param \Throwable $failure
     * @return string
     */
    private function outcomeOfFailure(Submerchant $row, \Throwable $failure): string
    {
        if ($failure instanceof OnboardingStepFailedException) {
            return $failure->getErrorClass() === Submerchant::ERROR_CONFIGURATION
                ? $this->pause($row, $failure->getMessage())
                : $this->fail($row, $failure->getErrorClass(), $failure->getMessage());
        }
        if ($failure instanceof OnboardingPayloadException) {
            return $this->fail($row, Submerchant::ERROR_BUSINESS, $failure->getMessage());
        }
        if ($failure instanceof PayuOnboardingCredentialsException) {
            return $this->pause($row, $failure->getMessage());
        }
        if ($failure instanceof PayuOnboardingRequestException) {
            return $this->retryLater($row, $failure->getMessage());
        }
        $this->logger->error('PayU onboarding: unexpected error', [
            'seller_id' => $row->getSellerId(),
            'exception' => get_class($failure),
        ]);
        return $this->retryLater($row, 'Unexpected ' . get_class($failure) . ' — see var/log.');
    }

    /**
     * Whether the scan is paused after PayU rejected our credentials.
     *
     * @return bool
     */
    public function isPaused(): bool
    {
        return $this->pause->isActive();
    }

    /**
     * Everything that must hold before this run may touch the vendor, ending with the lease.
     *
     * @param int $sellerId
     * @param bool $ignoreSchedule
     * @return array{0: Submerchant, 1: string}|string The claimed, freshly read row and its lease
     *                                                   — or the outcome explaining why not
     */
    private function claimRow(int $sellerId, bool $ignoreSchedule): array|string
    {
        $blocked = $this->scanBlocked($ignoreSchedule);
        if ($blocked !== null) {
            return $blocked;
        }

        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null) {
            return self::OUTCOME_NO_ROW;
        }
        $notWorkable = $this->rowNotWorkable($row, $ignoreSchedule);
        if ($notWorkable !== null) {
            return $notWorkable;
        }

        $lease = $this->leaseUntil(null);
        $now = $this->dateTime->gmtDate('Y-m-d H:i:s');
        if (!$this->claim->claim((int)$row->getId(), $row->getOnboardingState(), $now, $lease)) {
            return self::OUTCOME_LOCKED;
        }

        // Re-read under the lease: another run may have moved the row between our first read
        // and the claim, and the claim itself changed locked_until.
        return [$this->repository->getBySellerId($sellerId) ?? $row, $lease];
    }

    /**
     * @param bool $ignoreSchedule
     * @return string|null Why no vendor may be worked right now, or null
     */
    private function scanBlocked(bool $ignoreSchedule): ?string
    {
        if (!$this->config->isEnabled()) {
            return self::OUTCOME_DISABLED;
        }
        if (!$this->config->isConfigured()) {
            return self::OUTCOME_NOT_CONFIGURED;
        }
        return !$ignoreSchedule && $this->isPaused() ? self::OUTCOME_PAUSED : null;
    }

    /**
     * @param Submerchant $row
     * @param bool $ignoreSchedule
     * @return string|null Why this vendor is not worked now, or null
     */
    private function rowNotWorkable(Submerchant $row, bool $ignoreSchedule): ?string
    {
        if (!$row->isWorkable()) {
            return self::OUTCOME_NOTHING_TO_DO;
        }
        $nextAttemptAt = $row->getNextAttemptAt();
        $now = $this->dateTime->gmtDate('Y-m-d H:i:s');
        return !$ignoreSchedule && $nextAttemptAt !== null && $nextAttemptAt > $now ? self::OUTCOME_NOT_DUE : null;
    }

    /**
     * A lease expiry LEASE_SECONDS from now, and always strictly later than the one being
     * replaced: MySQL reports 0 rows for an UPDATE that changes nothing, which would read as a
     * lost lease.
     *
     * @param string|null $current
     * @return string
     */
    private function leaseUntil(?string $current): string
    {
        $until = $this->dateTime->gmtTimestamp() + self::LEASE_SECONDS;
        if ($current !== null) {
            $until = max($until, (int)strtotime($current . ' UTC') + 1);
        }
        return $this->dateTime->gmtDate('Y-m-d H:i:s', $until);
    }

    /**
     * @param Submerchant $row
     * @param callable $keepLease
     * @return string
     */
    private function advance(Submerchant $row, callable $keepLease): string
    {
        $sellerId = $row->getSellerId();

        if (!$this->reader->isApprovedSeller($sellerId)) {
            throw OnboardingStepFailedException::business(
                'Vendor is not approved in Marketplace any more, so nothing was sent to PayU.'
            );
        }
        if ($this->reader->hasAffiliateRecord($sellerId)) {
            throw OnboardingStepFailedException::business(
                'This customer also holds an affiliate account. A customer can be an affiliate or a '
                . 'vendor, never both, so no PayU account was created.'
            );
        }

        if ($row->getOnboardingState() === Submerchant::STATE_QUEUED) {
            $this->steps->ensureChildMerchant($row, $keepLease);
            $this->transition($row, Submerchant::STATE_CREATED);
        }
        if ($row->getOnboardingState() === Submerchant::STATE_CREATED) {
            $this->steps->ensureBankAttached($row, $keepLease);
            $this->transition($row, Submerchant::STATE_BANK_ATTACHED);
        }
        if ($row->getOnboardingState() === Submerchant::STATE_BANK_ATTACHED) {
            $this->handToStaff($row);
            return self::OUTCOME_AWAITING_ESIGN;
        }
        return self::OUTCOME_NOTHING_TO_DO;
    }

    /**
     * BANK_ATTACHED -> AWAITING_ESIGN, and tell a human. PayU has no API for the Schedule C
     * e-sign email; someone has to click it in PayU's dashboard (R-4).
     *
     * @param Submerchant $row
     * @return void
     */
    private function handToStaff(Submerchant $row): void
    {
        $this->transition($row, Submerchant::STATE_AWAITING_ESIGN);

        $sellerId = $row->getSellerId();
        $this->alerter->alert(
            $sellerId,
            $this->reader->readShopName($sellerId),
            'send the Schedule C e-sign',
            sprintf(
                'The PayU sub-merchant (MID ending %s) is created and its bank account has been submitted to '
                . 'PayU. In PayU\'s dashboard, open this sub-merchant and send the Schedule C e-sign email, '
                . 'then click "Mark e-sign sent" for this vendor on Sales > PayU Submerchants.',
                substr((string)$row->getPayuMid(), -4)
            ),
            false
        );
    }

    /**
     * @param Submerchant $row
     * @param string $target
     * @return void
     */
    private function transition(Submerchant $row, string $target): void
    {
        if (!$row->canTransitionTo($target)) {
            throw new \LogicException(sprintf(
                'Illegal onboarding transition %s -> %s for seller %d.',
                $row->getOnboardingState(),
                $target,
                $row->getSellerId()
            ));
        }
        $row->addData([
            'onboarding_state' => $target,
            'attempts' => 0,
            'last_error' => null,
            'error_class' => null,
            'next_attempt_at' => null,
        ]);
        $this->repository->save($row);
        $this->logger->info('PayU onboarding: state advanced', [
            'seller_id' => $row->getSellerId(),
            'state' => $target,
        ]);
    }

    /**
     * @param Submerchant $row
     * @param string $message
     * @return string
     */
    private function retryLater(Submerchant $row, string $message): string
    {
        $attempts = $row->getAttempts() + 1;
        if ($attempts > count(self::BACKOFF_MINUTES)) {
            return $this->fail(
                $row,
                Submerchant::ERROR_RETRYABLE,
                sprintf('Gave up after %d attempts. Last error: %s', $attempts, $message)
            );
        }

        $delay = self::BACKOFF_MINUTES[$attempts - 1] * 60;
        $row->addData([
            'attempts' => $attempts,
            'last_error' => OnboardingErrorText::clean($message),
            'error_class' => Submerchant::ERROR_RETRYABLE,
            'next_attempt_at' => $this->dateTime->gmtDate('Y-m-d H:i:s', $this->dateTime->gmtTimestamp() + $delay),
        ]);
        $this->repository->save($row);
        $this->logger->warning('PayU onboarding: attempt failed, will retry', [
            'seller_id' => $row->getSellerId(),
            'state' => $row->getOnboardingState(),
            'attempts' => $attempts,
            'retry_in_minutes' => self::BACKOFF_MINUTES[$attempts - 1],
        ]);
        return self::OUTCOME_RETRY_SCHEDULED;
    }

    /**
     * @param Submerchant $row
     * @param string $errorClass
     * @param string $message
     * @return string
     */
    private function fail(Submerchant $row, string $errorClass, string $message): string
    {
        $from = $row->getOnboardingState();
        $row->addData([
            'onboarding_state' => Submerchant::STATE_FAILED,
            'last_error' => OnboardingErrorText::clean($message),
            'error_class' => $errorClass,
            'next_attempt_at' => null,
        ]);
        $this->repository->save($row);

        $sellerId = $row->getSellerId();
        $this->logger->error('PayU onboarding: vendor failed', [
            'seller_id' => $sellerId,
            'from_state' => $from,
            'error_class' => $errorClass,
        ]);
        $this->alerter->alert(
            $sellerId,
            $this->reader->readShopName($sellerId),
            'onboarding failed',
            OnboardingErrorText::clean($message) . ' Fix the cause, then use "Retry" on Sales > PayU Submerchants.',
            true
        );
        return self::OUTCOME_FAILED;
    }

    /**
     * Only this run's lease, and only the lease column — see OnboardingClaim::release().
     *
     * @param int $entityId
     * @param string $lease
     * @return void
     */
    private function releaseLease(int $entityId, string $lease): void
    {
        try {
            $this->claim->release($entityId, $lease);
        } catch (\Throwable $t) {
            // The lease expires on its own; losing the release only delays the next run.
            $this->logger->warning('PayU onboarding: could not release the lease', ['entity_id' => $entityId]);
        }
    }

    /**
     * Credentials or scope are wrong: nothing per-vendor will fix it. Keep the vendor's state,
     * pause the whole scan for an hour and tell someone once.
     *
     * @param Submerchant $row
     * @param string $message
     * @return string
     */
    private function pause(Submerchant $row, string $message): string
    {
        $alreadyPaused = $this->pause->isActive();
        $until = $this->pause->start();

        $row->addData([
            'last_error' => OnboardingErrorText::clean($message),
            'error_class' => Submerchant::ERROR_CONFIGURATION,
            'next_attempt_at' => $until,
        ]);
        $this->repository->save($row);
        $this->logger->error('PayU onboarding: paused — PayU rejected our credentials', ['until' => $until]);

        if (!$alreadyPaused) {
            $sellerId = $row->getSellerId();
            $this->alerter->alert(
                $sellerId,
                $this->reader->readShopName($sellerId),
                'paused — credentials rejected',
                OnboardingErrorText::clean($message) . ' No vendor is being processed until ' . $until . ' UTC. Fix the '
                . 'onboarding credentials in Stores > Configuration, then run '
                . sprintf('bin/magento fab:payu:onboarding:run --seller=%d', $sellerId)
                . ' — the pause lifts when that run completes, or on its own at the time above.',
                true
            );
        }
        return self::OUTCOME_PAUSED;
    }
}
