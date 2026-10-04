<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\FreePreview\FreeRemotePreviewService;
use Priebera\A11yQualityGate\FreePreview\FreeSubmitIntentService;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerStatusResult;
use Priebera\A11yQualityGate\Pro\Dto\CrawlerSubmitResult;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanSubmissionTracker;
use Priebera\A11yQualityGate\Pro\Enum\CrawlerJobStatus;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanErrorPresenter;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Pro\ViewModel\ProStatusViewModel;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\DateTimeService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\FixVerificationService;
use Priebera\A11yQualityGate\Service\FrontendPageUrlService;
use Priebera\A11yQualityGate\Service\RemotePageScanTargetResolver;
use Priebera\A11yQualityGate\Service\RemoteScanResponseService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\ScopeAccessService;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Locking\LockFactory;
use TYPO3\CMS\Core\Locking\LockingStrategyInterface;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A paid single-page scan sends the site's HTTP credentials and scanner token to the crawler, so the
 * target must follow from the page the user may edit. A site identifier, URL or stored page named by the
 * browser can only agree with it. A running scan is followed through the channel that started it (Free or
 * paid, as stored on the scan), never through whatever the licence says while it runs.
 */
final class ProCrawlerSubmitBindingTest extends TestCase
{
    private const JOB = '44444444-4444-4444-8444-444444444444';

    private ProCrawlerService $proCrawlerService;
    private ScopeAccessService $scopeAccessService;
    private FrontendPageUrlService $frontendPageUrlService;
    private RemoteScanRepository $remoteScanRepository;
    private RemotePageScanTargetResolver $targetResolver;
    private AccessControlService $accessControlService;
    private FixVerificationService $fixVerificationService;
    private bool $licenceIsTrial = false;
    private bool $licenceIsPaid = true;
    private FreeRemotePreviewService $freeRemotePreviewService;
    private Site $siteA;
    private Site $siteB;
    private ?RemoteScanSubmissionTracker $submissionTracker = null;
    private ?RemoteScanRecoveryService $recoveryService = null;
    /** @var array<string, mixed> */
    private array $registryEntries = [];

