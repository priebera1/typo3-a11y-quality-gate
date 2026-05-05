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
    private function postJson(string $path, array $payload): array
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
        } catch (ClientExceptionInterface|\JsonException $exception) {
            throw new ApiRequestFailedException(
                'AQG API request failed: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        $body = (string)$response->getBody();
        if ($body === '') {
            throw new ApiRequestFailedException('AQG API returned an empty response body.');
        }

        try {
            $decoded = Utils::jsonDecode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            throw new ApiRequestFailedException(
                'AQG API returned invalid JSON: ' . $exception->getMessage(),
                0,
                $exception
            );
        }

        if (!is_array($decoded)) {
            throw new ApiRequestFailedException('AQG API response is not a JSON object.');
        }

        return $decoded;
    }
}