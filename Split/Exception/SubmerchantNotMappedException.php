<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * Thrown when an order being split contains a vendor who is not mapped to an
 * active PayU sub-merchant. We never silently drop a vendor's money: the whole
 * split aborts so the order can be retried once the vendor is onboarded.
 */
class SubmerchantNotMappedException extends RuntimeException
{
}
