<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * Raised when a customer refund cannot be computed into a valid var8 allocation —
 * negative inputs, or a refund that exceeds what the vendor segment plus the parent
 * commission can fund (INV-7). Indicates corrupt settlement data; the refund must halt
 * (fail loud) rather than over-refund the customer or post an unfundable leg.
 */
class RefundCalculationException extends RuntimeException
{
}
