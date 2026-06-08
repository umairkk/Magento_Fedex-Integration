<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class PackagingType implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => 'YOUR_PACKAGING', 'label' => __('Your Packaging')->render()],
            ['value' => 'FEDEX_ENVELOPE', 'label' => __('FedEx Envelope')->render()],
            ['value' => 'FEDEX_BOX', 'label' => __('FedEx Box')->render()],
            ['value' => 'FEDEX_SMALL_BOX', 'label' => __('FedEx Small Box')->render()],
            ['value' => 'FEDEX_MEDIUM_BOX', 'label' => __('FedEx Medium Box')->render()],
            ['value' => 'FEDEX_LARGE_BOX', 'label' => __('FedEx Large Box')->render()],
        ];
    }
}
