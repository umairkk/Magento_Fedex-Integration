<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Vendor\FedExIntegration\Model\Config;

class Method implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach (Config::SUPPORTED_METHODS as $code => $label) {
            $options[] = [
                'value' => $code,
                'label' => $label,
            ];
        }

        return $options;
    }
}
