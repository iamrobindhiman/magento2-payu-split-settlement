<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\Source;

use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the submerchant form's "KYC Status" dropdown. Values come straight from
 * the Submerchant KYC constants so the dropdown can never drift from the stored values or the
 * save-time validator's allowed set.
 */
class KycStatus implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => Submerchant::KYC_PENDING, 'label' => __('Pending')],
            ['value' => Submerchant::KYC_VERIFIED, 'label' => __('Verified')],
            ['value' => Submerchant::KYC_REJECTED, 'label' => __('Rejected')],
        ];
    }
}
