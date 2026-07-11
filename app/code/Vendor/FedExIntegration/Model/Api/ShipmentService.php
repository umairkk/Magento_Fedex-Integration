<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Api;

use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Sales\Api\Data\OrderAddressInterface;
use Magento\Sales\Api\Data\ShipmentInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\ShipmentRepositoryInterface;
use Magento\Sales\Model\Order\Shipment\TrackFactory;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Api\FedExClientInterface;
use Vendor\FedExIntegration\Api\ShipmentServiceInterface;
use Vendor\FedExIntegration\Model\Carrier\FedEx;
use Vendor\FedExIntegration\Model\Config;

class ShipmentService implements ShipmentServiceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly FedExClientInterface $fedExClient,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly ShipmentRepositoryInterface $shipmentRepository,
        private readonly TrackFactory $trackFactory,
        private readonly Filesystem $filesystem,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly RegionFactory $regionFactory,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $options
     * @return array{tracking_number: string, label_path: string, fedex_response: array<string, mixed>}
     */
    public function createShipment(ShipmentInterface $shipment, array $options = []): array
    {
        $storeId = $shipment->getStoreId();
        $payload = $this->buildShipmentRequest($shipment, $options);
        $response = $this->fedExClient->post($this->config->getEndpointPath('ship', $storeId), $payload, $storeId);

        $trackingNumber = $this->extractTrackingNumber($response);
        $labelContent = $this->extractLabelContent($response);
        if ($trackingNumber === '' || $labelContent === '') {
            $this->logger->error('FedEx Ship API response was missing tracking number or label.', [
                'response' => $this->sanitize($response),
            ]);
            throw new FedExApiException(__('FedEx shipment response did not include a tracking number and label.'));
        }

        $labelPath = $this->saveLabel($shipment, $trackingNumber, $labelContent);
        $this->attachTracking($shipment, $trackingNumber, $labelContent);

        return [
            'tracking_number' => $trackingNumber,
            'label_path' => $labelPath,
            'fedex_response' => $this->sanitize($response),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildShipmentRequest(ShipmentInterface $shipment, array $options): array
    {
        $storeId = $shipment->getStoreId();
        $orderId = (int) $shipment->getOrderId();
        if ($orderId <= 0) {
            throw new LocalizedException(__('Shipment must be linked to an order before creating a FedEx shipment.'));
        }

        $order = $this->orderRepository->get($orderId);
        $shippingAddress = $order->getShippingAddress();
        if (!$shippingAddress instanceof OrderAddressInterface) {
            throw new LocalizedException(__('Order does not have a shipping address.'));
        }

        $accountNumber = $this->config->getAccountNumber($storeId);
        if ($accountNumber === '') {
            throw new LocalizedException(__('FedEx account number is not configured.'));
        }

        $allowedMethods = $this->config->getAllowedMethods($storeId);
        $serviceType = (string) ($options['service_type'] ?? ($allowedMethods[0] ?? ''));
        if (!isset(Config::SUPPORTED_METHODS[$serviceType])) {
            throw new LocalizedException(__('Unsupported FedEx service type: %1.', $serviceType));
        }

        return [
            'labelResponseOptions' => 'LABEL',
            'accountNumber' => [
                'value' => $accountNumber,
            ],
            'requestedShipment' => [
                'shipDatestamp' => (new \DateTimeImmutable())->format('Y-m-d'),
                'pickupType' => $this->config->getPickupType($storeId),
                'serviceType' => $serviceType,
                'packagingType' => $this->config->getPackagingType($storeId),
                'shipper' => $this->buildShipper($storeId, $options),
                'recipients' => [
                    $this->buildRecipient($shippingAddress),
                ],
                'shippingChargesPayment' => [
                    'paymentType' => 'SENDER',
                    'payor' => [
                        'responsibleParty' => [
                            'accountNumber' => [
                                'value' => $accountNumber,
                            ],
                        ],
                    ],
                ],
                'labelSpecification' => [
                    'imageType' => 'PDF',
                    'labelStockType' => (string) ($options['label_stock_type'] ?? 'PAPER_85X11_TOP_HALF_LABEL'),
                ],
                'requestedPackageLineItems' => [
                    $this->buildPackageLineItem($shipment, $options),
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildShipper(null|int|string $storeId, array $options): array
    {
        $phone = $this->normalizePhone((string) ($options['shipper_phone'] ?? $this->config->getShipperPhone($storeId)));
        if ($phone === '') {
            throw new LocalizedException(__('FedEx shipper phone is required to create shipments.'));
        }

        return [
            'contact' => [
                'companyName' => (string) ($options['shipper_company'] ?? $this->config->getShipperCompany($storeId)),
                'phoneNumber' => $phone,
            ],
            'address' => $this->buildOriginAddress($storeId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRecipient(OrderAddressInterface $address): array
    {
        $phone = $this->normalizePhone((string) $address->getTelephone());
        if ($phone === '') {
            throw new LocalizedException(__('Recipient phone is required to create FedEx shipments.'));
        }

        return [
            'contact' => [
                'personName' => trim((string) $address->getFirstname() . ' ' . (string) $address->getLastname()),
                'companyName' => (string) $address->getCompany(),
                'phoneNumber' => $phone,
            ],
            'address' => [
                'streetLines' => $this->getStreetLines($address),
                'city' => (string) $address->getCity(),
                'stateOrProvinceCode' => (string) $address->getRegionCode(),
                'postalCode' => (string) $address->getPostcode(),
                'countryCode' => (string) $address->getCountryId(),
                'residential' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildOriginAddress(null|int|string $storeId): array
    {
        $regionId = (int) $this->scopeConfig->getValue(
            'shipping/origin/region_id',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
        $regionCode = '';
        if ($regionId > 0) {
            $region = $this->regionFactory->create()->load($regionId);
            $regionCode = (string) ($region->getCode() ?: $region->getDefaultName());
        }

        return [
            'streetLines' => array_values(array_filter([
                (string) $this->scopeConfig->getValue('shipping/origin/street_line1', ScopeInterface::SCOPE_STORE, $storeId),
                (string) $this->scopeConfig->getValue('shipping/origin/street_line2', ScopeInterface::SCOPE_STORE, $storeId),
            ])),
            'city' => (string) $this->scopeConfig->getValue('shipping/origin/city', ScopeInterface::SCOPE_STORE, $storeId),
            'stateOrProvinceCode' => $regionCode,
            'postalCode' => (string) $this->scopeConfig->getValue('shipping/origin/postcode', ScopeInterface::SCOPE_STORE, $storeId),
            'countryCode' => (string) $this->scopeConfig->getValue('shipping/origin/country_id', ScopeInterface::SCOPE_STORE, $storeId),
        ];
    }

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    private function buildPackageLineItem(ShipmentInterface $shipment, array $options): array
    {
        $storeId = $shipment->getStoreId();
        $dimensions = is_array($options['dimensions'] ?? null)
            ? $options['dimensions']
            : $this->config->getDefaultDimensions($storeId);

        return [
            'weight' => [
                'units' => (string) ($options['weight_unit'] ?? $this->config->getDefaultWeightUnit($storeId)),
                'value' => round(max(0.1, (float) ($options['weight'] ?? $this->getShipmentWeight($shipment))), 2),
            ],
            'dimensions' => [
                'length' => max(1, (float) ($dimensions['length'] ?? 1)),
                'width' => max(1, (float) ($dimensions['width'] ?? 1)),
                'height' => max(1, (float) ($dimensions['height'] ?? 1)),
                'units' => (string) ($options['dimension_unit'] ?? $this->config->getDefaultDimensionUnit($storeId)),
            ],
        ];
    }

    private function getShipmentWeight(ShipmentInterface $shipment): float
    {
        if (method_exists($shipment, 'getTotalWeight')) {
            return (float) $shipment->getTotalWeight();
        }
        if (method_exists($shipment, 'getData')) {
            return (float) $shipment->getData('total_weight');
        }

        return 0.1;
    }

    /**
     * @return array<int, string>
     */
    private function getStreetLines(OrderAddressInterface $address): array
    {
        $street = $address->getStreet();
        if (is_array($street)) {
            return array_values(array_filter(array_map('trim', $street)));
        }

        $streetLines = preg_split('/\r\n|\r|\n/', (string) $street) ?: [];

        return array_values(array_filter(array_map('trim', $streetLines)));
    }

    private function normalizePhone(string $phone): string
    {
        return (string) preg_replace('/[^\d+]/', '', $phone);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractTrackingNumber(array $response): string
    {
        $transactionShipment = $response['output']['transactionShipments'][0] ?? [];
        if (!is_array($transactionShipment)) {
            return '';
        }

        return (string) (
            $transactionShipment['masterTrackingNumber']
            ?? $transactionShipment['pieceResponses'][0]['trackingNumber']
            ?? ''
        );
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractLabelContent(array $response): string
    {
        $encodedLabel = $this->findFirstValueByKey($response, 'encodedLabel');
        if (!is_string($encodedLabel) || $encodedLabel === '') {
            return '';
        }

        $decoded = base64_decode($encodedLabel, true);
        return $decoded === false ? '' : $decoded;
    }

    /**
     * @param mixed $data
     */
    private function findFirstValueByKey(mixed $data, string $searchKey): mixed
    {
        if (!is_array($data)) {
            return null;
        }

        foreach ($data as $key => $value) {
            if ((string) $key === $searchKey) {
                return $value;
            }

            $found = $this->findFirstValueByKey($value, $searchKey);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    private function saveLabel(ShipmentInterface $shipment, string $trackingNumber, string $labelContent): string
    {
        $mediaDirectory = $this->filesystem->getDirectoryWrite(DirectoryList::MEDIA);
        $mediaDirectory->create('fedex_labels');
        $shipmentIdentifier = method_exists($shipment, 'getIncrementId')
            ? (string) $shipment->getIncrementId()
            : (string) $shipment->getEntityId();
        $filename = sprintf(
            'fedex_labels/%s_%s.pdf',
            preg_replace('/[^A-Za-z0-9_-]/', '_', $shipmentIdentifier ?: 'shipment'),
            preg_replace('/[^A-Za-z0-9_-]/', '_', $trackingNumber)
        );

        $mediaDirectory->writeFile($filename, $labelContent);

        return $filename;
    }

    private function attachTracking(ShipmentInterface $shipment, string $trackingNumber, string $labelContent): void
    {
        $track = $this->trackFactory->create();
        $track->setCarrierCode(FedEx::CODE);
        $track->setTitle($this->config->getCarrierTitle($shipment->getStoreId()));
        $track->setTrackNumber($trackingNumber);

        if (method_exists($shipment, 'addTrack')) {
            $shipment->addTrack($track);
        }
        if (method_exists($shipment, 'setShippingLabel')) {
            $shipment->setShippingLabel($labelContent);
        }

        $this->shipmentRepository->save($shipment);
    }

    /**
     * @param mixed $data
     * @return mixed
     */
    private function sanitize(mixed $data): mixed
    {
        if (!is_array($data)) {
            return $data;
        }

        $sanitized = [];
        foreach ($data as $key => $value) {
            $keyName = (string) $key;
            $sanitized[$keyName] = preg_match('/label|document|token|secret/i', $keyName)
                ? '[redacted]'
                : $this->sanitize($value);
        }

        return $sanitized;
    }
}
