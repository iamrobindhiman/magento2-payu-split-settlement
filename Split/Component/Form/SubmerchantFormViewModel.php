<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Component\Form;

use Loki\AdminComponents\Component\Form\FormViewModel as BaseFormViewModel;
use Loki\AdminComponents\Ui\Button;
use Magento\Framework\DataObject;

/**
 * Form view-model for the submerchant mapping (A7-2). It exists solely to drop the Delete and
 * Duplicate actions from the default Loki button set:
 *   - Delete: a submerchant mapping is money-routing config — it is deactivated via the
 *     is_active flag, never hard-deleted (a stale split could reference it).
 *   - Duplicate: seller_id is unique, so duplicating a row would always violate the constraint.
 * Everything else (field rendering, load, save) is the stock Loki form behaviour.
 */
class SubmerchantFormViewModel extends BaseFormViewModel
{
    /**
     * Close + Save for both new and existing rows; no Delete, no Duplicate.
     *
     * @return Button[]
     */
    #[\Override]
    public function getButtons(): array
    {
        $item = $this->getValue();
        if ($item instanceof DataObject && (int) $item->getId() > 0) {
            return [
                $this->buttonFactory->createCloseAction(),
                $this->buttonFactory->createSaveContinueAction(),
                $this->buttonFactory->createSaveCloseAction(),
            ];
        }

        return [
            $this->buttonFactory->createCloseAction(),
            $this->buttonFactory->createSaveCloseAction(),
        ];
    }
}
