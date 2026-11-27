<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Component\Grid;

use RKD\PayuSplit\Split\Model\SplitOrder;
use Loki\AdminComponents\Component\Grid\GridViewModel as BaseGridViewModel;
use Loki\AdminComponents\Grid\Cell\CellAction;
use Loki\AdminComponents\Grid\Column\Column;
use Magento\Framework\DataObject;

/**
 * Read-only Loki grid view-model for the PayU per-vendor settlement view (A7-1). It adds a
 * coloured state badge for the segment state column; all data (amounts as ₹, eligibility
 * reason, refund summary) is decorated onto each row by SettlementGrid\Collection. No row
 * actions yet — break-glass actions (retry / force-release) arrive in A7-3 with ACL gating
 * and audit logging.
 */
class SettlementGridViewModel extends BaseGridViewModel
{
    /**
     * Render the segment state as a coloured badge.
     *
     * @return array<string, string>
     */
    #[\Override]
    public function getCellTemplates(): array
    {
        return [
            'state' => 'RKD_PayuSplit::grid/cell/state.phtml',
        ];
    }

    /**
     * Return EXACTLY the columns declared in the layout, verbatim.
     *
     * Loki's default getColumns() only MERGES the layout column config into an
     * auto-detected base set (getAvailableColumns). For this flat (non-EAV) collection that
     * auto-detection is unreliable for our DECORATED, non-DB columns (vendor_label,
     * amount_display, eligibility_reason, refund_summary) — it intermittently drops them, so
     * the grid degrades to DB columns only. Declaring the column set explicitly makes it
     * deterministic; per-row values for the decorated columns are still resolved by the base
     * getValueFromItem() via the model's magic getData() accessors.
     *
     * @return Column[]
     */
    #[\Override]
    public function getColumns(): array
    {
        return $this->columnLoader->sortColumns(
            $this->columnLoader->getColumnsFromBlock($this->block)
        );
    }

    /**
     * State-aware break-glass row actions (A7-3). "Retry Split" only on a row whose split
     * header is FAILED; "Re-arm Refund" only on a segment carrying a frozen (HELD) refund leg
     * — both flags are decorated onto the row by SettlementGrid\Collection. Each is a confirmed
     * (browser confirm) navigation to its dedicated ACL-gated, audited controller via a keyed
     * admin URL (CSRF-safe). The actions never appear inline for an unprivileged admin because
     * the manage ACL gates the target controllers.
     *
     * @param DataObject $item
     * @return CellAction[]
     */
    #[\Override]
    protected function getAdditionalActions(DataObject $item): array
    {
        $actions = [];
        $orderId = (int) $item->getData('order_id');

        if ((string) $item->getData('split_state') === SplitOrder::STATE_FAILED) {
            $url = $this->urlFactory->create()->getUrl('rkd_payu/settlement/retrysplit', ['order_id' => $orderId]);
            $actions[] = $this->cellActionFactory->create(
                'Retry Split',
                null,
                null,
                sprintf(
                    "confirm('Retry the PayU split for order %d? It will be re-attempted on the "
                    . "next scan.') && window.location.assign('%s')",
                    $orderId,
                    $url
                )
            );
        }

        if ((int) $item->getData('has_held_refund') === 1) {
            $segmentId = (int) $item->getId();
            $url = $this->urlFactory->create()->getUrl(
                'rkd_payu/settlement/rearmrefund',
                ['segment_id' => $segmentId, 'order_id' => $orderId]
            );
            $actions[] = $this->cellActionFactory->create(
                'Re-arm Refund',
                null,
                null,
                sprintf(
                    "confirm('Re-arm the frozen refund for segment %d? It will be re-attempted "
                    . "on the next scan.') && window.location.assign('%s')",
                    $segmentId,
                    $url
                )
            );
        }

        // #11 break-glass: "Clear Frozen Hold" on a segment carrying a FROZEN hold — decorated onto
        // the row by SettlementGrid\Collection. Confirmed navigation to the ACL-gated, flag-gated,
        // audited controller; the clearer re-checks the out-of-band credit-memo condition and refuses
        // if it still holds, and clearing moves no money (the segment just re-enters the gates).
        if ((int) $item->getData('has_frozen_hold') === 1) {
            $segmentId = (int) $item->getId();
            $url = $this->urlFactory->create()->getUrl(
                'rkd_payu/settlement/clearfrozenhold',
                ['segment_id' => $segmentId, 'order_id' => $orderId]
            );
            $actions[] = $this->cellActionFactory->create(
                'Clear Frozen Hold',
                null,
                null,
                sprintf(
                    "confirm('Clear the FROZEN hold on segment %d? It will re-enter the release/refund "
                    . "gates on the next scan (they re-freeze if the condition still holds).') "
                    . "&& window.location.assign('%s')",
                    $segmentId,
                    $url
                )
            );
        }

        return $actions;
    }
}
