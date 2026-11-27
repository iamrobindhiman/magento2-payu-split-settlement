<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Service;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\AuditLog;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant as SubmerchantResource;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Stdlib\DateTime\DateTime;

/**
 * Staff "Forget create mark": clear a vendor's recorded PayU create (payu_email) when it only gets in
 * the way (wave-6 integrity F3; hardened wave-7 integrity F3/F4, security L-2).
 *
 * PayU's full listing is always read first, and the mark is cleared only if no account under that
 * email could be this vendor's:
 *   - a row whose own account IS the child under the mark has a dead mark (CreateMarkClaims) — clear;
 *   - otherwise any child under the mark that no other row holds may be this vendor's lost create. On a
 *     FAILED row with no account, the mark is its only adoption path (wave-8 integrity M-1); on a row
 *     linked to a different account, the mark is what stops another vendor adopting an account PayU
 *     verified with this vendor's PAN (wave-9 integrity M-1). Refused either way.
 *   - a row with no account whose last attempt is under an hour old is refused before PayU is asked:
 *     a create PayU completed may not be in the listing yet, and clearing its mark then lets Retry make
 *     a second child (wave-9 integrity M-2). Same window Retry trusts an empty parent after.
 *
 * PayU is read before the transaction opens; the transaction holds only the compare-and-set and its
 * audit row (wave-9 security L-3). Audited with the email masked. The worker never touches a row this
 * acts on (not workable).
 */
class CreateMarkForgetter
{
    /**
     * @param SubmerchantRepositoryInterface $repository
     * @param SubmerchantResource $resource
     * @param CollectionFactory $collectionFactory
     * @param ExistingChildFinder $childFinder
     * @param SettlementAuditLogger $auditLogger
     * @param DateTime $dateTime
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $repository,
        private readonly SubmerchantResource $resource,
        private readonly CollectionFactory $collectionFactory,
        private readonly ExistingChildFinder $childFinder,
        private readonly SettlementAuditLogger $auditLogger,
        private readonly DateTime $dateTime
    ) {
    }

    /**
     * Whether the row may have its mark cleared at all (before asking PayU). The grid uses the same rule.
     *
     * @param Submerchant $row
     * @return bool
     */
    public static function isEligible(Submerchant $row): bool
    {
        if ($row->getPayuEmail() === null || $row->isWorkable()) {
            return false;
        }
        return $row->getPayuUuid() !== null || $row->getOnboardingState() === Submerchant::STATE_FAILED;
    }

    /**
     * @param int $sellerId
     * @return void
     * @throws LocalizedException When refused; nothing is written
     * @throws \RKD\PayuSplit\Split\Exception\PayuOnboardingRequestException When PayU cannot be read
     */
    public function forget(int $sellerId): void
    {
        $row = $this->repository->getBySellerId($sellerId);
        if ($row === null || !self::isEligible($row)) {
            throw new LocalizedException(__(
                'Seller %1 has no recorded PayU create that can be cleared (it may still be in onboarding\'s hands).',
                $sellerId
            ));
        }
        $mark = (string)$row->getPayuEmail();
        if ($row->getPayuUuid() === null) {
            $this->assertCreateHasSettled($row);
        }
        $this->assertNoAccountForThisMark($row, $mark);

        $before = $row->getOnboardingState();
        $connection = $this->resource->getConnection();
        $connection->beginTransaction();
        try {
            if (!$this->clearIfUnchanged($row, $mark)) {
                throw new LocalizedException(__(
                    'Seller %1 changed while this was being checked. Reload the grid and try again.',
                    $sellerId
                ));
            }
            $this->auditLogger->record(
                AuditLog::ACTION_FORGET_MARK,
                AuditLog::ENTITY_SUBMERCHANT_ROW,
                (int)$row->getId(),
                null,
                $before,
                $before,
                sprintf('seller %d; recorded create cleared (was %s)', $sellerId, self::mask($mark))
            );
            $connection->commit();
        } catch (\Throwable $t) {
            $connection->rollBack();
            throw $t;
        }
    }

