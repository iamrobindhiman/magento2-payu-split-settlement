<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Split\Exception;

use RuntimeException;

/**
 * This run no longer holds the vendor's lease — it expired while PayU was slow and another run
 * may have claimed the vendor. Thrown right before an irreversible PayU call, so the call is
 * never made twice. The run stops without writing anything further.
 */
class OnboardingLeaseLostException extends RuntimeException
{
}
