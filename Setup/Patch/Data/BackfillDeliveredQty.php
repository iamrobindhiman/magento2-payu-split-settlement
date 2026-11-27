<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Setup\Patch\Data;

use Magento\Framework\DB\Sql\Expression;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;

/**
 * F1-qty backfill. Rows fully delivered under the pre-F1-qty stamper carry delivered_at but the new
 * delivered_qty column defaults to 0. The F1-qty eligibility backstop (delivered_qty >= ordered qty)
 * would otherwise mis-block those already-delivered lines as "partially delivered", and
 * {@see \RKD\PayuSplit\Split\Model\ResourceModel\UndeliveredSegments} never re-polls a row whose
 * delivered_at is already set — so it could never self-heal. This backfills delivered_qty = qty for
 * exactly those rows, once and idempotently (a second run matches nothing). A genuinely partial line
 * (delivered_at IS NULL) is left untouched, so this never manufactures a false full delivery.
 *
 * No production rows exist yet (split not deployed, release gated OFF); the patch keeps existing
 * dev/UAT test data consistent with the new gate.
 */
class BackfillDeliveredQty implements DataPatchInterface
{
    /**
     * Inject the data-setup so the patch can run its guarded backfill UPDATE.
     *
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(
        private readonly ModuleDataSetupInterface $moduleDataSetup
    ) {
    }

    /**
     * Backfill delivered_qty = qty for rows already fully delivered before F1-qty.
     *
     * @return self
     */
    public function apply(): self
    {
        $this->moduleDataSetup->startSetup();
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('rkd_payu_segment_item');

        // Guarded: only run once the schema patch has added the column (mirrors the module's
        // tableColumnExists data-patch discipline).
        if ($connection->tableColumnExists($table, 'delivered_qty')) {
            $connection->update(
                $table,
                ['delivered_qty' => new Expression('qty')],
                ['delivered_at IS NOT NULL', 'delivered_qty = ?' => 0]
            );
        }

        $this->moduleDataSetup->endSetup();

        return $this;
    }

    /**
     * No patch dependencies — the schema column is applied by declarative db_schema, not a patch.
     *
     * @return string[]
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * No prior aliases for this patch.
     *
     * @return string[]
     */
    public function getAliases(): array
    {
        return [];
    }
}
