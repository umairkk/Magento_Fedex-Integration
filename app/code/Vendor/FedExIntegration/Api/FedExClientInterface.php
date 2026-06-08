<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Api;

interface FedExClientInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, null|int|string $storeId = null): array;
}
