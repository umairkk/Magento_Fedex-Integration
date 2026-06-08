<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Api;

use Magento\Framework\Exception\LocalizedException;
use Vendor\FedExIntegration\Api\FedExClientInterface;
use Vendor\FedExIntegration\Api\TrackingServiceInterface;
use Vendor\FedExIntegration\Model\Config;

class TrackingService implements TrackingServiceInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly FedExClientInterface $fedExClient
    ) {
    }

    /**
     * @return array{tracking_number: string, status: string, status_description: string, events: array<int, array<string, mixed>>, raw: array<string, mixed>}
     */
    public function track(string $trackingNumber, null|int|string $storeId = null): array
    {
        $trackingNumber = trim($trackingNumber);
        if ($trackingNumber === '') {
            throw new LocalizedException(__('Tracking number is required.'));
        }

        $payload = [
            'includeDetailedScans' => true,
            'trackingInfo' => [
                [
                    'trackingNumberInfo' => [
                        'trackingNumber' => $trackingNumber,
                    ],
                ],
            ],
        ];

        $response = $this->fedExClient->post($this->config->getEndpointPath('track', $storeId), $payload, $storeId);
        $trackResult = $this->extractTrackResult($response);
        $statusDetail = is_array($trackResult['latestStatusDetail'] ?? null)
            ? $trackResult['latestStatusDetail']
            : [];

        return [
            'tracking_number' => $trackingNumber,
            'status' => (string) ($statusDetail['code'] ?? ''),
            'status_description' => (string) ($statusDetail['description'] ?? $statusDetail['statusByLocale'] ?? ''),
            'events' => $this->extractEvents($trackResult),
            'raw' => $this->sanitize($response),
        ];
    }

    /**
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function extractTrackResult(array $response): array
    {
        $trackResult = $response['output']['completeTrackResults'][0]['trackResults'][0] ?? [];
        if (!is_array($trackResult)) {
            throw new FedExApiException(__('FedEx tracking response was invalid.'));
        }

        return $trackResult;
    }

    /**
     * @param array<string, mixed> $trackResult
     * @return array<int, array<string, mixed>>
     */
    private function extractEvents(array $trackResult): array
    {
        $scanEvents = $trackResult['scanEvents'] ?? [];
        if (!is_array($scanEvents)) {
            return [];
        }

        $events = [];
        foreach ($scanEvents as $event) {
            if (!is_array($event)) {
                continue;
            }

            $date = (string) ($event['date'] ?? '');
            $location = is_array($event['scanLocation'] ?? null) ? $event['scanLocation'] : [];
            $events[] = [
                'activity' => (string) ($event['eventDescription'] ?? $event['derivedStatus'] ?? ''),
                'deliverydate' => $date !== '' ? substr($date, 0, 10) : '',
                'deliverytime' => strlen($date) > 11 ? substr($date, 11, 8) : '',
                'deliverylocation' => trim(implode(', ', array_filter([
                    $location['city'] ?? null,
                    $location['stateOrProvinceCode'] ?? null,
                    $location['postalCode'] ?? null,
                    $location['countryCode'] ?? null,
                ]))),
            ];
        }

        return $events;
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
            $sanitized[$keyName] = preg_match('/token|secret|authorization|label|document/i', $keyName)
                ? '[redacted]'
                : $this->sanitize($value);
        }

        return $sanitized;
    }
}
