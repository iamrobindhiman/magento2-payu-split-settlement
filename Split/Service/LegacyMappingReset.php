<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Staff "Reset legacy mapping" (Robin, 2026-09-24): for a hand-typed mapping whose merchant key
 * names no PayU account under our parent — a sandbox key left behind, a typo. Such a row can
 * otherwise never be deleted, linked or retried (wave-4 integrity F2).
 *
 * The reset clears the key and MID, switches payouts off, and queues the vendor, so onboarding
 * creates their PayU account (while the onboarding switch is on). That is exactly the second-account
 * outcome the delete guard exists to prevent — so before anything changes it reads PayU in full and
 * refuses when the account plainly exists: the row's MID is one of our children, or a child carries
 * the vendor's current email, or a child carries the row's merchant key (use "Link PayU account"
 * then). The listing carries each child's key; a listing row without one makes the check
 * impossible, so the reset is refused rather than guessed (wave-6 integrity F1).
 * Both PayU calls are reads.
 */
class LegacyMappingReset
{
    /**
     * @param SubmerchantRepositoryInterface $repository
     * @param SubmerchantResource $resource
     * @param VendorOnboardingDataReader $reader
     * @param ExistingChildFinder $childFinder
     * @param SettlementAuditLogger $auditLogger
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly SubmerchantResource $resource,
        private readonly VendorOnboardingDataReader $reader,
        private readonly ExistingChildFinder $childFinder,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * @param int $sellerId
     * @return void
     * @throws LocalizedException When the reset is refused; nothing is written
     * @throws \RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException When PayU cannot be read
     */
    public function reset(int $sellerId): void
    {
        $row = $this->legacyRow($sellerId);
        $this->assertNoAccountAtPayu($row);
        $this->write($row);
    }

    /**
     * @param int $sellerId
     * @return Submerchant
     * @throws LocalizedException
     */
    private function legacyRow(int $sellerId): Submerchant
    {
        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null || !$row->isLegacyMapping()
            || $row->getOnboardingState() !== Submerchant::STATE_NOT_STARTED || $row->getPayuEmail() !== null
        ) {
            throw new LocalizedException(__('Seller %1 has no hand-entered legacy mapping to reset.', $sellerId));
        }
        if (!$this->reader->isApprovedSeller($sellerId)) {
            throw new LocalizedException(__('Seller %1 is not an approved seller.', $sellerId));
        }
        if ($this->reader->hasAffiliateRecord($sellerId)) {
            throw new LocalizedException(__(
                'Customer %1 also holds an affiliate account. A customer can be a seller or an affiliate, '
                . 'never both, so the mapping was not reset.',
                $sellerId
            ));
        }
        return $row;
    }

    /**
     * @param Submerchant $row
     * @return void
     * @throws LocalizedException
     */
    private function assertNoAccountAtPayu(Submerchant $row): void
    {
        $mid = trim((string)$row->getPayuMid());
        $key = $row->getMerchantKey();
        $email = strtolower(trim($this->reader->readEmail($row->getSellerId())));
        foreach ($this->childFinder->listChildren() as $child) {
            if ($child['pgKey'] === null || $child['pgKey'] === '') {
                throw new LocalizedException(__(
                    'PayU\'s list of sub-merchants did not include every merchant key, so it cannot be ruled out '
                    . 'that seller %1\'s key is a real account. Nothing was reset.',
                    $row->getSellerId()
                ));
            }
            // Loose on purpose: a key that differs only in case or spacing is more likely a typo of a real
            // account than a dead one, and refusing costs only a Link (wave-7 integrity F6).
            if (strcasecmp(trim($child['pgKey']), trim($key)) === 0
                || ($mid !== '' && $child['mid'] === $mid)
                || ($email !== '' && strtolower($child['email']) === $email)
            ) {
                throw new LocalizedException(__(
                    'PayU holds a sub-merchant for seller %1 (MID ending %2) under our parent account, so this '
                    . 'mapping is not dead. Use "Link PayU account" with that MID instead. Nothing was reset.',
                    $row->getSellerId(),
                    substr($child['mid'], -4)
                ));
            }
        }
    }

    /**
     * Compare-and-set on the legacy state the checks were made against, with the audit row in the
     * same transaction: a concurrent edit or Link leaves this writing nothing.
     *
     * @param Submerchant $row
     * @return void
     * @throws LocalizedException
     */
    private function write(Submerchant $row): void
    {
        $mid = $row->getPayuMid();
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            $written = (int)$connection->update(
                $this->resource->getMainTable(),
                [
                    'payu_merchant_key' => null,
                    'payu_mid' => null,
                    'is_active' => 0,
                    'onboarding_state' => Submerchant::STATE_QUEUED,
                    'attempts' => 0,
                    'last_error' => null,
                    'error_class' => null,
                    'next_attempt_at' => null,
                ],
                [
                    'entity_id = ?' => (int)$row->getId(),
                    'payu_uuid IS NULL',
                    'payu_email IS NULL',
                    'onboarding_state = ?' => Submerchant::STATE_NOT_STARTED,
                    'payu_merchant_key = ?' => $row->getMerchantKey(),
                    // Pin what the checks above were made against (wave-6 security L-1 / integrity F4).
                    'seller_id = ?' => $row->getSellerId(),
                    // Spelled out: a NULL bound into "<=> ?" is quoted as '' and would never match.
                    $mid === null ? 'payu_mid IS NULL' : 'payu_mid = ?' => $mid,
                    'locked_until IS NULL OR locked_until < ?' => $this->dateTime->gmtDate('Y-m-d H:i:s'),
                ]
            );
            if ($written !== 1) {
                throw new LocalizedException(__(
                    'Seller %1 changed while this was being checked. Reload the grid and try again.',
                    $row->getSellerId()
                ));
            }
            $this->auditLogger->record(
                AuditLog::ACTION_LEGACY_RESET,
                AuditLog::ENTITY_SUBMERCHANT_ROW,
                (int)$row->getId(),
                null,
                'legacy',
                Submerchant::STATE_QUEUED,
                sprintf(
                    'seller %d; cleared key ending %s, MID ending %s; payouts off; queued for onboarding',
                    $row->getSellerId(),
                    substr($row->getMerchantKey(), -4),
                    substr((string)$row->getPayuMid(), -4) ?: '-'
                )
            );
            $connection->commit();
        } catch (\Throwable $t) {
            $connection->rollBack();
            throw $t;
        }
    }
}
