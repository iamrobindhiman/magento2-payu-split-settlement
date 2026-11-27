<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model\Source;

use RKD\PayuSplit\Split\Model\Submerchant;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * Option source for the submerchant grid's "Onboarding State" filter and column.
 * Values come straight from the Submerchant constants so the dropdown can never
 * drift from the stored values or the state machine's allowed set.
 */
class OnboardingState implements OptionSourceInterface
{
    /**
     * Labels are deliberately written for the ALLYNOR staff who work the queue, not
     * for developers — "Waiting for e-sign" tells them what to do; AWAITING_ESIGN does not.
     *
     * @var array<string, string>
     */
    private const LABELS = [
        Submerchant::STATE_NOT_STARTED => 'Not started',
        Submerchant::STATE_QUEUED => 'Queued',
        Submerchant::STATE_CREATED => 'Created at PayU',
        Submerchant::STATE_BANK_ATTACHED => 'Bank attached',
        Submerchant::STATE_AWAITING_ESIGN => 'Waiting for e-sign',
        Submerchant::STATE_ACTIVE => 'Active',
        Submerchant::STATE_FAILED => 'Failed',
    ];

    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase|string}>
     */
    public function toOptionArray(): array
    {
        $options = [];
        foreach (Submerchant::STATES as $state) {
            $options[] = ['value' => $state, 'label' => __(self::LABELS[$state])];
        }
        return $options;
    }
}
