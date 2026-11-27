<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Form;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\ResourceModel\Submerchant\CollectionFactory;
use RKD\PayuSplit\Split\Model\Submerchant;
use RKD\PayuSplit\Split\Service\PayoutSwitchHistory;
use Loki\AdminComponents\Form\ItemConvertorInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;

/**
 * Server-side validation + normalisation for the submerchant CRUD form (A7-2).
 *
 * The Loki SaveAction maps each POSTed field onto the model via its setter and then calls
 * {@see self::beforeSave()} immediately before persisting — so this is the single point at
 * which a bad money-routing row can be refused. It:
 *   - requires a positive seller id and a non-empty child merchant key;
 *   - TRIMS the merchant key (and MID), which closes the stored-key-vs-config whitespace/case
 *     mismatch the A5b money review flagged (C2) — a refund's child-key lookup must match;
 *   - constrains kyc_status to {@see Submerchant::KYC_STATUSES} (defaulting blank to pending);
 *   - normalises is_active to an exact 0/1.
 *
 * It throws {@see LocalizedException} on invalid input, which fails closed: the throw happens
 * before the resource save, so a rejected row is never written. Pure — operates on the
 * DataObject only, no DB.
 */
class SubmerchantItemConvertor implements ItemConvertorInterface
{
    /**
     * @param SubmerchantRepositoryInterface $submerchantRepository
     * @param CollectionFactory $collectionFactory
     * @param PayoutSwitchHistory $switchHistory
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $submerchantRepository,
        private readonly CollectionFactory $collectionFactory,
        private readonly PayoutSwitchHistory $switchHistory
    ) {
    }

    /**
     * Seed the select-backed defaults for a NEW row before it renders.
     *
     * Loki seeds a new item's columns from their SQL data type, not the schema default:
     * smallint → null, varchar → ''. Left as-is, the Active/KYC dropdowns would render their
     * first option ("Active" / "Pending") while the model value is null/'' — so an unchanged
     * form would silently persist is_active=0. Seeding the displayed defaults makes the
     * rendered value match what is saved. Existing rows carry real 0/1 + a real status, so
     * this only touches genuinely-unset (new-row) values and never flips a saved value.
     *
     * @param DataObject $item
     * @return DataObject
     */
    public function afterLoad(DataObject $item): DataObject
    {
        if ($item->getData('is_active') === null) {
            $item->setData('is_active', 1);
        }
        if ((string) $item->getData('kyc_status') === '') {
            $item->setData('kyc_status', Submerchant::KYC_PENDING);
        }

        return $item;
    }

    /**
     * @param DataObject $item
     * @return DataObject
     * @throws LocalizedException
     */
    public function beforeSave(DataObject $item): DataObject
    {
        // Loki turns every posted key into a setter; "id_field_name" would re-point the UPDATE at a
        // row this method never checked (wave-4 security F1). The Loki guard refuses the key; this
        // is the second line.
        if ($item instanceof AbstractModel && $item->getIdFieldName() !== 'entity_id') {
            throw new LocalizedException(__('This PayU mapping could not be saved.'));
        }

        $sellerId = (int) $item->getData('seller_id');
        if ($sellerId <= 0) {
            throw new LocalizedException(
                __('A valid seller is required (seller ID must be a positive number).')
            );
        }
        $item->setData('seller_id', $sellerId);

        $this->assertEditableRow($item, $sellerId);

        $key = trim((string) $item->getData('payu_merchant_key'));
        if ($key === '') {
            throw new LocalizedException(__('The PayU child merchant key is required.'));
        }
        $item->setData('payu_merchant_key', $key);

        $mid = trim((string) $item->getData('payu_mid'));
        $item->setData('payu_mid', $mid === '' ? null : $mid);
        $this->assertRoutingNotTaken($item, $key, $mid);
        $this->assertSwitchedOffAccountUnchanged($item, $sellerId, $key, $mid);

        $item->setData('kyc_status', $this->normaliseKyc((string) $item->getData('kyc_status')));

        $item->setData('is_active', $this->normaliseFlag($item->getData('is_active')));
        $this->assertNotUndoingADeactivate($item);

        // The form posts every column; let the schema own the timestamps (created_at default
        // CURRENT_TIMESTAMP on insert, updated_at on_update) rather than writing the posted
        // null/stale values back.
        $item->unsetData('created_at');
        $item->unsetData('updated_at');

        return $item;
    }

    /**
     * One mapping per seller, and never a row onboarding owns.
     *
     * The table enforces one row per seller with a UNIQUE key; catching a duplicate here gives the
     * admin a clean message instead of a raw integrity-violation 500. Editing the seller's own row is
     * fine — only a DIFFERENT row collides.
     *
     * A row under onboarding's control is changed only by onboarding and by the audited grid
     * actions. The form posts every column it rendered, so a stale form would otherwise write back
     * an old state, uuid or e-sign mark — and could re-open a vendor PayU already holds.
     *
     * @param DataObject $item
     * @param int $sellerId
     * @return void
     * @throws LocalizedException
     */
    private function assertEditableRow(DataObject $item, int $sellerId): void
    {
        $existing = $this->submerchantRepository->getBySellerId($sellerId);
        if ($existing !== null && (int) $existing->getId() !== (int) $item->getData('entity_id')) {
            throw new LocalizedException(
                __('A PayU submerchant mapping already exists for seller ID %1.', $sellerId)
            );
        }

        $stored = $this->submerchantRepository->getById((int) $item->getData('entity_id'));
        if ($stored !== null && $stored->isManagedByOnboarding()) {
            throw new LocalizedException(__(
                'Seller %1 is managed by PayU onboarding, so this form cannot change it. Use Retry or '
                . '"Mark e-sign sent" on the PayU Submerchants grid.',
                $stored->getSellerId()
            ));
        }
        foreach (Submerchant::ONBOARDING_COLUMNS as $column) {
            $item->unsetData($column);
        }
    }

