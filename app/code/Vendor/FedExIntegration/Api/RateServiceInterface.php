<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Api;

use Magento\Quote\Model\Quote\Address\RateRequest;

interface RateServiceInterface
{
    /**
     * @return array<int, array{method_code: string, method_title: string, amount: float, currency: string}>
     */
    public function getRates(RateRequest $request): array;
}
