<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class DimensionUnit implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'IN', 'label' => __('Inches')->render()],
            ['value' => 'CM', 'label' => __('Centimeters')->render()],
        ];
    }
}
