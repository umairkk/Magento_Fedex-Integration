<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Vendor\FedExIntegration\Model\Config\Source\Environment;

class Config
{
    public const CARRIER_CODE = 'fedex_rest';
    public const XML_PATH_BASE = 'carriers/' . self::CARRIER_CODE . '/';

    /**
     * FedEx REST service codes supported by this carrier.
     */
    public const SUPPORTED_METHODS = [
        'FEDEX_GROUND' => 'FedEx Ground',
        'FEDEX_EXPRESS_SAVER' => 'FedEx Express Saver',
        'FEDEX_2_DAY' => 'FedEx 2Day',
        'STANDARD_OVERNIGHT' => 'FedEx Standard Overnight',
        'PRIORITY_OVERNIGHT' => 'FedEx Priority Overnight',
    ];

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly EncryptorInterface $encryptor
    ) {
    }

    public function isActive(null|int|string $storeId = null): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::XML_PATH_BASE . 'active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    public function getCarrierTitle(null|int|string $storeId = null): string
    {
        return $this->getValue('title', $storeId) ?: 'FedEx';
    }

    public function getCarrierName(null|int|string $storeId = null): string
    {
        return $this->getValue('name', $storeId) ?: 'FedEx REST Integration';
    }

    public function getEnvironment(null|int|string $storeId = null): string
    {
        $environment = $this->getValue('environment', $storeId);

        return $environment === Environment::PRODUCTION ? Environment::PRODUCTION : Environment::SANDBOX;
    }

    public function getBaseUrl(null|int|string $storeId = null): string
    {
        $field = $this->getEnvironment($storeId) === Environment::PRODUCTION
            ? 'production_api_url'
            : 'sandbox_api_url';

        return rtrim($this->getValue($field, $storeId), '/');
    }

    public function getEndpointPath(string $endpoint, null|int|string $storeId = null): string
    {
        return '/' . ltrim($this->getValue($endpoint . '_path', $storeId), '/');
    }

    public function getApiKey(null|int|string $storeId = null): string
    {
        return $this->decryptConfigValue('api_key', $storeId);
    }

    public function getSecretKey(null|int|string $storeId = null): string
    {
        return $this->decryptConfigValue('secret_key', $storeId);
    }

    public function getAccountNumber(null|int|string $storeId = null): string
    {
        return trim($this->getValue('account_number', $storeId));
    }

    public function getShipperCompany(null|int|string $storeId = null): string
    {
        return $this->getValue('shipper_company', $storeId) ?: $this->getCarrierTitle($storeId);
    }

    public function getShipperPhone(null|int|string $storeId = null): string
    {
        return preg_replace('/[^\d+]/', '', $this->getValue('shipper_phone', $storeId)) ?: '';
    }

    /**
     * @return array<int, string>
     */
    public function getAllowedMethods(null|int|string $storeId = null): array
    {
        $allowedMethods = $this->getValue('allowed_methods', $storeId);
        if ($allowedMethods === '') {
            return array_keys(self::SUPPORTED_METHODS);
        }

        return array_values(array_intersect(
            array_keys(self::SUPPORTED_METHODS),
            array_map('trim', explode(',', $allowedMethods))
        ));
    }

    public function isMethodAllowed(string $serviceCode, null|int|string $storeId = null): bool
    {
        return in_array($serviceCode, $this->getAllowedMethods($storeId), true);
    }

    public function getMethodLabel(string $serviceCode): string
    {
        return self::SUPPORTED_METHODS[$serviceCode] ?? $serviceCode;
    }

    public function getTimeout(null|int|string $storeId = null): int
    {
        return max(1, (int) $this->getValue('timeout', $storeId));
    }

    public function getTokenTtlBuffer(null|int|string $storeId = null): int
    {
        return max(0, (int) $this->getValue('token_ttl_buffer', $storeId));
    }

    public function getPickupType(null|int|string $storeId = null): string
    {
        return $this->getValue('pickup_type', $storeId) ?: 'USE_SCHEDULED_PICKUP';
    }

    public function getPackagingType(null|int|string $storeId = null): string
    {
        return $this->getValue('packaging_type', $storeId) ?: 'YOUR_PACKAGING';
    }

    public function getDefaultWeightUnit(null|int|string $storeId = null): string
    {
        return $this->getValue('default_weight_unit', $storeId) ?: 'LB';
    }

    public function getDefaultDimensionUnit(null|int|string $storeId = null): string
    {
        return $this->getValue('default_dimension_unit', $storeId) ?: 'IN';
    }

    /**
     * @return array{length: float, width: float, height: float}
     */
    public function getDefaultDimensions(null|int|string $storeId = null): array
    {
        return [
            'length' => max(1.0, (float) $this->getValue('default_package_length', $storeId)),
            'width' => max(1.0, (float) $this->getValue('default_package_width', $storeId)),
            'height' => max(1.0, (float) $this->getValue('default_package_height', $storeId)),
        ];
    }

    public function getSpecificErrorMessage(null|int|string $storeId = null): string
    {
        return $this->getValue('specificerrmsg', $storeId)
            ?: 'FedEx shipping is currently unavailable.';
    }

    private function getValue(string $field, null|int|string $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            self::XML_PATH_BASE . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    private function decryptConfigValue(string $field, null|int|string $storeId = null): string
    {
        $value = $this->getValue($field, $storeId);
        if ($value === '') {
            return '';
        }

        try {
            return trim((string) $this->encryptor->decrypt($value));
        } catch (\Throwable) {
            return '';
        }
    }
}
