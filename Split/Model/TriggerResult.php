<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The outcome of a split-trigger evaluation: which trigger (if any) fired for an
 * order, and whether a compliance alert is due because the order is ageing past
 * the alert threshold without yet being splittable. Immutable.
 */
class TriggerResult
{
    /**
     * @param string|null $trigger One of SplitTriggerResolver::TRIGGER_* or null if no trigger fired
     * @param bool $alertDue True when no trigger fired but the order has aged past the alert threshold
     */
    public function __construct(
        public readonly ?string $trigger,
        public readonly bool $alertDue
    ) {
    }
}
