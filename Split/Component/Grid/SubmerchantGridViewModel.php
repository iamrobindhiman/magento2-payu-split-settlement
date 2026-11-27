<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Component\Grid;

use Loki\AdminComponents\Component\Grid\GridViewModel as BaseGridViewModel;
use Loki\AdminComponents\Grid\Column\Column;

/**
 * Loki grid view-model for the A7-2 submerchant-mapping view. Unlike A7-1, this grid IS
 * actionable (per-row Edit + a New button → the submerchant form). The masked key/MID and the
 * KYC/active labels are decorated onto each row by SubmerchantGrid\Collection.
 */
class SubmerchantGridViewModel extends BaseGridViewModel
{
    /**
     * Return EXACTLY the columns declared in the layout.
     *
     * Loki's default getColumns() only merges the layout config into an auto-detected base
     * set, which is unreliable for our DECORATED, non-DB columns (key_display, mid_display,
     * kyc_display, active_display) — it intermittently drops them. Declaring the set explicitly
     * makes it deterministic; per-row values for the decorated columns still resolve via the
     * base getValueFromItem() through the model's magic getData() accessors. (Same fix as the
     * settlement grid; see lesson_loki_admin_components_gotchas.)
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
}