    /**
     * @param Submerchant $row
     * @return void
     * @throws LocalizedException
     */
    private function assertCreateHasSettled(Submerchant $row): void
    {
        $lastAttempt = strtotime((string)$row->getData('last_attempt_at') . ' UTC');
        if ($lastAttempt === false
            || $lastAttempt > $this->dateTime->gmtTimestamp() - Submerchant::CREATE_SETTLE_SECONDS
        ) {
            throw new LocalizedException(__(
                'Seller %1\'s last PayU attempt was under an hour ago, and an account it made may not show in '
                . 'PayU\'s listing yet. Try again after an hour. Nothing was cleared.',
                $row->getSellerId()
            ));
        }
    }

    /**
     * Compare-and-set on what was checked: the same mark, account and state. A Retry and a new create
     * landing while PayU was being read leaves this writing nothing (wave-8 integrity L-4).
     *
     * @param Submerchant $row
     * @param string $mark
     * @return bool
     */
    private function clearIfUnchanged(Submerchant $row, string $mark): bool
    {
        $uuid = $row->getPayuUuid();
        return (int)$this->resource->getConnection()->update(
            $this->resource->getMainTable(),
            ['payu_email' => null],
            [
                'entity_id = ?' => (int)$row->getId(),
                'payu_email = ?' => $mark,
                'onboarding_state = ?' => $row->getOnboardingState(),
                // Spelled out: a NULL bound into a placeholder is quoted as '' and would never match.
                $uuid === null ? 'payu_uuid IS NULL' : 'payu_uuid = ?' => $uuid,
            ]
        ) === 1;
    }

    /**
     * @param Submerchant $row
     * @param string $mark
     * @return void
     * @throws LocalizedException
     */
    private function assertNoAccountForThisMark(Submerchant $row, string $mark): void
    {
        $children = $this->childFinder->listChildren();
        if (CreateMarkClaims::isExplained((string)$row->getPayuUuid(), $mark, $children)) {
            return;
        }
        foreach ($children as $child) {
            if (strcasecmp($child['email'], trim($mark)) !== 0 || $this->heldElsewhere($child, $row)) {
                continue;
            }
            if ($row->getPayuUuid() !== null) {
                throw new LocalizedException(__(
                    'PayU holds a sub-merchant (MID ending %1) created with seller %2\'s recorded email that no '
                    . 'vendor holds, and seller %2 is linked to a different account. It may be their lost create; '
                    . 'the record is what keeps another vendor from adopting it. Nothing was cleared.',
                    substr($child['mid'], -4),
                    $row->getSellerId()
                ));
            }
            throw new LocalizedException(__(
                'PayU holds a sub-merchant (MID ending %1) created with seller %2\'s recorded email, and no '
                . 'other vendor holds it. It is probably theirs: press Retry and onboarding will adopt it, or '
                . 'use "Link PayU account". Nothing was cleared.',
                substr($child['mid'], -4),
                $row->getSellerId()
            ));
        }
    }

    /**
     * @param array{uuid: string, mid: string, email: string, pgKey: string|null} $child
     * @param Submerchant $row
     * @return bool
     */
    private function heldElsewhere(array $child, Submerchant $row): bool
    {
        // By uuid or key — the same rule as the worker's (ExistingChildFinder::notHeldElsewhere).
        $fields = ['payu_uuid'];
        $conditions = [['eq' => $child['uuid']]];
        if ($child['pgKey'] !== null && $child['pgKey'] !== '') {
            $fields[] = 'payu_merchant_key';
            $conditions[] = ['eq' => $child['pgKey']];
        }
        return $this->collectionFactory->create()
            ->addFieldToFilter($fields, $conditions)
            ->addFieldToFilter('seller_id', ['neq' => $row->getSellerId()])
            ->getSize() > 0;
    }

    /**
     * @param string $email
     * @return string e.g. "r***@example.in"
     */
    private static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        return $at === false || $at === 0 ? '***' : substr($email, 0, 1) . '***' . substr($email, $at);
    }
}
