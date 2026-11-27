<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The outcome of a release-eligibility check: either eligible, or blocked with a
 * human-readable reason (surfaced in the admin grid and logs). Immutable.
 */
class EligibilityResult
{
    /**
     * @param bool $eligible
     * @param string|null $reason Null when eligible; the blocking reason otherwise
     */
    private function __construct(
        public readonly bool $eligible,
        public readonly ?string $reason
    ) {
    }

    /**
     * @return self
     */
    public static function eligible(): self
    {
        return new self(true, null);
    }

    /**
     * @param string $reason
     * @return self
     */
    public static function blocked(string $reason): self
    {
        return new self(false, $reason);
    }
}
