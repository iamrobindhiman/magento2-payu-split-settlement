<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * Raised when a PayU split request cannot be completed at the transport level —
 * connection refused, timeout, DNS failure. Distinct from a PayU business error
 * (those come back as a PayuApiResponse with status 0 + AGG-* code). The caller
 * (settlement cron) treats this as "try again later", not "split failed".
 */
class PayuSplitRequestException extends RuntimeException
{
}
