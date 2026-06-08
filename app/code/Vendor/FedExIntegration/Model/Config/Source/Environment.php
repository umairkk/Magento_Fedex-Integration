<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

class Environment implements OptionSourceInterface
{
    public const SANDBOX = 'sandbox';
    public const PRODUCTION = 'production';

    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => self::SANDBOX, 'label' => __('Sandbox')->render()],
            ['value' => self::PRODUCTION, 'label' => __('Production')->render()],
        ];
    }
}
