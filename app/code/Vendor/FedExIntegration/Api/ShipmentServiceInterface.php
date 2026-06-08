<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Api;

use Magento\Sales\Api\Data\ShipmentInterface;

interface ShipmentServiceInterface
{
    /**
     * @param array<string, mixed> $options
     * @return array{tracking_number: string, label_path: string, fedex_response: array<string, mixed>}
     */
    public function createShipment(ShipmentInterface $shipment, array $options = []): array;
}
