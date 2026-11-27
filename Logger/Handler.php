<?php
declare(strict_types=1);

namespace RKD\PayuSplit\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger as MonologLogger;

class Handler extends Base
{
    /** @var int */
    protected $loggerType = MonologLogger::INFO;

    /** @var string */
    protected $fileName = '/var/log/payu.log';
}
