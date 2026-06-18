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
use Priebera\A11yQualityGate\Utility\StringListUtility;
use Psr\Http\Client\ClientExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

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
        string $scannerPreviewToken = '',
        string $httpAuthUser = '',
        string $httpAuthPass = '',
        array $excludedPatterns = [],
        array $priorityUrls = [],
        array $cookieSelectors = [],
        ?int $languageId = null,
        string $languageCode = '',
    ): CrawlerSubmitResponseDto {
        $requestPayload = [
            'siteId' => $siteId,
            'sourceType' => $sourceType->value,
            'startUrl' => $startUrl,
            'sitemapUrl' => $sitemapUrl,
            'maxPages' => $maxPages,
            'followLinks' => $followLinks,
            'axeLocale' => $axeLocale,
            'captureScreenshot' => $captureScreenshot,
            'cookieDismiss' => $cookieDismiss,
        ];

        if (is_string($sitemapUrl) && trim($sitemapUrl) !== '') {
            $requestPayload['sitemapUrl'] = trim($sitemapUrl);
        }

        if ($scannerPreviewToken !== '') {
            $requestPayload['scannerToken'] = $scannerPreviewToken;
        }

        if ($httpAuthUser !== '' && $httpAuthPass !== '') {
            $requestPayload['httpAuth'] = [
                'username' => $httpAuthUser,
                'password' => $httpAuthPass,
            ];
        }

        $excludedPatterns = StringListUtility::normalize($excludedPatterns);
        if ($excludedPatterns !== []) {
            $requestPayload['excludedPatterns'] = $excludedPatterns;
        }

        $priorityUrls = StringListUtility::normalize($priorityUrls);
        if ($priorityUrls !== []) {
            $requestPayload['priorityUrls'] = $priorityUrls;
        }

        $cookieSelectors = StringListUtility::normalize($cookieSelectors);
        if ($cookieSelectors !== []) {
            $requestPayload['cookieSelectors'] = $cookieSelectors;
        }

        if ($languageId !== null) {
            $requestPayload['languageId'] = $languageId;
        }

        $languageCode = trim($languageCode);
        if ($languageCode !== '') {
            $requestPayload['languageCode'] = $languageCode;
        }

        $payload = $this->requestJson(
            '/crawl/submit',
            'POST',
            $accessToken,
            $requestPayload
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
    public function history(
        string $accessToken,
        string $siteId,
        int $limit = 10,
        string $sourceType = '',
        string $startUrl = '',
        string $status = ''
    ): array {
        $queryParameters = [
            'siteId' => $siteId,
            'limit' => max(1, min(100, $limit)),
        ];

        if (trim($sourceType) !== '') {
            $queryParameters['sourceType'] = trim($sourceType);
        }

        if (trim($startUrl) !== '') {
            $queryParameters['startUrl'] = trim($startUrl);
        }

        if (trim($status) !== '') {
            $queryParameters['status'] = trim($status);
        }

        $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/history?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function latest(string $accessToken, string $siteId): array
    {
        $query = http_build_query([
            'siteId' => $siteId,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/latest?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function compare(string $accessToken, string $fromJobId, string $toJobId): array
    {
        $query = http_build_query([
            'fromJobId' => $fromJobId,
            'toJobId' => $toJobId,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/compare?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function progressSummary(string $accessToken, string $siteId): array
    {
        $query = http_build_query([
            'siteId' => $siteId,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/progress-summary?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function regressionAlert(
        string $accessToken,
        string $siteId,
        string $sourceType,
        string $startUrl = '',
        string $language = 'en'
    ): array {
        $queryParameters = [
            'siteId' => $siteId,
            'sourceType' => $sourceType,
            'language' => $language,
        ];

        if (trim($startUrl) !== '') {
            $queryParameters['startUrl'] = trim($startUrl);
        }

        $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/regression-alert?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function remediationPlan(string $accessToken, string $jobId): array
    {
        return $this->requestJson(
            '/crawl/remediation-plan/' . rawurlencode($jobId),
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function latestRemediationPlan(
        string $accessToken,
        string $siteId,
        string $sourceType,
        ?string $startUrl = null
    ): array {
        $queryParameters = [
            'siteId' => $siteId,
            'sourceType' => $sourceType,
        ];

        if ($startUrl !== null && trim($startUrl) !== '') {
            $queryParameters['startUrl'] = trim($startUrl);
        }

        $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/remediation-plan/latest?' . $query,
            'GET',
            $accessToken
        );
    }


    /**
     * @return array<string, mixed>
     */
    public function accessibilityStatement(string $accessToken, string $jobId, string $language = 'en'): array
    {
        $query = http_build_query([
            'language' => $language,
        ], '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/accessibility-statement/' . rawurlencode($jobId) . '?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function latestAccessibilityStatement(
        string $accessToken,
        string $siteId,
        string $sourceType,
        string $startUrl = '',
        string $language = 'en'
    ): array {
        $queryParameters = [
            'siteId' => $siteId,
            'sourceType' => $sourceType,
            'language' => $language,
        ];

        if (trim($startUrl) !== '') {
            $queryParameters['startUrl'] = trim($startUrl);
        }

        $query = http_build_query($queryParameters, '', '&', PHP_QUERY_RFC3986);

        return $this->requestJson(
            '/crawl/accessibility-statement/latest?' . $query,
            'GET',
            $accessToken
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(string $accessToken, string $jobId): array
    {
        return $this->requestJson(
            '/crawl/cancel/' . rawurlencode($jobId),
            'POST',
            $accessToken,
            ['jobId' => $jobId]
        );
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

        $this->logCrawlerRequest('outbound', [
            'url' => $url,
            'method' => $method,
            'payloadKeys' => array_keys($payload),
            'payload' => $this->sanitizePayloadForLog($payload),
            'authMode' => $accessToken !== '' ? 'bearer-header' : 'none',
            'authorizationHeaderPresent' => $accessToken !== '',
            'authorizationHeaderType' => $accessToken !== '' ? 'Bearer' : '',
            'accessTokenPresent' => $accessToken !== '',
            'accessTokenLength' => strlen($accessToken),
            'contentType' => (string)($options['headers']['Content-Type'] ?? ''),
            'licenseKeyPresent' => false,
            'scannerTokenPresent' => isset($payload['scannerToken']) && trim((string)$payload['scannerToken']) !== '',
        ]);

        try {
            $response = $this->requestFactory->request($url, $method, $options);
        } catch (ClientExceptionInterface | \JsonException $exception) {
            throw new ApiRequestFailedException(
                'AQG crawler request failed: '
                . $exception->getMessage()
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($this->sanitizePayloadForLog($payload)),
                0,
                $exception
            );
        }

        $statusCode = $response->getStatusCode();
        $body = trim((string)$response->getBody());

        $this->logCrawlerRequest($statusCode >= 400 ? 'response-error' : 'response', [
            'url' => $url,
            'method' => $method,
            'status' => $statusCode,
            'payloadKeys' => array_keys($payload),
            'authorizationHeaderPresent' => $accessToken !== '',
            'authorizationHeaderType' => $accessToken !== '' ? 'Bearer' : '',
            'accessTokenPresent' => $accessToken !== '',
            'accessTokenLength' => strlen($accessToken),
            'licenseKeyPresent' => false,
            'scannerTokenPresent' => isset($payload['scannerToken']) && trim((string)$payload['scannerToken']) !== '',
            'body' => $this->sanitizeBodyForLog($body),
        ], $statusCode >= 400 ? 'warning' : 'debug');

        if ($body === '') {
            throw new ApiRequestFailedException(
                'AQG crawler returned empty response body'
                . ' | http=' . $statusCode
                . ' | url=' . $url
                . ' | method=' . $method
                . ' | payload=' . json_encode($this->sanitizePayloadForLog($payload))
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
                . ' | payload=' . json_encode($this->sanitizePayloadForLog($payload)),
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
                . ' | payload=' . json_encode($this->sanitizePayloadForLog($payload))
            );
        }

        if ($statusCode >= 400) {
            $message = 'AQG crawler HTTP ' . $statusCode;

            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message .= ': ' . $decoded['error']['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $message .= ': ' . $decoded['error'];
            }

            if (isset($decoded['error']['code']) && is_string($decoded['error']['code'])) {
                $message .= ' | code=' . $decoded['error']['code'];
            }

            $message .= ' | url=' . $url;
            $message .= ' | method=' . $method;
            $message .= ' | payload=' . json_encode($this->sanitizePayloadForLog($payload));
            $message .= ' | auth=' . json_encode($this->buildAuthDebug($accessToken, $payload));
            $message .= ' | body=' . $this->sanitizeBodyForLog($body);

            throw new ApiRequestFailedException($message);
        }

        if ((bool)($decoded['success'] ?? true) === false) {
            $message = 'AQG crawler logical error';
            if (isset($decoded['error']['message']) && is_string($decoded['error']['message'])) {
                $message .= ': ' . $decoded['error']['message'];
            } elseif (isset($decoded['error']) && is_string($decoded['error'])) {
                $message .= ': ' . $decoded['error'];
            }

            $message .= ' | url=' . $url;
            $message .= ' | method=' . $method;
            $message .= ' | payload=' . json_encode($this->sanitizePayloadForLog($payload));
            $message .= ' | auth=' . json_encode($this->buildAuthDebug($accessToken, $payload));
            $message .= ' | body=' . $this->sanitizeBodyForLog($body);

            throw new ApiRequestFailedException($message);
        }

        return $decoded;
    }



    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function buildAuthDebug(string $accessToken, array $payload): array
    {
        return [
            'authMode' => $accessToken !== '' ? 'bearer-header' : 'none',
            'authorizationHeaderPresent' => $accessToken !== '',
            'authorizationHeaderType' => $accessToken !== '' ? 'Bearer' : '',
            'accessTokenPresent' => $accessToken !== '',
            'accessTokenLength' => strlen($accessToken),
            'licenseKeyPresent' => false,
            'scannerTokenPresent' => isset($payload['scannerToken']) && trim((string)$payload['scannerToken']) !== '',
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logCrawlerRequest(string $event, array $context, string $level = 'debug'): void
    {
        try {
            $logger = GeneralUtility::makeInstance(LogManager::class)->getLogger(__CLASS__);
            if ($level === 'warning') {
                $logger->warning('AQG crawler client ' . $event, $context);
                return;
            }
            $logger->debug('AQG crawler client ' . $event, $context);
        } catch (\Throwable) {
        }
    }

    private function sanitizeBodyForLog(string $body): string
    {
        if ($body === '') {
            return '';
        }

        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            return json_encode($this->sanitizePayloadForLog($decoded), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }

        return substr($body, 0, 2000);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function sanitizePayloadForLog(array $payload): array
    {
        if (isset($payload['scannerToken']) && $payload['scannerToken'] !== '') {
            $payload['scannerToken'] = '***';
        }

        if (isset($payload['scannerPreviewToken']) && $payload['scannerPreviewToken'] !== '') {
            $payload['scannerPreviewToken'] = '***';
        }

        if (isset($payload['httpAuth']) && is_array($payload['httpAuth'])) {
            if (isset($payload['httpAuth']['password']) && $payload['httpAuth']['password'] !== '') {
                $payload['httpAuth']['password'] = '***';
            }
        }

        return $payload;
    }
}
