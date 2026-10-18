<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractBackendModuleController;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use ReflectionClass;
use ReflectionProperty;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * "Test connection" of HTTP Basic Auth answered a staging site on a private address (VPN, internal DNS, DDEV) with
 * "Select a configured site and enter username and password" although both were given (2026-10-16 audit). It says
 * that the address is not public, and it still never sends a request there.
 */
final class SettingsHttpAuthTestTest extends TestCase
{
    #[Test]
    public function aPrivateSiteAddressIsNamedInsteadOfMissingInput(): void
    {
        $requests = $this->createMock(RequestFactory::class);
        $requests->expects(self::never())->method('request');

        $response = $this->subject('https://localhost/', $requests)->testHttpAuthAction(
            (new ServerRequest('https://example.org/typo3/ajax/a11y/test-http-auth', 'POST'))
                ->withParsedBody(['rulesetSite' => 'staging', 'username' => 'stage', 'password' => 'secret'])
        );

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('private_site', json_decode((string)$response->getBody(), true)['code']);
    }

    #[Test]
    public function aSiteWithoutHostIsNotCalledPrivate(): void
    {
        $requests = $this->createMock(RequestFactory::class);
        $requests->expects(self::never())->method('request');

        $response = $this->subject('/', $requests)->testHttpAuthAction(
            (new ServerRequest('https://example.org/typo3/ajax/a11y/test-http-auth', 'POST'))
                ->withParsedBody(['rulesetSite' => 'staging', 'username' => 'stage', 'password' => 'secret'])
        );

        self::assertArrayNotHasKey('code', json_decode((string)$response->getBody(), true));
    }

    #[Test]
    public function missingCredentialsAreStillReportedAsMissing(): void
    {
        $requests = $this->createMock(RequestFactory::class);
        $requests->expects(self::never())->method('request');

        $response = $this->subject('https://localhost/', $requests)->testHttpAuthAction(
            (new ServerRequest('https://example.org/typo3/ajax/a11y/test-http-auth', 'POST'))
                ->withParsedBody(['rulesetSite' => 'staging', 'username' => '', 'password' => 'secret'])
        );

        self::assertArrayNotHasKey('code', json_decode((string)$response->getBody(), true));
    }

    private function subject(string $base, RequestFactory $requests): SettingsController
    {
        $backendContext = $this->createMock(BackendContextService::class);
        $backendContext->method('translate')->willReturnCallback(static fn (string $key): string => $key);
        $access = $this->createMock(AccessControlService::class);
        $access->method('canManageAdminOnlySettings')->willReturn(true);
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('staging');
        $site->method('getBase')->willReturn(new Uri($base));
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByIdentifier')->willReturn($site);
        $status = $this->createMock(ProStatusResolverService::class);
        $status->method('resolveForSiteIdentifier')->willReturn(new ProStatusViewModel(
            configured: true, valid: true, proAvailable: true, plan: 'pro', features: ['crawler'], reason: null, reasonLabel: null,
            statusLabel: '', showProHints: false, hasCrawler: true, hasExportPdf: true, hasMultiSite: false, hasProRules: true,
        ));
        $cacheManager = $this->createMock(CacheManager::class);
        $cacheManager->method('getCache')->willThrowException(new \RuntimeException('no cache in unit tests'));

        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        foreach ([
            [AbstractBackendModuleController::class, 'backendContextService', $backendContext],
            [AbstractBackendModuleController::class, 'siteResolutionService', $sites],
            [SettingsController::class, 'accessControlService', $access],
            [SettingsController::class, 'proStatusResolverService', $status],
            [SettingsController::class, 'cacheManager', $cacheManager],
            [SettingsController::class, 'requestFactory', $requests],
        ] as [$class, $property, $value]) {
            (new ReflectionProperty($class, $property))->setValue($subject, $value);
        }

        return $subject;
    }
}
