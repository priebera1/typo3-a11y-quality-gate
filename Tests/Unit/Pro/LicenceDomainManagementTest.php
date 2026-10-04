<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Configuration\ProSettings;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Http\AqgApiClient;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceDomainService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteInventoryService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * The AQG service holds a licence's domain state; the installation reports its complete site inventory with every
 * licensed request and asks the service to activate or deactivate. Domains from the browser are only a request:
 * the extension forwards well-formed host names, never decides itself, and keeps no domain list of its own.
 */
final class LicenceDomainManagementTest extends TestCase
{
    #[Test]
    public function theInventoryListsEverySiteWithItsBaseAndLanguageHosts(): void
    {
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('getAllSites')->willReturn([
            'main' => new Site('main', 1, [
                'base' => 'https://www.Client.example/',
                'languages' => [
                    ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
                    ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => 'https://client.de/', 'enabled' => true],
                    // A disabled language still belongs to the site: the inventory is the configuration, not the menu.
                    ['languageId' => 2, 'title' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => 'https://client.fr/', 'enabled' => false],
                ],
            ]),
            'dev' => new Site('dev', 20, ['base' => 'https://typo314.ddev.site/']),
            'relative' => new Site('relative', 30, ['base' => '/']),
        ]);

        $inventory = (new ProSiteInventoryService($sites, new DomainNormalizer()))->collect();

