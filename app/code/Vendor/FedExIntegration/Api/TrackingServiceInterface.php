<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Api;

interface TrackingServiceInterface
{
    /**
     * @return array{tracking_number: string, status: string, status_description: string, events: array<int, array<string, mixed>>, raw: array<string, mixed>}
     */
    public function track(string $trackingNumber, null|int|string $storeId = null): array;
}
