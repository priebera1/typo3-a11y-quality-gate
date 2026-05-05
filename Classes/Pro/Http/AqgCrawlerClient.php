<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Http;

use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerResultsResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSummaryResponseDto;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Psr\Http\Client\ClientExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;

final class AqgCrawlerClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    public function submit(
        string $accessToken,
        string $siteId,
        string $startUrl,
        ?string $sitemapUrl,
        RemoteScanSourceType $sourceType,
        int $maxPages = 20,
        bool $followLinks = true,
        string $axeLocale = 'en',
        bool $captureScreenshot = false,
        bool $cookieDismiss = true,
    ): CrawlerSubmitResponseDto {
        $payload = $this->requestJson(
            '/crawl/submit',
            'POST',
            $accessToken,
            [
                'siteId' => $siteId,
                'sourceType' => $sourceType->value,
                'startUrl' => $startUrl,
                'sitemapUrl' => $sitemapUrl,
                'maxPages' => $maxPages,
                'followLinks' => $followLinks,
                'axeLocale' => $axeLocale,
                'captureScreenshot' => $captureScreenshot,
                'cookieDismiss' => $cookieDismiss,
            ]
        );

        return CrawlerSubmitResponseDto::fromArray($payload);
    }

    public function status(string $accessToken, string $jobId): CrawlerStatusResponseDto
    {
        $payload = $this->requestJson(
            '/crawl/status/' . rawurlencode($jobId),
            'GET',
            $accessToken
        );

        return CrawlerStatusResponseDto::fromArray($payload);
    }

    public function summary(string $accessToken, string $jobId): CrawlerSummaryResponseDto
    {
        $payload = $this->requestJson(
            '/crawl/summary/' . rawurlencode($jobId),
            'GET',
            $accessToken
        );

        return CrawlerSummaryResponseDto::fromArray($payload);
    }

    public function results(string $accessToken, string $jobId): CrawlerResultsResponseDto
    {
        $payload = $this->requestJson(
            '/crawl/results/' . rawurlencode($jobId),
            'GET',
            $accessToken
        );

        return CrawlerResultsResponseDto::fromArray($payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function requestJson(string $path, string $method, string $accessToken, array $payload = []): array
    {
        $url = rtrim(ProSettings::resolveCrawlerBaseUrl(), '/') . $path;

        $options = [
            'headers' => [
                'Accept' => 'application/json',
                'Authorization' => 'Bearer ' . $accessToken,
            ],
            'timeout' => ProConstants::REQUEST_TIMEOUT,
            'http_errors' => false,
            'allow_redirects' => false,
        ];

        if ($method === 'POST') {
            $options['headers']['Content-Type'] = 'application/json';
            $options['body'] = json_encode($payload, JSON_THROW_ON_ERROR);
        }

        try {
            $response = $this->requestFactory->request($url, $method, $options);
        } catch (ClientExceptionInterface|\JsonException $exception) {
            throw new ApiRequestFailedException(
                'AQG crawler request failed: '
                . $exception->getMessage()
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($payload),
                0,
                $exception
            );
        }

        $statusCode = $response->getStatusCode();
        $body = trim((string)$response->getBody());

        if ($body === '') {
            throw new ApiRequestFailedException(
                'AQG crawler returned empty response body'
                . ' | http=' . $statusCode
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($payload)
            );
        }

        try {
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiRequestFailedException(
                'AQG crawler returned invalid JSON'
                . ' | http=' . $statusCode
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($payload)
                . ' | body=' . $body,
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new ApiRequestFailedException(
                'AQG crawler response is not a JSON object'
                . ' | http=' . $statusCode
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($payload)
                . ' | body=' . $body
            );
        }

        if ($statusCode >= 400) {
            $message = 'AQG crawler HTTP ' . $statusCode;

            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message .= ': ' . $decoded['error']['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $message .= ': ' . $decoded['error'];
            }

            $message .= ' | url=' . $url;
            $message .= ' | method=' . $method;
            $message .= ' | payload=' . json_encode($payload);
            $message .= ' | body=' . $body;

            throw new ApiRequestFailedException($message);
        }

        if ((bool)($decoded['success'] ?? true) === false) {
            throw new ApiRequestFailedException(
                'AQG crawler logical error'
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($payload)
                . ' | body=' . $body
            );
        }

        return $decoded;
    }
}