    /**
     * One PayU account per seller (INV-O3). The key is what the split routes a vendor's share with,
     * and the table has no UNIQUE on it — so without this, a second row carrying another seller's
     * key would send this vendor's money to that seller's account (wave-3 security lead).
     *
     * @param DataObject $item
     * @param string $key
     * @param string $mid
     * @return void
     * @throws LocalizedException
     */
    private function assertRoutingNotTaken(DataObject $item, string $key, string $mid): void
    {
        $fields = ['payu_merchant_key'];
        $conditions = [['eq' => $key]];
        if ($mid !== '') {
            $fields[] = 'payu_mid';
            $conditions[] = ['eq' => $mid];
        }
        $others = $this->collectionFactory->create()->addFieldToFilter($fields, $conditions);
        // Exclude THIS row by its id, not by seller: a row being moved to another seller still holds
        // its own key in the database, and must not collide with itself (wave-4 correctness F4).
        $entityId = (int) $item->getData('entity_id');
        if ($entityId > 0) {
            $others->addFieldToFilter('entity_id', ['neq' => $entityId]);
        }
        if ($others->getSize() > 0) {
            throw new LocalizedException(__(
                'That PayU merchant key or MID is already mapped to seller %1. One PayU account belongs to '
                . 'one seller.',
                (int) $others->getFirstItem()->getData('seller_id')
            ));
        }
    }

    /**
     * A mapping staff switched off keeps the account and seller it had when they did. "Reactivate
     * payouts" switches back on whatever the row holds, so a key typed in meanwhile would go live
     * without anyone reviewing it against the Deactivate (wave-9 integrity L-1). A wrong key on a
     * switched-off legacy mapping is replaced with "Link PayU account", which takes the key from PayU and
     * ends the history (LinkKeyPolicy, wave-10 integrity M-3).
     *
     * @param DataObject $item
     * @param int $sellerId
     * @param string $key
     * @param string $mid
     * @return void
     * @throws LocalizedException
     */
    private function assertSwitchedOffAccountUnchanged(DataObject $item, int $sellerId, string $key, string $mid): void
    {
        $entityId = (int) $item->getData('entity_id');
        if ($entityId <= 0) {
            return;
        }
        $stored = $this->submerchantRepository->getById($entityId);
        if ($stored === null || $stored->isActive() || !$this->switchHistory->isSwitchedOffByStaff($entityId)) {
            return;
        }
        if ($stored->getSellerId() !== $sellerId
            || !hash_equals($stored->getMerchantKey(), $key)
            || (string) $stored->getPayuMid() !== $mid
        ) {
            throw new LocalizedException(__(
                'Payouts on this mapping (seller %1) were switched off with "Deactivate payouts", so its seller, '
                . 'merchant key and MID are frozen until "Reactivate payouts". If the key is wrong, use "Link PayU '
                . 'account" on the PayU Submerchants grid: it takes the key from PayU.',
                $stored->getSellerId()
            ));
        }
    }

    /**
     * A mapping staff switched off with "Deactivate payouts" comes back on only through the audited
     * "Reactivate payouts" — never by a form save, which could be a form opened before the Deactivate
     * (wave-4 correctness F5).
     *
     * @param DataObject $item
     * @return void
     * @throws LocalizedException
     */
    private function assertNotUndoingADeactivate(DataObject $item): void
    {
        $entityId = (int) $item->getData('entity_id');
        if ($entityId <= 0 || (int) $item->getData('is_active') !== 1) {
            return;
        }
        $stored = $this->submerchantRepository->getById($entityId);
        if ($stored !== null && !$stored->isActive() && $this->switchHistory->isSwitchedOffByStaff($entityId)) {
            throw new LocalizedException(__(
                'Payouts on this mapping (seller %1) were switched off with "Deactivate payouts". Use '
                . '"Reactivate payouts" on the PayU Submerchants grid to switch them back on.',
                $stored->getSellerId()
            ));
        }
    }

    /**
     * @param string $kyc
     * @return string
     * @throws LocalizedException
     */
    private function normaliseKyc(string $kyc): string
    {
        if ($kyc === '') {
            return Submerchant::KYC_PENDING;
        }
        if (!in_array($kyc, Submerchant::KYC_STATUSES, true)) {
            throw new LocalizedException(__('Invalid KYC status "%1".', $kyc));
        }
        return $kyc;
    }

    /**
     * Coerce a posted flag to an exact integer 0/1 (only an explicit "1"/1/true is active).
     *
     * @param mixed $value
     * @return int
     */
    private function normaliseFlag(mixed $value): int
    {
        return in_array($value, [1, '1', true], true) ? 1 : 0;
    }
}