    protected function setUp(): void
    {
        $this->proCrawlerService = $this->createMock(ProCrawlerService::class);
        $this->scopeAccessService = $this->createMock(ScopeAccessService::class);
        $this->frontendPageUrlService = $this->createMock(FrontendPageUrlService::class);
        $this->remoteScanRepository = $this->createMock(RemoteScanRepository::class);
        $this->targetResolver = $this->createMock(RemotePageScanTargetResolver::class);
        $this->accessControlService = $this->createMock(AccessControlService::class);
        $this->fixVerificationService = $this->createMock(FixVerificationService::class);
        $this->freeRemotePreviewService = $this->createMock(FreeRemotePreviewService::class);
        $this->siteA = new Site('client-a', 1, ['base' => 'https://a.example/']);
        $this->siteB = new Site('client-b', 100, ['base' => 'https://b.example/']);

        $lock = $this->createMock(LockingStrategyInterface::class);
        $lock->method('acquire')->willReturn(true);
        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory->method('createLocker')->willReturn($lock);
        GeneralUtility::setSingletonInstance(LockFactory::class, $lockFactory);
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    #[Test]
    public function aSiteIdentifierOfAnotherSiteIsRejected(): void
    {
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->with(10)->willReturn(true);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->submitPageAction($this->request([
            'pageUid' => 10,
            'siteIdentifier' => 'client-b',
        ]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('site_page_mismatch', $this->decode($response)['code']);
    }

    #[Test]
    public function aBrowserSuppliedUrlIsIgnoredAndThePageUrlIsResolvedServerSide(): void
    {
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->with(10)->willReturn(true);
        $this->frontendPageUrlService->method('resolveForPage')->with($this->siteA, 10, 0)->willReturn('https://a.example/about');
        $this->proCrawlerService->expects(self::once())->method('submit')
            ->with(
                self::anything(),
                self::anything(),
                'client-a',
                'https://a.example/about',
            )
            ->willReturn(new CrawlerSubmitResult('11111111-1111-4111-8111-111111111111', 'queued', 'single_page', null));

        $response = $this->controller()->submitPageAction($this->request([
            'pageUid' => 10,
            'siteIdentifier' => 'client-a',
            'pageUrl' => 'https://b.example/private',
        ]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('https://a.example/about', $this->decode($response)['startUrl']);
    }

    #[Test]
    public function aPageTheUserCannotEditIsRefused(): void
    {
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->willReturn(false);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10]));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function aStoredPageOfAnotherSiteCannotBeRescannedThroughAnEditablePage(): void
    {
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->willReturn(true);
        $this->remoteScanRepository->method('findPageByUid')->with(900)->willReturn(['uid' => 900, 'remote_scan' => 90, 'url' => 'https://b.example/private']);
        $this->remoteScanRepository->method('findScanByUid')->with(90)->willReturn(['uid' => 90, 'site_identifier' => 'client-b', 'language_uid' => 0]);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10, 'remotePageUid' => 900]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame('site_page_mismatch', $this->decode($response)['code']);
    }

    #[Test]
    public function aStoredUrlBoundToAnotherPageOfTheSiteIsRefused(): void
    {
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->willReturn(true);
        $this->remoteScanRepository->method('findPageByUid')->willReturn(['uid' => 900, 'remote_scan' => 90, 'url' => 'https://a.example/board-only']);
        $this->remoteScanRepository->method('findScanByUid')->willReturn(['uid' => 90, 'site_identifier' => 'client-a', 'language_uid' => 0]);
        // The URL belongs to page 55 (or to the site root); page 10 cannot claim it.
        $this->targetResolver->method('resolveScanPageUid')->willReturn(55);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10, 'remotePageUid' => 900]));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function singlePageScansNeedScanNowButNotScanAll(): void
    {
        $this->accessControlService->method('canShowScanNow')->willReturn(true);
        $this->accessControlService->method('canShowScanAll')->willReturn(false);
        $this->scopeAccessService->method('canEditPage')->willReturn(true);
        $this->frontendPageUrlService->method('resolveForPage')->willReturn('https://a.example/about');
        $this->proCrawlerService->method('submit')
            ->willReturn(new CrawlerSubmitResult('11111111-1111-4111-8111-111111111111', 'queued', 'single_page', null));

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10]));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function singlePageScansAreRefusedWithoutScanNow(): void
    {
        $this->accessControlService->method('canShowScanNow')->willReturn(false);
        $this->accessControlService->method('canShowScanAll')->willReturn(true);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10]));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function verifyFixOfAFindingTheUserCannotReadIsNotFound(): void
    {
        $this->allowScanNow();
        $this->fixVerificationService->method('resolveFinding')->willReturn(null);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->verifyFixAction($this->request(['findingId' => 77]));

        self::assertSame(404, $response->getStatusCode());
    }

    #[Test]
    public function verifyFixNeedsEditAccessToThePageTheUrlBelongsTo(): void
    {
        $this->allowScanNow();
        $this->fixVerificationService->method('resolveFinding')->willReturn($this->finding());
        $this->targetResolver->method('resolveScanPageUid')->willReturn(55);
        $this->scopeAccessService->method('canEditPage')->with(55)->willReturn(false);
        $this->proCrawlerService->expects(self::never())->method('submit');

        $response = $this->controller()->verifyFixAction($this->request(['findingId' => 77]));

        self::assertSame(403, $response->getStatusCode());
    }

    #[Test]
    public function verifyFixIsAProOrAgencyFeature(): void
    {
        $this->allowScanNow();
        $this->licenceIsTrial = true;
        $this->fixVerificationService->method('resolveFinding')->willReturn($this->finding());
        $this->targetResolver->method('resolveScanPageUid')->willReturn(55);
        $this->scopeAccessService->method('canEditPage')->willReturn(true);
        $this->proCrawlerService->expects(self::never())->method('submit');
        $this->fixVerificationService->expects(self::never())->method('recordRequest');

        $response = $this->controller()->verifyFixAction($this->request(['findingId' => 77]));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('pro_plan_required', $this->decode($response)['code']);
    }

    #[Test]
    public function verifyFixScansTheStoredUrlAndRecordsTheVerification(): void
    {
        $this->allowScanNow();
        $this->fixVerificationService->method('resolveFinding')->willReturn($this->finding());
        $this->targetResolver->method('resolveScanPageUid')->willReturn(55);
        $this->scopeAccessService->method('canEditPage')->willReturn(true);
        $this->proCrawlerService->expects(self::once())->method('submit')
            ->with(self::anything(), self::anything(), 'client-a', 'https://a.example/board')
            ->willReturn(new CrawlerSubmitResult('22222222-2222-4222-8222-222222222222', 'queued', 'single_page', null));
        $this->fixVerificationService->expects(self::once())->method('recordRequest')
            ->with(self::anything(), 55, '22222222-2222-4222-8222-222222222222', 3)
            ->willReturn(9);

        $response = $this->controller()->verifyFixAction($this->request(['findingId' => 77]));

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(9, $this->decode($response)['verificationUid']);
    }

    /** @return array{issue:array<string, mixed>,page:array<string, mixed>,scan:array<string, mixed>,occurrences:int} */
    #[Test]
    public function aFreeScanIsFollowedThroughTheFreeChannelAfterAnUpgrade(): void
    {
        $this->storedScan(isFreePreview: true);
        $this->freeRemotePreviewService->expects(self::once())->method('getStatus')->willReturn($this->runningStatus());
        $this->proCrawlerService->expects(self::never())->method('getStatus');

        $response = $this->controller()->statusAction($this->pollRequest());

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function aPaidScanIsNeverPolledThroughTheFreeChannelWhenTheLicenceLapses(): void
    {
        $this->licenceIsPaid = false;
        $this->storedScan(isFreePreview: false);
        $this->proCrawlerService->expects(self::once())->method('getStatus')->willReturn($this->runningStatus());
        $this->freeRemotePreviewService->expects(self::never())->method('getStatus');
        $this->freeRemotePreviewService->expects(self::never())->method('getSummary');

        self::assertSame(200, $this->controller()->statusAction($this->pollRequest())->getStatusCode());
    }

    #[Test]
    public function theSummaryOfAFreeScanIsFetchedThroughTheFreeChannel(): void
    {
        $this->storedScan(isFreePreview: true);
        $this->freeRemotePreviewService->expects(self::once())->method('getSummary')->willThrowException(new \RuntimeException('stop after routing'));
        $this->proCrawlerService->expects(self::never())->method('getSummary');

        $this->controller()->summaryAction($this->pollRequest());
    }

    #[Test]
    public function aPageScanCountsAsStartingUntilItsScanRecordExists(): void
    {
        $this->submissionTracker = $this->tracker();
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->with(10)->willReturn(true);
        $this->frontendPageUrlService->method('resolveForPage')->with($this->siteA, 10, 0)->willReturn('https://a.example/about');
        $pendingDuringSubmit = null;
        $pendingWhenRecorded = null;
        $this->proCrawlerService->expects(self::once())->method('submit')
            ->willReturnCallback(function () use (&$pendingDuringSubmit): CrawlerSubmitResult {
                $pendingDuringSubmit = $this->submissionTracker?->findPending('client-a');

                return new CrawlerSubmitResult('11111111-1111-4111-8111-111111111111', 'queued', 'single_page', null);
            });
        $this->remoteScanRepository->expects(self::once())->method('markSubmitted')
            ->willReturnCallback(function () use (&$pendingWhenRecorded): int {
                $pendingWhenRecorded = $this->submissionTracker?->findPending('client-a');

                return 1;
            });

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10, 'siteIdentifier' => 'client-a']));

        self::assertSame(200, $response->getStatusCode());
        self::assertIsArray($pendingDuringSubmit, 'the Page module must see the scan while the AQG service answers');
        self::assertSame(['page', 10], [$pendingDuringSubmit['scope'], $pendingDuringSubmit['pageUid']]);
        self::assertIsArray($pendingWhenRecorded, 'no gap between the submit and its scan record');
        self::assertNull($this->submissionTracker->findPending('client-a'));
    }

    #[Test]
    public function aFailedSubmitLeavesNoScanStarting(): void
    {
        $this->submissionTracker = $this->tracker();
        $this->allowScanNow();
        $this->scopeAccessService->method('canEditPage')->with(10)->willReturn(true);
        $this->frontendPageUrlService->method('resolveForPage')->with($this->siteA, 10, 0)->willReturn('https://a.example/about');
        $this->proCrawlerService->method('submit')->willThrowException(new \RuntimeException('AQG service unavailable'));

        $response = $this->controller()->submitPageAction($this->request(['pageUid' => 10, 'siteIdentifier' => 'client-a']));

        self::assertGreaterThanOrEqual(400, $response->getStatusCode());
        self::assertNull($this->submissionTracker->findPending('client-a'));
    }

    #[Test]
    public function aJobTheServiceNoLongerServesIsAnsweredAsGoneNotRestoredAgain(): void
    {
        $this->storedScan(isFreePreview: false);
        $refused = new ApiRequestFailedException('Forbidden', 403, null, 'forbidden_resource');
        $this->proCrawlerService->method('getStatus')->willThrowException($refused);
        $this->recoveryService = $this->createMock(RemoteScanRecoveryService::class);
        $this->recoveryService->expects(self::once())->method('discardUnavailableJob')
            ->with(self::callback(static fn (array $scan): bool => ($scan['job_id'] ?? '') === self::JOB), $refused)
            ->willReturn(true);

        $response = $this->controller()->statusAction($this->pollRequest());

        self::assertSame(410, $response->getStatusCode());
        self::assertSame(
            ['success' => false, 'code' => 'remote_job_unavailable', 'status' => 'failed'],
            array_intersect_key($this->decode($response), ['success' => 1, 'code' => 1, 'status' => 1]),
        );
    }

    #[Test]
    public function aTransientStatusFailureIsNotTreatedAsAGoneJob(): void
    {
        $this->storedScan(isFreePreview: false);
        $this->proCrawlerService->method('getStatus')->willThrowException(new ApiRequestFailedException('Unavailable', 503, null, 'service_unavailable'));
        $this->recoveryService = $this->createMock(RemoteScanRecoveryService::class);
        $this->recoveryService->method('discardUnavailableJob')->willReturn(false);

        $response = $this->controller()->statusAction($this->pollRequest());

        self::assertNotSame(410, $response->getStatusCode());
        self::assertNotSame('remote_job_unavailable', $this->decode($response)['code'] ?? '');
    }

    private function tracker(): RemoteScanSubmissionTracker
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(fn (string $namespace, string $key, mixed $default = null): mixed => $this->registryEntries[$namespace . '/' . $key] ?? $default);
        $registry->method('set')->willReturnCallback(function (string $namespace, string $key, mixed $value): void {
            $this->registryEntries[$namespace . '/' . $key] = $value;
        });
        $registry->method('remove')->willReturnCallback(function (string $namespace, string $key): void {
            unset($this->registryEntries[$namespace . '/' . $key]);
        });

        return new RemoteScanSubmissionTracker($registry);
    }

    private function storedScan(bool $isFreePreview): void
    {
        $this->accessControlService->method('canShowScanNow')->willReturn(true);
        $this->accessControlService->method('canShowScanAll')->willReturn(true);
        $this->scopeAccessService->method('canEditRemoteScan')->willReturn(true);
        $this->remoteScanRepository->method('findScanByJobId')->willReturn([
            'uid' => 55,
            'job_id' => self::JOB,
            'site_identifier' => 'client-a',
            'status' => 'running',
            'scan_scope' => 'page',
            'is_free_preview' => $isFreePreview ? 1 : 0,
            'persisted_at' => 0,
        ]);
    }

    private function runningStatus(): CrawlerStatusResult
    {
        return new CrawlerStatusResult(self::JOB, CrawlerJobStatus::Active, 0, 1, null, null);
    }

    private function pollRequest(): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn([]);
        $request->method('getQueryParams')->willReturn(['jobId' => self::JOB, 'siteIdentifier' => 'client-a']);

        return $request;
    }

    private function finding(): array
    {
        return [
            'issue' => ['uid' => 77, 'rule_id' => 'image-alt', 'remote_scan' => 90],
            'page' => ['uid' => 900, 'remote_scan' => 90, 'url' => 'https://a.example/board'],
            'scan' => ['uid' => 90, 'site_identifier' => 'client-a', 'language_uid' => 0, 'status' => 'completed'],
            'occurrences' => 2,
        ];
    }

    private function allowScanNow(): void
    {
        $this->accessControlService->method('canShowScanNow')->willReturn(true);
        $this->accessControlService->method('canShowScanAll')->willReturn(true);
    }

    private function controller(): ProCrawlerAjaxController
    {
        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)->disableOriginalConstructor()->getMock();
        $backendUser->user = ['uid' => 3, 'username' => 'editor'];
        $users = $this->createMock(BackendUserService::class);
        $users->method('isLoggedIn')->willReturn(true);
        $users->method('getBackendUser')->willReturn($backendUser);
        $users->method('getBackendUserUid')->willReturn(3);

        $sites = $this->createMock(SiteResolutionService::class);
        $sites->method('resolveSiteByPageId')->willReturnCallback(fn (int $uid): ?Site => $uid >= 100 ? $this->siteB : $this->siteA);
        $sites->method('resolveSiteByIdentifier')->willReturnCallback(fn (string $id): ?Site => match ($id) {
            'client-a' => $this->siteA,
            'client-b' => $this->siteB,
            default => null,
        });

        $capabilities = $this->createMock(ProCapabilityService::class);
        $capabilities->method('getStatus')->willReturn(new ProStatusViewModel(
            configured: true,
            valid: $this->licenceIsPaid,
            proAvailable: true,
            plan: $this->licenceIsPaid ? 'pro' : 'free',
            features: ['crawler'],
            reason: null,
            reasonLabel: null,
            statusLabel: '',
            showProHints: false,
            hasCrawler: true,
            hasExportPdf: true,
            hasMultiSite: false,
            hasProRules: true,
            isTrial: $this->licenceIsTrial,
        ));

        $parameters = $this->createMock(RequestParameterService::class);
        $parameters->method('getLanguageUidFromParameters')->willReturnCallback(
            static fn (array $data): int => (int)($data['languageUid'] ?? 0)
        );
        $accessSettings = $this->createMock(RemoteScanAccessSettingsService::class);
        $accessSettings->method('buildForSite')->willReturn([
            'scannerPreviewToken' => '',
            'scannerTokenLength' => 0,
            'resolvedRulesetUid' => 0,
            'resolvedRulesetSiteIdentifier' => '',
            'httpAuthUser' => '',
            'httpAuthPass' => '',
            'excludedPatterns' => [],
            'priorityUrls' => [],
            'cookieSelectors' => [],
        ]);
        $extensionContext = $this->createMock(ExtensionContextService::class);
        $extensionContext->method('getExtensionVersion')->willReturn('1.9.6');

        return new ProCrawlerAjaxController(
            $this->proCrawlerService,
            $this->accessControlService,
            $sites,
            $this->createMock(RemoteScanPersistenceService::class),
            new RemoteScanInputResolver(new DomainNormalizer(), $this->createMock(RequestFactory::class)),
            $capabilities,
            $this->remoteScanRepository,
            $extensionContext,
            $this->createMock(DateTimeService::class),
            $this->createMock(RemoteScanResponseService::class),
            $this->recoveryService ?? $this->createMock(RemoteScanRecoveryService::class),
            $this->createMock(SiteLanguageService::class),
            $parameters,
            $this->freeRemotePreviewService,
            $this->createMock(FreeSubmitIntentService::class),
            $this->frontendPageUrlService,
            $this->scopeAccessService,
            $this->targetResolver,
            new RemoteScanErrorPresenter(),
            $this->fixVerificationService,
            $accessSettings,
            new ResponseFactory(),
            new StreamFactory(),
            $users,
            $this->submissionTracker,
        );
    }

    /** @param array<string, mixed> $body */
    private function request(array $body): ServerRequestInterface
    {
        $request = $this->createMock(ServerRequestInterface::class);
        $request->method('getParsedBody')->willReturn($body);
        $request->method('getQueryParams')->willReturn([]);

        return $request;
    }

    /** @return array<string, mixed> */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
