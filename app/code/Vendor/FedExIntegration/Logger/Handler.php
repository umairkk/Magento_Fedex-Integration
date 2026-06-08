<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Logger;

use Magento\Framework\Logger\Handler\Base;
use Monolog\Logger;

class Handler extends Base
{
    /**
     * @var string
     */
    protected $fileName = '/var/log/fedex_integration.log';

    /**
     * @var int
     */
    protected $loggerType = Logger::INFO;
}
