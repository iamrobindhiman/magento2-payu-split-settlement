<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;

/**
 * The two database operations the onboarding worker needs beyond load/save:
 * finding the vendors that are due, and claiming one so only one run works on it.
 *
 * The claim is a compare-and-set lease, the same shape as ReleaseProcessor's release claim.
 * Cron and the CLI command can both reach a vendor; without the lease two overlapping runs
 * would both see QUEUED and both call create — and a PayU child cannot be deleted. The lease
 * expires on its own, so a run that dies mid-call does not strand the vendor.
 */
class OnboardingClaim
{
    /**
     * @param SubmerchantResource $resource
     * @param CollectionFactory $collectionFactory
     */
    public function __construct(
        private readonly SubmerchantResource $resource,
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Take the lease on one row, but only if it is still in the state the caller saw and no
     * live lease is held. 0 rows affected means someone else has it, or it moved on.
     *
     * @param int $entityId
     * @param string $expectedState
     * @param string $now UTC Y-m-d H:i:s
     * @param string $leaseUntil UTC Y-m-d H:i:s
     * @return bool
     */
    public function claim(int $entityId, string $expectedState, string $now, string $leaseUntil): bool
    {
        $claimed = (int)$this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            ['locked_until' => $leaseUntil],
            [
                'entity_id = ?' => $entityId,
                'onboarding_state = ?' => $expectedState,
                'locked_until IS NULL OR locked_until < ?' => $now,
            ]
        );
        return $claimed === 1;
    }

    /**
     * Push this run's lease further out, but only if this run still holds it. Called right
     * before each irreversible PayU call: a run that lost its lease (a slow PayU pushed it past
     * the expiry and another run claimed the vendor) must not make the call.
     *
     * @param int $entityId
     * @param string $currentLease
     * @param string $newLease
     * @return bool False when the lease is no longer ours
     */
    public function renew(int $entityId, string $currentLease, string $newLease): bool
    {
        $renewed = (int)$this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            ['locked_until' => $newLease],
            ['entity_id = ?' => $entityId, 'locked_until = ?' => $currentLease]
        );
        return $renewed === 1;
    }

    /**
     * Give the lease back — only if it is still this run's. Touches no other column, so an
     * admin action taken while this run was sending an alert is never overwritten.
     *
     * @param int $entityId
     * @param string $lease
     * @return void
     */
    public function release(int $entityId, string $lease): void
    {
        $this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            ['locked_until' => null],
            ['entity_id = ?' => $entityId, 'locked_until = ?' => $lease]
        );
    }

    /**
     * Seller ids with onboarding work to do now, oldest first.
     *
     * @param string $now UTC Y-m-d H:i:s
     * @param int $limit
     * @return int[]
     */
    public function dueSellerIds(string $now, int $limit): array
    {
        $collection = $this->collectionFactory->create()
            ->addFieldToFilter('onboarding_state', ['in' => Submerchant::WORKABLE_STATES])
            ->addFieldToFilter('next_attempt_at', [['null' => true], ['lteq' => $now]])
            // Not a hand-typed legacy mapping (key, no uuid): the processor never works one, and left
            // in they sort first by updated_at and starve the batch (wave-2 N-6).
            ->addFieldToFilter(
                ['payu_merchant_key', 'payu_merchant_key', 'payu_uuid'],
                [['null' => true], ['eq' => ''], ['notnull' => true]]
            )
            ->setOrder('updated_at', 'ASC')
            ->setPageSize($limit);

        return array_map('intval', $collection->getColumnValues('seller_id'));
    }
}
