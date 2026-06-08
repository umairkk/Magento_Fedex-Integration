<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Test\Unit\Model\Api;

use PHPUnit\Framework\TestCase;
use Vendor\FedExIntegration\Api\FedExClientInterface;
use Vendor\FedExIntegration\Model\Api\TrackingService;
use Vendor\FedExIntegration\Model\Config;

class TrackingServiceTest extends TestCase
{
    public function testMapsFedExTrackingEvents(): void
    {
        $config = $this->createMock(Config::class);
        $client = $this->createMock(FedExClientInterface::class);

        $config->method('getEndpointPath')->with('track', null)->willReturn('/track/v1/trackingnumbers');
        $client->expects($this->once())->method('post')->with(
            '/track/v1/trackingnumbers',
            $this->callback(static fn (array $payload): bool => $payload['trackingInfo'][0]['trackingNumberInfo']['trackingNumber'] === '123'),
            null
        )->willReturn([
            'output' => [
                'completeTrackResults' => [
                    [
                        'trackResults' => [
                            [
                                'latestStatusDetail' => [
                                    'code' => 'DL',
                                    'description' => 'Delivered',
                                ],
                                'scanEvents' => [
                                    [
                                        'date' => '2026-06-08T10:30:00-05:00',
                                        'eventDescription' => 'Delivered',
                                        'scanLocation' => [
                                            'city' => 'Austin',
                                            'stateOrProvinceCode' => 'TX',
                                            'countryCode' => 'US',
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new TrackingService($config, $client);
        $result = $service->track('123');

        self::assertSame('Delivered', $result['status_description']);
        self::assertSame('Delivered', $result['events'][0]['activity']);
        self::assertSame('Austin, TX, US', $result['events'][0]['deliverylocation']);
    }
}
