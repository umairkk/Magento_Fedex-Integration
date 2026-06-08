<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Api;

use Magento\Framework\App\CacheInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Api\OAuthClientInterface;
use Vendor\FedExIntegration\Model\Config;

class OAuthClient implements OAuthClientInterface
{
    private const CACHE_TAG = 'VENDOR_FEDEX_OAUTH';
    private const CACHE_ID_PREFIX = 'vendor_fedex_oauth_';

    public function __construct(
        private readonly Config $config,
        private readonly CacheInterface $cache,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    public function getAccessToken(null|int|string $storeId = null, bool $forceRefresh = false): string
    {
        $cacheId = $this->getCacheId($storeId);

        if (!$forceRefresh) {
            $cachedToken = $this->loadCachedToken($cacheId);
            if ($cachedToken !== null) {
                return $cachedToken;
            }
        }

        $apiKey = $this->config->getApiKey($storeId);
        $secretKey = $this->config->getSecretKey($storeId);
        if ($apiKey === '' || $secretKey === '') {
            throw new FedExApiException(__('FedEx API credentials are not configured.'));
        }

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_TIMEOUT, $this->config->getTimeout($storeId));
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, min(10, $this->config->getTimeout($storeId)));
        $curl->setHeaders([
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ]);

        $url = $this->config->getBaseUrl($storeId) . $this->config->getEndpointPath('oauth', $storeId);
        $body = http_build_query([
            'grant_type' => 'client_credentials',
            'client_id' => $apiKey,
            'client_secret' => $secretKey,
        ]);

        try {
            $curl->post($url, $body);
        } catch (\Throwable $exception) {
            $this->logger->error('FedEx OAuth request failed.', [
                'exception' => $exception->getMessage(),
                'url' => $url,
            ]);
            throw new FedExApiException(__('Unable to authenticate with FedEx at this time.'), 0, $exception);
        }

        $statusCode = (int) $curl->getStatus();
        $responseBody = (string) $curl->getBody();
        $response = $this->decodeResponse($responseBody);

        if ($statusCode < 200 || $statusCode >= 300) {
            $this->logger->error('FedEx OAuth authentication failed.', [
                'status' => $statusCode,
                'response' => $this->sanitize($response),
            ]);
            throw new FedExApiException(__('FedEx authentication failed.'), $statusCode);
        }

        $accessToken = (string) ($response['access_token'] ?? '');
        $expiresIn = (int) ($response['expires_in'] ?? 0);
        if ($accessToken === '' || $expiresIn <= 0) {
            $this->logger->error('FedEx OAuth response did not contain a usable access token.', [
                'response' => $this->sanitize($response),
            ]);
            throw new FedExApiException(__('FedEx authentication response was invalid.'));
        }

        $lifetime = max(1, $expiresIn - $this->config->getTokenTtlBuffer($storeId));
        $this->cache->save(
            $this->json->serialize([
                'access_token' => $accessToken,
                'expires_at' => time() + $lifetime,
            ]),
            $cacheId,
            [self::CACHE_TAG],
            $lifetime
        );

        return $accessToken;
    }

    public function invalidateToken(null|int|string $storeId = null): void
    {
        $this->cache->remove($this->getCacheId($storeId));
    }

    private function getCacheId(null|int|string $storeId = null): string
    {
        return self::CACHE_ID_PREFIX . sha1(implode('|', [
            $this->config->getEnvironment($storeId),
            $this->config->getApiKey($storeId),
            (string) $storeId,
        ]));
    }

    private function loadCachedToken(string $cacheId): ?string
    {
        $cached = $this->cache->load($cacheId);
        if (!is_string($cached) || $cached === '') {
            return null;
        }

        try {
            $data = $this->json->unserialize($cached);
        } catch (\InvalidArgumentException) {
            return null;
        }

        if (!is_array($data) || empty($data['access_token']) || (int) ($data['expires_at'] ?? 0) <= time()) {
            return null;
        }

        return (string) $data['access_token'];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeResponse(string $responseBody): array
    {
        if ($responseBody === '') {
            return [];
        }

        try {
            $decoded = $this->json->unserialize($responseBody);
        } catch (\InvalidArgumentException) {
            return ['raw_response' => $responseBody];
        }

        return is_array($decoded) ? $decoded : [];
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
            $sanitized[$keyName] = preg_match('/token|secret|client_secret|authorization/i', $keyName)
                ? '[redacted]'
                : $this->sanitize($value);
        }

        return $sanitized;
    }
}
