<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Component\Grid;

use Loki\AdminComponents\Component\Grid\GridViewModel as BaseGridViewModel;
use Loki\AdminComponents\Grid\Column\Column;

/**
 * Read-only Loki grid view-model for the A7-3 break-glass audit log (rkd_payu_audit_log). All
 * columns are real DB columns; the explicit getColumns() override keeps the declared set
 * deterministic (see lesson_loki_admin_components_gotchas — the base merge-with-auto-detect is
 * unreliable for flat collections).
 */
class AuditGridViewModel extends BaseGridViewModel
{
    /**
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