        self::assertSame([
            ['site' => 'main', 'domains' => ['client.example', 'client.de', 'client.fr']],
            // Development hosts are reported too; the service decides what can be licensed.
            ['site' => 'dev', 'domains' => ['typo314.ddev.site']],
        ], $inventory);
    }

    #[Test]
    public function licensedRequestsCarryTheInventoryNextToTheReleasedIdentityFields(): void
    {
        $captured = [];
        $client = new AqgApiClient(
            $this->capturingFactory($captured, ['success' => true, 'valid' => true, 'plan' => 'pro']),
            $this->identity(),
            $this->inventory([['site' => 'main', 'domains' => ['client.example', 'client.de']]]),
        );

        $client->validate('aqg_live_key', 'client.example', '1.9.8', ['client.example']);
        self::assertSame(['client.example'], $captured['payload']['allSites']);
        self::assertSame('installation-secret', $captured['payload']['projectInstallationId']);
        self::assertSame([['site' => 'main', 'domains' => ['client.example', 'client.de']]], $captured['payload']['siteInventory']);

        $client->issueToken('aqg_live_key', 'client.example', '1.9.8', ['client.example']);
        self::assertStringEndsWith('/auth/token', $captured['url']);
        self::assertSame([['site' => 'main', 'domains' => ['client.example', 'client.de']]], $captured['payload']['siteInventory']);
    }

    #[Test]
    public function anEmptyInventoryIsLeftOutOfValidation(): void
    {
        $captured = [];
        $client = new AqgApiClient($this->capturingFactory($captured, ['success' => true, 'valid' => true]), $this->identity(), $this->inventory([]));

        $client->validate('aqg_live_key', 'client.example', '1.9.8', ['client.example']);

        self::assertArrayNotHasKey('siteInventory', $captured['payload']);
    }

    #[Test]
    public function domainManagementSendsTheVerifiedIdentityAndTheRequestedChange(): void
    {
        $captured = [];
        $client = new AqgApiClient(
            $this->capturingFactory($captured, ['success' => true, 'domainManagement' => []]),
            $this->identity(),
            $this->inventory([['site' => 'main', 'domains' => ['client.example']]]),
        );

        $client->listDomains('aqg_live_key', 'client.example', '1.9.8', ['client.example']);
        self::assertStringEndsWith('/licence/domains', $captured['url']);
        self::assertSame('installation-secret', $captured['payload']['projectInstallationId']);
        self::assertSame([['site' => 'main', 'domains' => ['client.example']]], $captured['payload']['siteInventory']);
        self::assertSame('client.example', $captured['payload']['domain']);

        $client->changeDomains('activate', 'aqg_live_key', 'client.example', '1.9.8', ['client.example'], ['shop.example']);
        self::assertStringEndsWith('/licence/domains/activate', $captured['url']);
        self::assertSame(['shop.example'], $captured['payload']['domains']);
        self::assertArrayNotHasKey('all', $captured['payload']);

        $client->changeDomains('activate', 'aqg_live_key', 'client.example', '1.9.8', ['client.example'], [], true);
        self::assertTrue($captured['payload']['all']);
        self::assertArrayNotHasKey('domains', $captured['payload']);

        // "All" is an activation shortcut only; a deactivation always names its domains.
        $client->changeDomains('deactivate', 'aqg_live_key', 'client.example', '1.9.8', ['client.example'], ['shop.example'], true);
        self::assertStringEndsWith('/licence/domains/deactivate', $captured['url']);
        self::assertSame(['shop.example'], $captured['payload']['domains']);
        self::assertArrayNotHasKey('all', $captured['payload']);
    }

    #[Test]
    public function aLicenceServiceWithoutDomainManagementIsReportedAsOutdated(): void
    {
        $captured = [];
        $client = new AqgApiClient($this->capturingFactory($captured, [
            'message' => 'Route POST:/licence/domains not found',
            'error' => 'Not Found',
            'statusCode' => 404,
        ], 404), $this->identity(), $this->inventory([]));

        $result = $this->domainService($client)->list('client.example', '1.9.8');

        self::assertFalse($result['success']);
        self::assertSame('route_not_found', $result['code']);
        self::assertStringContainsString('does not offer domain management yet', $result['message']);
    }

    #[Test]
    public function theOverviewIsTheServicesStateWithUnknownValuesDropped(): void
    {
        $client = $this->createMock(AqgApiClient::class);
        $client->method('listDomains')->willReturn([
            'success' => true,
            'plan' => 'pro',
            'domainManagement' => [
                'plan' => 'pro',
                'multiProject' => false,
                'maxDomains' => 3,
                'activeDomains' => 1,
                'remainingSlots' => 2,
                'activationLockDays' => 90,
                'counts' => ['all' => 3, 'active' => 1, 'activeNotDetected' => 0, 'available' => 1, 'unavailable' => 0],
                'domains' => [
                    ['domain' => 'client.example', 'state' => 'active', 'reason' => null, 'sites' => ['main'], 'lockedUntil' => '2026-12-30T00:00:00.000Z', 'canActivate' => false, 'canDeactivate' => false, 'deactivateBlockedBy' => 'locked', 'projects' => ['primary']],
                    ['domain' => 'shop.example', 'state' => 'available', 'reason' => 'invented', 'sites' => ['shop'], 'canActivate' => true, 'canDeactivate' => 'yes'],
                    ['domain' => 'odd.example', 'state' => 'granted', 'canActivate' => true],
                ],
            ],
        ]);
        $cache = $this->createMock(ProCacheManager::class);
        // The portal may have changed the domains: cached per-site answers and tokens are dropped.
        $cache->expects(self::once())->method('flushAll');

        $result = $this->domainService($client, $cache)->list('client.example', '1.9.8');

        self::assertTrue($result['success']);
        self::assertSame(3, $result['maxDomains']);
        self::assertSame(90, $result['activationLockDays']);
        self::assertSame(['client.example', 'shop.example'], array_column($result['domains'], 'domain'));
        self::assertSame('locked', $result['domains'][0]['deactivateBlockedBy']);
        self::assertNull($result['domains'][1]['reason']);
        self::assertFalse($result['domains'][1]['canDeactivate'], 'only a literal true allows an action');
        self::assertArrayNotHasKey('projects', $result['domains'][0], 'project ids stay on the service');
    }

    #[Test]
    public function onlyWellFormedHostNamesAreForwarded(): void
    {
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::once())->method('changeDomains')
            ->with('deactivate', 'aqg_live_key', 'client.example', '1.9.8', ['client.example'], ['client.example', 'shop.example'], false)
            ->willReturn(['success' => true, 'domainManagement' => ['domains' => []], 'results' => []]);

        $result = $this->domainService($client)->change(
            'deactivate',
            ['Client.Example', 'evil.example/path', '<script>', 42, 'client.example', ' shop.example ', 'localhost', 'https://x.example'],
            false,
            'client.example',
            '1.9.8',
        );

        self::assertTrue($result['success']);
    }

    #[Test]
    public function activateAllIsForwardedWithoutADomainList(): void
    {
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::once())->method('changeDomains')
            ->with('activate', 'aqg_live_key', 'client.example', '1.9.8', ['client.example'], [], true)
            ->willReturn(['success' => true, 'domainManagement' => ['domains' => []], 'results' => [['domain' => 'a.example', 'result' => 'activated'], ['domain' => 'b.example', 'result' => 'granted']]]);

        $result = $this->domainService($client)->change('activate', [], true, 'client.example', '1.9.8');

        self::assertSame([['domain' => 'a.example', 'result' => 'activated']], $result['results']);
    }

    #[Test]
    public function anEmptyOrUnsupportedRequestNeverReachesTheService(): void
    {
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::never())->method('changeDomains');
        $service = $this->domainService($client);

        self::assertSame('invalid_request', $service->change('activate', ['not a domain'], false, 'client.example', '1.9.8')['code']);
        // Deactivating everything at once is not a request the service accepts.
        self::assertSame('invalid_request', $service->change('deactivate', [], true, 'client.example', '1.9.8')['code']);
    }

    #[Test]
    public function withoutALicenceKeyNothingIsAsked(): void
    {
        $client = $this->createMock(AqgApiClient::class);
        $client->expects(self::never())->method('listDomains');

        $result = $this->domainService($client, null, false)->list('client.example', '1.9.8');

        self::assertSame('not_configured', $result['code']);
    }

    #[Test]
    public function refusalsBecomeTheirNextStepAndTransportDetailsStayOut(): void
    {
        $client = $this->createMock(AqgApiClient::class);
        $client->method('listDomains')->willReturnOnConsecutiveCalls(
            ['success' => false, 'error' => ['code' => 'licence_project_mismatch', 'message' => 'raw API text']],
            ['success' => false, 'error' => ['code' => 'licence_project_removed']],
            ['success' => false, 'error' => ['code' => 'trial_domain_fixed']],
            ['success' => false, 'error' => ['code' => 'activate_all_not_available']],
            ['success' => false, 'error' => ['code' => '<b>bad</b>']],
        );
        $cache = $this->createMock(ProCacheManager::class);
        $cache->expects(self::never())->method('flushAll');
        $service = $this->domainService($client, $cache);

        $mismatch = $service->list('client.example', '1.9.8');
        self::assertSame('licence_project_mismatch', $mismatch['code']);
        self::assertStringContainsString('another TYPO3 installation', $mismatch['message']);
        self::assertStringNotContainsString('raw API text', $mismatch['message']);
        self::assertStringContainsString('Restore it in the customer portal', $service->list('client.example', '1.9.8')['message']);
        self::assertStringContainsString('Choose PRO or Agency', $service->list('client.example', '1.9.8')['message']);
        self::assertStringContainsString('available with Agency', $service->list('client.example', '1.9.8')['message']);
        self::assertSame('invalid_response', $service->list('client.example', '1.9.8')['code']);

        $failing = $this->createMock(AqgApiClient::class);
        $failures = [
            new ApiRequestFailedException('AQG API request failed: cURL error 6 for https://internal.example', 0, null, 'transport_error'),
            new ApiRequestFailedException('Too many', 429, null, 'licence_rate_limited'),
        ];
        $failing->method('listDomains')->willReturnCallback(static function () use (&$failures): array {
            throw array_shift($failures);
        });
        $service = $this->domainService($failing, $cache);
        $outage = $service->list('client.example', '1.9.8');
        self::assertSame('api_unreachable', $outage['code']);
        self::assertStringNotContainsString('internal.example', $outage['message']);
        self::assertSame('licence_rate_limited', $service->list('client.example', '1.9.8')['code']);
    }

    private function domainService(AqgApiClient $client, ?ProCacheManager $cache = null, bool $configured = true): ProLicenceDomainService
    {
        $settings = $this->createMock(ProSettings::class);
        $settings->method('isConfigured')->willReturn($configured);
        $settings->method('isTrialKey')->willReturn(false);
        $settings->method('getLicenceKey')->willReturn('aqg_live_key');
        $fingerprint = $this->createMock(ProSiteFingerprintService::class);
        $fingerprint->method('collectValidationSites')->willReturn(['client.example']);

        return new ProLicenceDomainService($client, $settings, $fingerprint, $cache ?? $this->createMock(ProCacheManager::class));
    }

    private function identity(): InstallationIdentityServiceInterface
    {
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')->willReturn('installation-secret');

        return $identity;
    }

    /**
     * @param list<array{site: string, domains: list<string>}> $inventory
     */
    private function inventory(array $inventory): ProSiteInventoryService
    {
        $service = $this->createMock(ProSiteInventoryService::class);
        $service->method('collect')->willReturn($inventory);

        return $service;
    }

    /**
     * @param array<string, mixed> $captured
     * @param array<string, mixed> $responsePayload
     */
    private function capturingFactory(array &$captured, array $responsePayload, int $status = 200): RequestFactory
    {
        $factory = $this->createMock(RequestFactory::class);
        $factory->method('request')
            ->willReturnCallback(function (string $url, string $method, array $options) use (&$captured, $responsePayload, $status): ResponseInterface {
                $captured = [
                    'url' => $url,
                    'payload' => json_decode((string)($options['body'] ?? '{}'), true, 512, JSON_THROW_ON_ERROR),
                ];
                $stream = $this->createMock(StreamInterface::class);
                $stream->method('__toString')->willReturn(json_encode($responsePayload, JSON_THROW_ON_ERROR));
                $response = $this->createMock(ResponseInterface::class);
                $response->method('getStatusCode')->willReturn($status);
                $response->method('getBody')->willReturn($stream);
                $response->method('getHeaderLine')->willReturn('');

                return $response;
            });

        return $factory;
    }
}
