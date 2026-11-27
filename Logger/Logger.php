<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Logger;

use Monolog\Logger as MonologLogger;

/**
 * Dedicated Monolog channel for PayU diagnostics. Writes to var/log/payu.log
 * (intentionally the same file the upstream module dumps to via Zend_Log_Writer_Stream
 * — our entries appear alongside the legacy noise so a single tail covers everything).
 * Channel name: rkd_payu.
 */
class Logger extends MonologLogger
{
}
