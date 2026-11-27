<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * Raised when a payload is refused BEFORE it reaches PayU, because sending it would
 * do something we cannot undo.
 *
 * The case this exists for is T-04. `aggregator_parent_mid` is documented mandatory,
 * but PayU returns 200 without it and silently creates a merchant OUTSIDE our
 * aggregator — an orphan we then cannot read, edit or split to. That happened twice
 * on 2026-09-09 before it was understood. A missing parent MID must therefore fail
 * loudly on our side of the wire, not quietly on theirs.
 */
class OnboardingPayloadException extends RuntimeException
{
}
