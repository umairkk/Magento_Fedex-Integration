<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Test\Unit\Model\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Model\Api\OAuthClient;
use Vendor\FedExIntegration\Model\Config;

class OAuthClientTest extends TestCase
{
    public function testRequestsAndCachesClientCredentialsToken(): void
    {
        $config = $this->createMock(Config::class);
        $cache = $this->createMock(CacheInterface::class);
        $curlFactory = $this->createMock(CurlFactory::class);
        $curl = $this->createMock(Curl::class);
        $json = $this->createMock(Json::class);
        $logger = $this->createMock(LoggerInterface::class);

        $config->method('getEnvironment')->willReturn('sandbox');
        $config->method('getApiKey')->willReturn('client-id');
        $config->method('getSecretKey')->willReturn('client-secret');
        $config->method('getTimeout')->willReturn(30);
        $config->method('getBaseUrl')->willReturn('https://apis-sandbox.fedex.com');
        $config->method('getEndpointPath')->with('oauth', null)->willReturn('/oauth/token');
        $config->method('getTokenTtlBuffer')->willReturn(120);

        $cache->method('load')->willReturn(false);
        $cache->expects($this->once())->method('save')->with(
            'serialized-token',
            $this->isType('string'),
            ['VENDOR_FEDEX_OAUTH'],
            3480
        );

        $curlFactory->method('create')->willReturn($curl);
        $curl->expects($this->once())->method('post')->with(
            'https://apis-sandbox.fedex.com/oauth/token',
            $this->stringContains('grant_type=client_credentials')
        );
        $curl->method('getStatus')->willReturn(200);
        $curl->method('getBody')->willReturn('{"access_token":"fedex-token","expires_in":3600}');

        $json->method('unserialize')->willReturn([
            'access_token' => 'fedex-token',
            'expires_in' => 3600,
        ]);
        $json->method('serialize')->willReturn('serialized-token');

        $client = new OAuthClient($config, $cache, $curlFactory, $json, $logger);

        self::assertSame('fedex-token', $client->getAccessToken());
    }
}
