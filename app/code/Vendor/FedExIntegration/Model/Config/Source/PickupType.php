<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PickupType implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'USE_SCHEDULED_PICKUP', 'label' => __('Use Scheduled Pickup')->render()],
            ['value' => 'DROPOFF_AT_FEDEX_LOCATION', 'label' => __('Drop off at FedEx Location')->render()],
            ['value' => 'CONTACT_FEDEX_TO_SCHEDULE', 'label' => __('Contact FedEx to Schedule')->render()],
        ];
    }
}
