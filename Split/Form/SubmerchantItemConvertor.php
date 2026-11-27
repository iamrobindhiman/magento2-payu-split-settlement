<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Form;

use RKD\PayuSplit\Split\Api\SubmerchantRepositoryInterface;
use RKD\PayuSplit\Split\Model\Submerchant;
use Loki\AdminComponents\Form\ItemConvertorInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;

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
     */
    public function __construct(
        private readonly SubmerchantRepositoryInterface $submerchantRepository
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
        $sellerId = (int) $item->getData('seller_id');
        if ($sellerId <= 0) {
            throw new LocalizedException(
                __('A valid seller is required (seller ID must be a positive number).')
            );
        }
        $item->setData('seller_id', $sellerId);

        // One mapping per seller (the table enforces it with a UNIQUE key). Catch a duplicate
        // here so the admin gets a clean message instead of a raw integrity-violation 500.
        // Editing the seller's own existing row is fine — only a DIFFERENT row collides.
        $existing = $this->submerchantRepository->getBySellerId($sellerId);
        if ($existing !== null && (int) $existing->getId() !== (int) $item->getData('entity_id')) {
            throw new LocalizedException(
                __('A PayU submerchant mapping already exists for seller ID %1.', $sellerId)
            );
        }

        $key = trim((string) $item->getData('payu_merchant_key'));
        if ($key === '') {
            throw new LocalizedException(__('The PayU child merchant key is required.'));
        }
        $item->setData('payu_merchant_key', $key);

        $mid = trim((string) $item->getData('payu_mid'));
        $item->setData('payu_mid', $mid === '' ? null : $mid);

        $kyc = (string) $item->getData('kyc_status');
        if ($kyc === '') {
            $kyc = Submerchant::KYC_PENDING;
        }
        if (!in_array($kyc, Submerchant::KYC_STATUSES, true)) {
            throw new LocalizedException(__('Invalid KYC status "%1".', $kyc));
        }
        $item->setData('kyc_status', $kyc);

        $item->setData('is_active', $this->normaliseFlag($item->getData('is_active')));

        // The form posts every column; let the schema own the timestamps (created_at default
        // CURRENT_TIMESTAMP on insert, updated_at on_update) rather than writing the posted
        // null/stale values back.
        $item->unsetData('created_at');
        $item->unsetData('updated_at');

        return $item;
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
