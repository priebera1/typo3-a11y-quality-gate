<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractBackendModuleController;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Pro\Cache\ProCacheManager;
use Priebera\A11yQualityGate\Pro\Dto\LicenceValidationResult;
use Priebera\A11yQualityGate\Pro\Service\ProLicenceService;
use Priebera\A11yQualityGate\Pro\Service\ProSiteFingerprintService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use ReflectionClass;
use ReflectionProperty;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Revalidate is a definitive check of the saved key. A definitive answer — valid or rejected — replaces every
 * cached licence state and token; an outage keeps the last known good state.
 */
final class SettingsRevalidateCacheTest extends TestCase
{
    /**
     * @return iterable<string, array{0:LicenceValidationResult, 1:bool}>
     */
    public static function answerProvider(): iterable
    {
        yield 'valid' => [new LicenceValidationResult(valid: true, plan: 'pro'), true];
        yield 'expired' => [LicenceValidationResult::invalid('expired'), true];
        yield 'revoked' => [LicenceValidationResult::invalid('inactive'), true];
        yield 'another project' => [LicenceValidationResult::invalid('project_mismatch'), true];
        yield 'service unreachable' => [LicenceValidationResult::invalid('api_unreachable'), false];
        yield 'rate limited' => [LicenceValidationResult::invalid('rate_limited'), false];
    }

    #[DataProvider('answerProvider')]
    #[Test]
    public function aDefinitiveAnswerReplacesCachedStateAndAnOutageKeepsIt(LicenceValidationResult $answer, bool $flushExpected): void
    {
        $cache = $this->createMock(ProCacheManager::class);
        $cache->expects($flushExpected ? self::once() : self::never())->method('flushAll');

        $response = $this->subject($answer, $cache)->validateLicenceAction(
            (new ServerRequest('https://example.org/typo3/ajax/a11y/validate-licence', 'POST'))
                ->withParsedBody(['licenceKey' => 'aqg_live_saved-key'])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    private function subject(LicenceValidationResult $answer, ProCacheManager $cache): SettingsController
    {
        $backendContext = $this->createMock(BackendContextService::class);
        $backendContext->method('translate')->willReturnCallback(static fn (string $key): string => $key);
        $access = $this->createMock(AccessControlService::class);
        $access->method('canShowSettings')->willReturn(true);
        $access->method('canManageAdminOnlySettings')->willReturn(true);
        $parameters = $this->createMock(RequestParameterService::class);
        $parameters->method('getPageUidOrZero')->willReturn(0);
        $site = $this->createMock(Site::class);
        $site->method('getIdentifier')->willReturn('main');
        $site->method('getBase')->willReturn(new Uri('https://example.org/'));
        $site->method('getLanguages')->willReturn([]);
        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteForBackendRequest')->willReturn($site);
        $siteFinder = $this->createMock(SiteFinder::class);
        $siteFinder->method('getAllSites')->willReturn(['main' => $site]);
        $context = $this->createMock(ExtensionContextService::class);
        $context->method('getNormalizedDomainFromSiteBase')->willReturn('example.org');
        $context->method('getExtensionVersion')->willReturn('1.9.6');
        $fingerprint = $this->createMock(ProSiteFingerprintService::class);
        $fingerprint->method('collectValidationSites')->willReturn(['main']);
        $licence = $this->createMock(ProLicenceService::class);
        $licence->method('validateKeyDirect')->willReturn($answer);

        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        foreach ([
            [AbstractBackendModuleController::class, 'backendContextService', $backendContext],
            [AbstractBackendModuleController::class, 'requestParameterService', $parameters],
            [AbstractBackendModuleController::class, 'siteResolutionService', $sites],
            [SettingsController::class, 'accessControlService', $access],
            [SettingsController::class, 'siteFinder', $siteFinder],
            [SettingsController::class, 'extensionContextService', $context],
            [SettingsController::class, 'proSiteFingerprintService', $fingerprint],
            [SettingsController::class, 'proLicenceService', $licence],
            [SettingsController::class, 'proCacheManager', $cache],
        ] as [$class, $property, $value]) {
            (new ReflectionProperty($class, $property))->setValue($subject, $value);
        }

        return $subject;
    }
}
