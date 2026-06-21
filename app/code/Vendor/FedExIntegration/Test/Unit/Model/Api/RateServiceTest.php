<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Test\Unit\Model\Api;

use Magento\Directory\Model\Region;
use Magento\Directory\Model\RegionFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Api\FedExClientInterface;
use Vendor\FedExIntegration\Model\Api\RateService;
use Vendor\FedExIntegration\Model\Config;

class RateServiceTest extends TestCase
{
    public function testMapsFedExRateReplyDetailsToMagentoRates(): void
    {
        $config = $this->createMock(Config::class);
        $client = $this->createMock(FedExClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $regionFactory = $this->createMock(RegionFactory::class);

        $request = new RateRequest();
        $request->setData([
            'store_id' => 1,
            'package_weight' => 5.0,
            'package_qty' => 1,
            'orig_street' => '1 Origin St',
            'orig_city' => 'Memphis',
            'orig_region_code' => 'TN',
            'orig_postcode' => '38116',
            'orig_country_id' => 'US',
            'dest_street' => '100 Market St',
            'dest_city' => 'San Francisco',
            'dest_region_code' => 'CA',
            'dest_postcode' => '94105',
            'dest_country_id' => 'US',
        ]);

        $config->method('getAccountNumber')->with(1)->willReturn('123456789');
        $config->method('getPickupType')->with(1)->willReturn('USE_SCHEDULED_PICKUP');
        $config->method('getPackagingType')->with(1)->willReturn('YOUR_PACKAGING');
        $config->method('getDefaultWeightUnit')->with(1)->willReturn('LB');
        $config->method('getDefaultDimensionUnit')->with(1)->willReturn('IN');
        $config->method('getDefaultDimensions')->with(1)->willReturn([
            'length' => 10.0,
            'width' => 8.0,
            'height' => 4.0,
        ]);
        $config->method('getEndpointPath')->with('rates', 1)->willReturn('/rate/v1/rates/quotes');
        $config->method('isMethodAllowed')->with('FEDEX_2_DAY', 1)->willReturn(true);
        $config->method('getMethodLabel')->with('FEDEX_2_DAY')->willReturn('FedEx 2Day');

        $client->expects($this->once())->method('post')->with(
            '/rate/v1/rates/quotes',
            $this->callback(static fn (array $payload): bool => $payload['accountNumber']['value'] === '123456789'),
            1
        )->willReturn([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_2_DAY',
                        'ratedShipmentDetails' => [
                            [
                                'totalNetCharge' => 42.75,
                                'currency' => 'USD',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new RateService($config, $client, $logger, $scopeConfig, $regionFactory);

        self::assertSame([
            [
                'method_code' => 'FEDEX_2_DAY',
                'method_title' => 'FedEx 2Day',
                'amount' => 42.75,
                'currency' => 'USD',
            ],
        ], $service->getRates($request));
    }

    public function testUsesMagentoShippingOriginWhenRequestOriginIsMissing(): void
    {
        $config = $this->createMock(Config::class);
        $client = $this->createMock(FedExClientInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $regionFactory = $this->createMock(RegionFactory::class);
        $region = $this->createMock(Region::class);

        $request = new RateRequest();
        $request->setData([
            'store_id' => 1,
            'package_weight' => 5.0,
            'package_qty' => 1,
            'dest_street' => '100 Market St',
            'dest_city' => 'San Francisco',
            'dest_region_code' => 'CA',
            'dest_postcode' => '94105',
            'dest_country_id' => 'US',
        ]);

        $config->method('getAccountNumber')->with(1)->willReturn('123456789');
        $config->method('getPickupType')->with(1)->willReturn('USE_SCHEDULED_PICKUP');
        $config->method('getPackagingType')->with(1)->willReturn('YOUR_PACKAGING');
        $config->method('getDefaultWeightUnit')->with(1)->willReturn('LB');
        $config->method('getDefaultDimensionUnit')->with(1)->willReturn('IN');
        $config->method('getDefaultDimensions')->with(1)->willReturn([
            'length' => 10.0,
            'width' => 8.0,
            'height' => 4.0,
        ]);
        $config->method('getEndpointPath')->with('rates', 1)->willReturn('/rate/v1/rates/quotes');
        $config->method('isMethodAllowed')->with('FEDEX_2_DAY', 1)->willReturn(true);
        $config->method('getMethodLabel')->with('FEDEX_2_DAY')->willReturn('FedEx 2Day');

        $scopeConfig->method('getValue')->willReturnMap([
            ['shipping/origin/street_line1', ScopeInterface::SCOPE_STORE, 1, '1 Origin St'],
            ['shipping/origin/street_line2', ScopeInterface::SCOPE_STORE, 1, ''],
            ['shipping/origin/city', ScopeInterface::SCOPE_STORE, 1, 'Memphis'],
            ['shipping/origin/region_id', ScopeInterface::SCOPE_STORE, 1, '43'],
            ['shipping/origin/postcode', ScopeInterface::SCOPE_STORE, 1, '38116'],
            ['shipping/origin/country_id', ScopeInterface::SCOPE_STORE, 1, 'US'],
        ]);
        $regionFactory->method('create')->willReturn($region);
        $region->method('load')->with(43)->willReturnSelf();
        $region->method('getCode')->willReturn('TN');

        $client->expects($this->once())->method('post')->with(
            '/rate/v1/rates/quotes',
            $this->callback(static function (array $payload): bool {
                $shipperAddress = $payload['requestedShipment']['shipper']['address'];

                return $shipperAddress['postalCode'] === '38116'
                    && $shipperAddress['countryCode'] === 'US'
                    && $shipperAddress['stateOrProvinceCode'] === 'TN';
            }),
            1
        )->willReturn([
            'output' => [
                'rateReplyDetails' => [
                    [
                        'serviceType' => 'FEDEX_2_DAY',
                        'ratedShipmentDetails' => [
                            [
                                'totalNetCharge' => 42.75,
                                'currency' => 'USD',
                            ],
                        ],
                    ],
                ],
            ],
        ]);

        $service = new RateService($config, $client, $logger, $scopeConfig, $regionFactory);

        self::assertCount(1, $service->getRates($request));
    }
}
