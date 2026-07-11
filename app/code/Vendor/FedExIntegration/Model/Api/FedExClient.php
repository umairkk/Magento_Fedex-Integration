<?php

declare(strict_types=1);

namespace Vendor\FedExIntegration\Model\Api;

use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Psr\Log\LoggerInterface;
use Vendor\FedExIntegration\Api\FedExClientInterface;
use Vendor\FedExIntegration\Api\OAuthClientInterface;
use Vendor\FedExIntegration\Model\Config;

class FedExClient implements FedExClientInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly OAuthClientInterface $oauthClient,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function post(string $path, array $payload, null|int|string $storeId = null): array
    {
        return $this->request($path, $payload, $storeId);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function request(
        string $path,
        array $payload,
        null|int|string $storeId = null,
        bool $forceTokenRefresh = false,
        bool $hasRetried = false
    ): array {
        $url = $this->config->getBaseUrl($storeId) . '/' . ltrim($path, '/');
        $accessToken = $this->oauthClient->getAccessToken($storeId, $forceTokenRefresh);
        $requestBody = $this->json->serialize($payload);

        $curl = $this->curlFactory->create();
        $curl->setOption(CURLOPT_TIMEOUT, $this->config->getTimeout($storeId));
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, min(10, $this->config->getTimeout($storeId)));
        $curl->setHeaders([
            'Authorization' => 'Bearer ' . $accessToken,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ]);

        try {
            $curl->post($url, $requestBody);
        } catch (\Throwable $exception) {
            $this->logger->error('FedEx API request failed before receiving a response.', [
                'exception' => $exception->getMessage(),
                'url' => $url,
                'payload' => $this->sanitize($payload),
            ]);
            throw new FedExApiException(__('Unable to connect to FedEx at this time.'), 0, $exception);
        }

        $statusCode = (int) $curl->getStatus();
        $responseBody = (string) $curl->getBody();
        $response = $this->decodeResponse($responseBody);

        if ($statusCode === 401 && !$hasRetried) {
            $this->oauthClient->invalidateToken($storeId);
            return $this->request($path, $payload, $storeId, true, true);
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            $level = $statusCode >= 500 ? 'error' : 'warning';
            $this->logger->{$level}('FedEx API returned an error response.', [
                'status' => $statusCode,
                'url' => $url,
                'payload' => $this->sanitize($payload),
                'response' => $this->sanitize($response),
            ]);

            throw new FedExApiException($this->getErrorPhrase($statusCode), $statusCode);
        }

        $this->logger->info('FedEx API request completed.', [
            'status' => $statusCode,
            'url' => $url,
            'response_summary' => $this->summarizeResponse($response),
        ]);

        return $response;
    }

    private function getErrorPhrase(int $statusCode): \Magento\Framework\Phrase
    {
        return match ($statusCode) {
            400 => __('FedEx rejected the request. Please verify the address and shipment details.'),
            401, 403 => __('FedEx authentication failed. Please verify API credentials.'),
            404 => __('FedEx service endpoint was not found.'),
            408 => __('FedEx request timed out. Please try again.'),
            429 => __('FedEx rate limit was reached. Please try again later.'),
            500, 502, 503, 504 => __('FedEx service is temporarily unavailable. Please try again later.'),
            default => __('FedEx returned an unexpected error.'),
        };
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
     * @param array<string, mixed> $response
     * @return array<string, mixed>
     */
    private function summarizeResponse(array $response): array
    {
        $summary = [];
        foreach (['transactionId', 'customerTransactionId'] as $field) {
            if (isset($response[$field])) {
                $summary[$field] = $response[$field];
            }
        }

        if (isset($response['output']) && is_array($response['output'])) {
            $summary['output_keys'] = array_keys($response['output']);
        }

        return $summary;
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
            $sanitized[$keyName] = preg_match(
                '/token|secret|authorization|password|encodedlabel|label|document/i',
                $keyName
            )
                ? '[redacted]'
                : $this->sanitize($value);
        }

        return $sanitized;
    }
}
