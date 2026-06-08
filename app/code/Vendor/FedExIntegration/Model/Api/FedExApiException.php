<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

class FedExApiException extends LocalizedException
{
    public function __construct(
        Phrase $phrase,
        private readonly int $statusCode = 0,
        ?\Throwable $cause = null,
        int $code = 0
    ) {
        parent::__construct($phrase, $cause instanceof \Exception ? $cause : null, $code);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
