<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * Raised when an order cannot be split into a valid PayU allocation — seller
 * shares exceed the grand total, a share is negative, or there are no sellers.
 * Indicates corrupt order data; the split must halt (fail loud) rather than
 * move money on bad figures.
 */
class SplitCalculationException extends RuntimeException
{
}
