<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Model\SubmerchantFactory;
use Magento\Framework\Exception\AlreadyExistsException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * The state changes that come from people rather than from PayU: a vendor is approved,
 * a failed vendor is retried, staff have sent the Schedule C e-sign.
 *
 * None of these calls PayU. Approval runs inside Webkul's mass-approve loop, which has no
 * per-item error handling — so enqueue() only ever writes one row (N-1, N-2). The cron does
 * the network work.
 */
class OnboardingQueue
{
    public const RESULT_QUEUED = 'queued';
    public const RESULT_REQUEUED = 'requeued';
    public const RESULT_LEGACY = 'legacy-mapping-left-alone';
    public const RESULT_ALREADY = 'already-in-progress';

    /**
     * @param SubmerchantRepositoryInterface $repository
     * @param SubmerchantFactory $submerchantFactory
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly SubmerchantFactory $submerchantFactory,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Mark an approved vendor for onboarding. Safe to call any number of times (N-3, T-07):
     * a vendor already in progress or done is left exactly as it is.
     *
     * @param int $sellerId
     * @return string One of the RESULT_* constants
     */
    public function enqueue(int $sellerId): string
    {
        $row = $this->repository->getBySellerId($sellerId);

        if ($row === null) {
            $row = $this->submerchantFactory->create();
            $row->setData([
                'seller_id' => $sellerId,
                'onboarding_state' => Submerchant::STATE_QUEUED,
                'kyc_status' => Submerchant::KYC_PENDING,
                // Never active from here. Production's older split engine pays any active row
                // and has no switch; readiness lives in onboarding_state until S-5.
                'is_active' => 0,
            ]);
            try {
                $this->repository->save($row);
            } catch (AlreadyExistsException $e) {
                // A concurrent approval wrote the row first. Theirs stands.
                return self::RESULT_ALREADY;
            }
            return self::RESULT_QUEUED;
        }

        if ($row->isLegacyMapping()) {
            return self::RESULT_LEGACY;
        }

        return match ($row->getOnboardingState()) {
            Submerchant::STATE_NOT_STARTED => $this->requeue($row, self::RESULT_QUEUED),
            Submerchant::STATE_FAILED => $this->requeue($row, self::RESULT_REQUEUED),
            default => self::RESULT_ALREADY,
        };
    }

    /**
     * Staff "Retry" on a failed vendor (R-8). No re-approval, and safe: the worker adopts a
     * child PayU already holds and never re-sends a bank PayU already has.
     *
     * $parentHasNoChildren: the caller has just read PayU's listing and it holds no sub-merchants
     * at all. Then a create this vendor may have sent cannot have made an account, so its
     * create-sent mark is dropped — the one way out for a vendor whose create failed ambiguously on
     * a parent with no children (wave-4 correctness F3). Only for a vendor with no PayU account.
     *
     * @param int $sellerId
     * @param bool $parentHasNoChildren
     * @return void
     * @throws LocalizedException When the vendor is not in FAILED
     */
    public function retry(int $sellerId, bool $parentHasNoChildren = false): void
    {
        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null || $row->getOnboardingState() !== Submerchant::STATE_FAILED) {
            throw new LocalizedException(__('Only a vendor whose PayU onboarding failed can be retried.'));
        }
        if ($row->isLegacyMapping()) {
            // A key typed in by hand, with no PayU uuid: onboarding would create a SECOND,
            // undeletable account for this vendor. The same rule enqueue() applies (D8).
            throw new LocalizedException(__(
                'Seller %1 has a hand-entered PayU merchant key, so onboarding will not create an account '
                . 'for them. Link their existing PayU account instead.',
                $sellerId
            ));
        }
        if ($parentHasNoChildren && $row->getPayuUuid() === null) {
            $row->setData('payu_email', null);
        }
        $this->requeue($row, self::RESULT_REQUEUED);
    }

    /**
     * Record that staff clicked "send Schedule C" in PayU's dashboard (R-4, T-12).
     *
     * @param int $sellerId
     * @param int $adminUserId
     * @return void
     * @throws LocalizedException When the vendor is not waiting for it
     */
    public function markEsignSent(int $sellerId, int $adminUserId): void
    {
        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null || !$row->isAwaitingEsign()) {
            throw new LocalizedException(__('This vendor is not waiting for the Schedule C e-sign.'));
        }
        if ($row->isEsignSent()) {
            throw new LocalizedException(__('The Schedule C e-sign was already marked as sent.'));
        }
        $row->setData('esign_sent_at', $this->dateTime->gmtDate());
        $row->setData('esign_sent_by', $adminUserId > 0 ? $adminUserId : null);
        $this->repository->save($row);
    }

    /**
     * @param Submerchant $row
     * @param string $result
     * @return string
     */
    private function requeue(Submerchant $row, string $result): string
    {
        $row->addData([
            'onboarding_state' => Submerchant::STATE_QUEUED,
            'attempts' => 0,
            'last_error' => null,
            'error_class' => null,
            'next_attempt_at' => null,
            // locked_until is deliberately left alone: a run may still hold the lease (sending
            // the FAILED alert). Clearing it would let a second run start alongside the first.
        ]);
        $this->repository->save($row);
        return $result;
    }
}
