<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Model;

/**
 * The outcome of polling a refund leg's status from PayU (check_action_status_txnid), gate item
 * #6. Immutable. Four outcomes, deliberately distinct:
 *  - SUCCESS    — PayU confirms the refund completed; the reconciler promotes the leg QUEUED→SUCCESS.
 *  - FAILURE    — PayU rejected/failed the queued refund; the reconciler FAILs the leg, FREEZEs the
 *                 segment and alarms (the customer was NOT refunded, and the executor's optimistic
 *                 refunded_paise bump is now wrong — surfaced for a human, never auto-acted on).
 *  - PROCESSING — still queued/in-progress at PayU; leave the leg, retry next tick.
 *  - NONE       — no recognizable signal (UAT generic error, an unknown status value, a non-array
 *                 body). The reconciler waits / strand-alarms; it must NEVER guess a promotion.
 */
class RefundStatusResult
{
    public const STATUS_SUCCESS = 'SUCCESS';
    public const STATUS_FAILURE = 'FAILURE';
    public const STATUS_PROCESSING = 'PROCESSING';
    public const STATUS_NONE = 'NONE';

    /**
     * @param string $status One of the STATUS_* constants
     */
    private function __construct(
        public readonly string $status
    ) {
    }

    /**
     * @return self
     */
    public static function success(): self
    {
        return new self(self::STATUS_SUCCESS);
    }

    /**
     * @return self
     */
    public static function failure(): self
    {
        return new self(self::STATUS_FAILURE);
    }

    /**
     * @return self
     */
    public static function processing(): self
    {
        return new self(self::STATUS_PROCESSING);
    }

    /**
     * @return self
     */
    public static function noSignal(): self
    {
        return new self(self::STATUS_NONE);
    }

    /**
     * @return bool
     */
    public function isSuccess(): bool
    {
        return $this->status === self::STATUS_SUCCESS;
    }

    /**
     * @return bool
     */
    public function isFailure(): bool
    {
        return $this->status === self::STATUS_FAILURE;
    }

    /**
     * @return bool
     */
    public function isProcessing(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * @return bool True when PayU returned a status we recognize (not NONE)
     */
    public function hasSignal(): bool
    {
        return $this->status !== self::STATUS_NONE;
    }
}
