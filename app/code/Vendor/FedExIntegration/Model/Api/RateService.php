<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Api;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Api\FedExClientInterface;
use Vendor\FedExIntegration\Api\RateServiceInterface;
use Vendor\FedExIntegration\Model\Config;

class RateService implements RateServiceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly FedExClientInterface $fedExClient,
        private readonly LoggerInterface $logger,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RegionFactory $regionFactory
    ) {
    }

    /**
     * @return array<int, array{method_code: string, method_title: string, amount: float, currency: string}>
     */
    public function getRates(RateRequest $request): array
    {
        $storeId = $request->getStoreId();
        $payload = $this->buildRateRequest($request);
        $response = $this->fedExClient->post($this->config->getEndpointPath('rates', $storeId), $payload, $storeId);

        return $this->extractRates($response, $storeId);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRateRequest(RateRequest $request): array
    {
        $storeId = $request->getStoreId();
        $accountNumber = $this->config->getAccountNumber($storeId);
        if ($accountNumber === '') {
            throw new LocalizedException(__('FedEx account number is not configured.'));
        }

        $weight = (float) $request->getPackageWeight();
        if ($weight <= 0.0) {
            throw new LocalizedException(__('Package weight is required to retrieve FedEx rates.'));
        }

        return [
            'accountNumber' => [
                'value' => $accountNumber,
            ],
            'requestedShipment' => [
                'shipper' => [
                    'address' => $this->buildOriginAddress($request),
                ],
                'recipient' => [
                    'address' => $this->buildAddress(
                        'destination',
                        $this->normalizeStreet($request->getDestStreet()),
                        (string) $request->getDestCity(),
                        (string) $request->getDestRegionCode(),
                        (string) $request->getDestPostcode(),
                        (string) $request->getDestCountryId(),
                        (bool) $request->getDestResidential()
                    ),
                ],
                'pickupType' => $this->config->getPickupType($storeId),
                'packagingType' => $this->config->getPackagingType($storeId),
                'rateRequestType' => ['ACCOUNT', 'LIST'],
                'requestedPackageLineItems' => [
                    $this->buildPackageLineItem($request),
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildAddress(
        string $addressType,
        string $street,
        string $city,
        string $regionCode,
        string $postcode,
        string $countryCode,
        bool $residential = false
    ): array {
        $missingFields = [];
        if ($postcode === '') {
            $missingFields[] = 'postcode';
        }
        if ($countryCode === '') {
            $missingFields[] = 'country';
        }
        if ($missingFields !== []) {
            throw new LocalizedException(__(
                'FedEx %1 shipping address is missing required field(s): %2.',
                $addressType,
                implode(', ', $missingFields)
            ));
        }

        $address = [
            'postalCode' => $postcode,
            'countryCode' => $countryCode,
            'residential' => $residential,
        ];

        if ($street !== '') {
            $streetLines = preg_split('/\r\n|\r|\n/', $street) ?: [];
            $address['streetLines'] = array_values(array_filter(array_map('trim', $streetLines)));
        }
        if ($city !== '') {
            $address['city'] = $city;
        }
        if ($regionCode !== '') {
            $address['stateOrProvinceCode'] = $regionCode;
        }

        return $address;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOriginAddress(RateRequest $request): array
    {
        $storeId = $request->getStoreId();

        return $this->buildAddress(
            'origin',
            $this->normalizeStreet($request->getOrigStreet())
                ?: $this->normalizeStreet(array_filter([
                    $this->getOriginConfigValue('street_line1', $storeId),
                    $this->getOriginConfigValue('street_line2', $storeId),
                ])),
            (string) ($request->getOrigCity() ?: $this->getOriginConfigValue('city', $storeId)),
            (string) ($request->getOrigRegionCode() ?: $this->getOriginRegionCode($storeId)),
            (string) ($request->getOrigPostcode() ?: $this->getOriginConfigValue('postcode', $storeId)),
            (string) ($request->getOrigCountryId() ?: $this->getOriginConfigValue('country_id', $storeId))
        );
    }

    private function getOriginConfigValue(string $field, null|int|string $storeId = null): string
    {
        return trim((string) $this->scopeConfig->getValue(
            'shipping/origin/' . $field,
            ScopeInterface::SCOPE_STORE,
            $storeId
        ));
    }

    private function getOriginRegionCode(null|int|string $storeId = null): string
    {
        $regionCode = $this->getOriginConfigValue('region_id', $storeId);
        if ($regionCode === '') {
            return '';
        }

        $region = $this->regionFactory->create()->load((int) $regionCode);

        return (string) ($region->getCode() ?: $region->getDefaultName() ?: $regionCode);
    }

    /**
     * @param mixed $street
     */
    private function normalizeStreet(mixed $street): string
    {
        if (is_array($street)) {
            return implode("\n", array_filter(array_map('trim', $street)));
        }

        return trim((string) $street);
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPackageLineItem(RateRequest $request): array
    {
        $storeId = $request->getStoreId();
        $packageCount = max(1, (int) $request->getPackageQty());
        $dimensions = $this->getPackageDimensions($request);

        return [
            'groupPackageCount' => $packageCount,
            'weight' => [
                'units' => $this->config->getDefaultWeightUnit($storeId),
                'value' => round(max(0.1, (float) $request->getPackageWeight() / $packageCount), 2),
            ],
            'dimensions' => [
                'length' => $dimensions['length'],
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'units' => $this->config->getDefaultDimensionUnit($storeId),
            ],
        ];
    }

    /**
     * @return array{length: float, width: float, height: float}
     */
    private function getPackageDimensions(RateRequest $request): array
    {
        $defaults = $this->config->getDefaultDimensions($request->getStoreId());

        return [
            'length' => $this->getRequestDimension($request, 'length', $defaults['length']),
            'width' => $this->getRequestDimension($request, 'width', $defaults['width']),
            'height' => $this->getRequestDimension($request, 'height', $defaults['height']),
        ];
    }

    private function getRequestDimension(RateRequest $request, string $dimension, float $default): float
    {
        $value = $request->getData('package_' . $dimension)
            ?: $request->getData('package_' . ucfirst($dimension))
            ?: $request->getData($dimension);

        return max(1.0, (float) ($value ?: $default));
    }

    /**
     * @param array<string, mixed> $response
     * @return array<int, array{method_code: string, method_title: string, amount: float, currency: string}>
     */
    private function extractRates(array $response, null|int|string $storeId = null): array
    {
        $rateReplyDetails = $response['output']['rateReplyDetails'] ?? [];
        if (!is_array($rateReplyDetails)) {
            $this->logger->warning('FedEx rate response did not include rate reply details.', [
                'response_keys' => array_keys($response),
                'output_keys' => isset($response['output']) && is_array($response['output'])
                    ? array_keys($response['output'])
                    : [],
            ]);
            return [];
        }

        $rates = [];
        $returnedServiceCodes = [];
        $filteredServiceCodes = [];
        foreach ($rateReplyDetails as $detail) {
            if (!is_array($detail)) {
                continue;
            }

            $serviceCode = (string) ($detail['serviceType'] ?? '');
            if ($serviceCode !== '') {
                $returnedServiceCodes[] = $serviceCode;
            }
            if ($serviceCode === '' || !$this->config->isMethodAllowed($serviceCode, $storeId)) {
                if ($serviceCode !== '') {
                    $filteredServiceCodes[] = $serviceCode;
                }
                continue;
            }

            $charge = $this->extractCharge($detail);
            if ($charge === null) {
                $this->logger->warning('FedEx rate response did not contain a usable charge.', [
                    'service_type' => $serviceCode,
                ]);
                continue;
            }

            $rates[] = [
                'method_code' => $serviceCode,
                'method_title' => $this->config->getMethodLabel($serviceCode),
                'amount' => $charge['amount'],
                'currency' => $charge['currency'],
            ];
        }

        if ($rates === []) {
            $this->logger->warning('FedEx rate response produced no enabled Magento rates.', [
                'returned_service_codes' => array_values(array_unique($returnedServiceCodes)),
                'filtered_service_codes' => array_values(array_unique($filteredServiceCodes)),
                'allowed_methods' => $this->config->getAllowedMethods($storeId),
            ]);
        }

        return $rates;
    }

    /**
     * @param array<string, mixed> $detail
     * @return array{amount: float, currency: string}|null
     */
    private function extractCharge(array $detail): ?array
    {
        $ratedShipmentDetails = $detail['ratedShipmentDetails'] ?? [];
        if (!is_array($ratedShipmentDetails)) {
            return null;
        }

        foreach ($ratedShipmentDetails as $ratedShipmentDetail) {
            if (!is_array($ratedShipmentDetail)) {
                continue;
            }

            $shipmentRateDetail = is_array($ratedShipmentDetail['shipmentRateDetail'] ?? null)
                ? $ratedShipmentDetail['shipmentRateDetail']
                : [];

            $amount = $ratedShipmentDetail['totalNetCharge']
                ?? $shipmentRateDetail['totalNetCharge']
                ?? $shipmentRateDetail['totalNetFedExCharge']
                ?? null;

            if ($amount === null) {
                continue;
            }

            return [
                'amount' => (float) $amount,
                'currency' => (string) (
                    $ratedShipmentDetail['currency']
                    ?? $shipmentRateDetail['currency']
                    ?? 'USD'
                ),
            ];
        }

        return null;
    }
}
