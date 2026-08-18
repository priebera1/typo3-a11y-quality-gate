<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Http;

use GuzzleHttp\Utils;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Psr\Http\Client\ClientExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AqgApiClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
    ) {
    }

    /**
     * @param list<string> $allSites
     */
    public function validate(
        string $licenceKey,
        string $domain,
        string $version,
        array $allSites = [],
    ): LicenceValidationResponseDto {
        $payload = $this->postJson('/licence/validate', [
            'key' => $licenceKey,
            'domain' => $domain,
            'version' => $version,
            'productSlug' => ProConstants::PRODUCT_SLUG,
            'allSites' => $this->normalizeAllSites($allSites),
        ]);

        return LicenceValidationResponseDto::fromArray($payload);
    }

    /**
     * @param list<string> $allSites
     */
    public function issueToken(
        string $licenceKey,
        string $domain,
        string $version,
        array $allSites = [],
    ): AccessTokenResponseDto {
        $payload = $this->postJson('/auth/token', [
            'key' => $licenceKey,
            'domain' => $domain,
            'version' => $version,
            'productSlug' => ProConstants::PRODUCT_SLUG,
            'allSites' => $this->normalizeAllSites($allSites),
        ]);

        return AccessTokenResponseDto::fromArray($payload);
    }

    public function issueFreeToken(
        string $installationId,
        string $siteUrl,
        string $siteIdentifier,
        string $version,
    ): AccessTokenResponseDto {
        $payload = $this->postJson('/auth/token', [
            'installationId' => $installationId,
            'siteUrl' => $siteUrl,
            'siteIdentifier' => $siteIdentifier,
            'version' => $version,
        ], true);

        return AccessTokenResponseDto::fromArray($payload);
    }

    /**
     * @param list<string> $allSites
     * @return list<string>
     */
    private function normalizeAllSites(array $allSites): array
    {
        $normalized = array_values(array_filter(array_map(
            static fn (mixed $site): string => trim((string)$site),
            $allSites
        ), static fn (string $site): bool => $site !== ''));

        $normalized = array_values(array_unique($normalized));
        sort($normalized);

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private function postJson(string $path, array $payload, bool $throwOnHttpError = false): array
    {
        $url = rtrim(ProSettings::resolveApiBaseUrl(), '/') . $path;

        try {
            $response = $this->requestFactory->request(
                $url,
                'POST',
                [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                    'body' => Utils::jsonEncode($payload, JSON_THROW_ON_ERROR),
                    'timeout' => ProConstants::REQUEST_TIMEOUT,
                    'http_errors' => false,
                    'allow_redirects' => false,
                ]
            );
        } catch (ClientExceptionInterface | \JsonException $exception) {
            $this->logRequestFailure($path, 0, 'transport_error');
            throw new ApiRequestFailedException(
                'AQG API request failed.',
                0,
                $exception,
                'transport_error',
            );
        }

        $statusCode = $response->getStatusCode();
        $body = (string)$response->getBody();
        if ($body === '') {
            $this->logRequestFailure($path, $statusCode, 'empty_response');
            throw new ApiRequestFailedException(
                'AQG API returned an empty response body.',
                $statusCode,
                null,
                'empty_response',
            );
        }

        try {
            $decoded = Utils::jsonDecode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            $this->logRequestFailure($path, $statusCode, 'invalid_json');
            throw new ApiRequestFailedException(
                'AQG API returned invalid JSON.',
                $statusCode,
                $exception,
                'invalid_json',
            );
        }

        if (!is_array($decoded)) {
            $this->logRequestFailure($path, $statusCode, 'invalid_response');
            throw new ApiRequestFailedException(
                'AQG API response is not a JSON object.',
                $statusCode,
                null,
                'invalid_response',
            );
        }

        $error = is_array($decoded['error'] ?? null) ? $decoded['error'] : [];
        $errorCode = trim((string)($error['code'] ?? ''));
        if ($errorCode === '' && $statusCode === 404) {
            $errorCode = 'route_not_found';
        }

        if ($statusCode >= 400) {
            $this->logRequestFailure($path, $statusCode, $errorCode !== '' ? $errorCode : 'http_error');
            if ($throwOnHttpError) {
                throw new ApiRequestFailedException(
                    'AQG API rejected the request.',
                    $statusCode,
                    null,
                    $errorCode,
                    is_array($error['details'] ?? null) ? $error['details'] : [],
                );
            }
        }

        return $decoded;
    }

    private function logRequestFailure(string $endpoint, int $statusCode, string $errorCode): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(__CLASS__)
                ->warning('AQG API client request failed', [
                    'endpoint' => $endpoint,
                    'httpStatus' => $statusCode,
                    'errorCode' => $errorCode,
                ]);
        } catch (\Throwable) {
        }
    }
}
