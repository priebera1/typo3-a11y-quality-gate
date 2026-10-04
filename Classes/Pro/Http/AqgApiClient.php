<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Http;

use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Configuration\ProConstants;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Dto\AccessTokenResponseDto;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResponseDto;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Service\ProSiteInventoryService;
use Psr\Http\Client\ClientExceptionInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AqgApiClient
{
    public function __construct(
        private readonly RequestFactory $requestFactory,
        private readonly ?InstallationIdentityServiceInterface $installationIdentityService = null,
        private readonly ?ProSiteInventoryService $siteInventoryService = null,
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
        $payload = $this->postJson('/licence/validate', $this->withSiteInventory($this->withProjectInstallation([
            'key' => $licenceKey,
            'domain' => $domain,
            'version' => $version,
            'productSlug' => ProConstants::PRODUCT_SLUG,
            'allSites' => $this->normalizeAllSites($allSites),
        ])));

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
        $payload = $this->postJson('/auth/token', $this->withSiteInventory($this->withProjectInstallation([
            'key' => $licenceKey,
            'domain' => $domain,
            'version' => $version,
            'productSlug' => ProConstants::PRODUCT_SLUG,
            'allSites' => $this->normalizeAllSites($allSites),
        ])));

        return AccessTokenResponseDto::fromArray($payload);
    }

    /**
     * The licence's domains as the verified installation sees them: activated, reported but not activated,
     * activated but no longer reported, and unavailable under the plan. The answer is the AQG service's state —
     * the same the customer portal shows — never a local list.
     *
     * @param list<string> $allSites
     * @return array<string, mixed>
     */
    public function listDomains(string $licenceKey, string $domain, string $version, array $allSites): array
    {
        return $this->postJson('/licence/domains', $this->domainManagementPayload($licenceKey, $domain, $version, $allSites));
    }

    /**
     * Activates or deactivates domains. The service only activates hosts this installation reports in its
     * `siteInventory` and enforces the plan's limit and locks; the domains named here are a request, not a grant.
     *
     * @param list<string> $allSites
     * @param list<string> $domains
     * @return array<string, mixed>
     */
    public function changeDomains(
        string $action,
        string $licenceKey,
        string $domain,
        string $version,
        array $allSites,
        array $domains,
        bool $all = false,
    ): array {
        $path = $action === 'deactivate' ? '/licence/domains/deactivate' : '/licence/domains/activate';
        $payload = $this->domainManagementPayload($licenceKey, $domain, $version, $allSites);
        if ($all && $action !== 'deactivate') {
            $payload['all'] = true;
        } else {
            $payload['domains'] = array_values($domains);
        }

        return $this->postJson($path, $payload);
    }

    /**
     * @param list<string> $allSites
     * @return array<string, mixed>
     */
    private function domainManagementPayload(string $licenceKey, string $domain, string $version, array $allSites): array
    {
        $payload = $this->withProjectInstallation([
            'key' => $licenceKey,
            'version' => $version,
            'productSlug' => ProConstants::PRODUCT_SLUG,
            'allSites' => $this->normalizeAllSites($allSites),
        ]);
        if ($domain !== '') {
            $payload['domain'] = $domain;
        }
        $payload['siteInventory'] = $this->siteInventoryService?->collect() ?? [];

        return $payload;
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
     * Paid requests name the installation, so an Agency licence binds each client project to its TYPO3
     * installation instead of to its public site list. Not `installationId`: on /auth/token that field asks
     * for the Free fallback, which a paid request must never silently receive.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withProjectInstallation(array $payload): array
    {
        $installationId = trim((string)$this->installationIdentityService?->getOrCreateInstallationId());
        if ($installationId !== '') {
            $payload['projectInstallationId'] = $installationId;
        }

        return $payload;
    }

    /**
     * 1.9.8+: every configured site with all its hosts. The AQG service issues tokens and activates domains only
     * for hosts the installation reports; older services ignore the field.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function withSiteInventory(array $payload): array
    {
        $inventory = $this->siteInventoryService?->collect() ?? [];
        if ($inventory !== []) {
            $payload['siteInventory'] = $inventory;
        }

        return $payload;
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
                    'body' => json_encode($payload, JSON_THROW_ON_ERROR),
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
            $decoded = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
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
            if (str_starts_with($path, '/licence/domains')) {
                // A licence service older than 1.9.8 has no domain management: the Licence tab says so instead of
                // reporting a generic failure. Other endpoints keep reading a bare 404 as an unreachable service.
                $error = ['code' => $errorCode, 'message' => 'Route not found.', 'status' => 404];
                $decoded['error'] = $error;
            }
        }
        if ($errorCode === '' && $statusCode === 429 && isset($payload['key'])) {
            // The API's per-route limiter answers without AQG's error body. It is still a rate limit — the
            // licence was not checked — not an unreachable service.
            $errorCode = 'licence_rate_limited';
            $error = ['code' => $errorCode, 'message' => 'Too many licence requests.', 'status' => 429];
            $decoded['error'] = $error;
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
