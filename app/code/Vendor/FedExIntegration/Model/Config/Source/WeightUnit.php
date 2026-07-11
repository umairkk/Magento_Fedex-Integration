<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class WeightUnit implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'LB', 'label' => __('Pounds')->render()],
            ['value' => 'KG', 'label' => __('Kilograms')->render()],
        ];
    }
}
