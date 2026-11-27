<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\ResourceModel;

use RKD\PayuSplit\Split\Model\Submerchant as SubmerchantModel;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\AbstractModel;
use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * Resource model for the rkd_payu_submerchant mapping table.
 */
class Submerchant extends AbstractDb
{
    /**
     * @return void
     */
    protected function _construct()
    {
        $this->_init('rkd_payu_submerchant', 'entity_id');
    }

    /**
     * A compare-and-set condition that a column still holds $value, NULL included. Spelled out because a
     * NULL bound into a placeholder is quoted as '' and would never match.
     *
     * @param string $column
     * @param string|null $value
     * @param bool $emptyIsNull Also accept '' where NULL is expected (columns the admin form writes)
     * @return array<string, string|null>
     */
    public static function pinned(string $column, ?string $value, bool $emptyIsNull = false): array
    {
        if ($value !== null) {
            return [$column . ' = ?' => $value];
        }
        return [$emptyIsNull ? "($column IS NULL OR $column = '')" : "$column IS NULL" => null];
    }

    /**
     * A row that records a PayU account is never deleted, by any path (INV-O1/O3, wave-2 N-1, wave-3 I-F4).
     *
     * The row is what records that a PayU child exists, or that a create was sent. Deleting it and
     * re-approving the vendor would create a second, undeletable PayU account — and that includes a
     * hand-typed legacy mapping: its key or MID proves an account exists even with no uuid stored.
     * Checked here, on the table, because the admin form's Delete, a grid mass action and any future
     * caller all end up in this method — and against the stored row, not whatever the caller holds.
     * Such a row is switched off with "Deactivate payouts" instead.
     *
     * @param AbstractModel $object
     * @return $this
     * @throws LocalizedException
     */
    protected function _beforeDelete(AbstractModel $object)
    {
        $id = (int)$object->getId();
        if ($id > 0) {
            $connection = $this->getConnection();
            $stored = $connection->fetchRow(
                $connection->select()
                    ->from($this->getMainTable(), ['onboarding_state', 'payu_uuid', 'payu_email', 'payu_merchant_key', 'payu_mid'])
                    ->where('entity_id = ?', $id)
            );
            if (is_array($stored) && self::isProtectedFromDelete($stored)) {
                throw new LocalizedException(__(
                    'This PayU mapping records a PayU account and cannot be deleted: it is what stops a second '
                    . 'account being created for this vendor. Use "Deactivate payouts" to switch it off.'
                ));
            }
        }
        return parent::_beforeDelete($object);
    }

    /**
     * @param array<string, mixed> $stored
     * @return bool
     */
    public static function isProtectedFromDelete(array $stored): bool
    {
        $state = (string)($stored['onboarding_state'] ?? '');
        if ($state !== '' && $state !== SubmerchantModel::STATE_NOT_STARTED) {
            return true;
        }
        foreach (['payu_uuid', 'payu_email', 'payu_merchant_key', 'payu_mid'] as $proof) {
            if (trim((string)($stored[$proof] ?? '')) !== '') {
                return true;
            }
        }
        return false;
    }
}
