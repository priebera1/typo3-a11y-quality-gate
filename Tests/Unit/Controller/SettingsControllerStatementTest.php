<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\AbstractBackendModuleController;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Export\PdfGenerator;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Service\AccessControlService;
use Priebera\A11yQualityGate\Service\AccessibilityStatementService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Psr\Http\Message\ResponseInterface;
use ReflectionClass;
use ReflectionProperty;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Http\ResponseFactory;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\StreamFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Every failed generation used to answer HTTP 200 with success:false, so a crawler rate limit, an
 * upstream outage and a missing scan were indistinguishable to the browser and to proxies.
 */
final class SettingsControllerStatementTest extends TestCase
{
    private const JOB_ID = '11111111-1111-4111-8111-111111111111';
    private const SITE_BASE = 'https://example.org/';

    private AccessibilityStatementService $statementService;
    private AccessControlService $accessControl;
    private PdfGenerator $pdfGenerator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->statementService = $this->createMock(AccessibilityStatementService::class);
        $this->statementService->method('isValidJobId')->willReturnCallback(
            static fn (string $jobId): bool => preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $jobId) === 1
        );
        $this->statementService->method('isValidPageUrl')->willReturnCallback(
            static fn (string $url): bool => str_starts_with($url, 'https://example.org/')
        );
        $this->accessControl = $this->createMock(AccessControlService::class);
        $this->accessControl->method('canShowSettings')->willReturn(true);
        $this->pdfGenerator = $this->createMock(PdfGenerator::class);
    }

    #[Test]
    public function aRateLimitKeepsItsStatusAndRetryAfter(): void
    {
        $this->statementService->method('loadLatestSiteScan')->willReturn(
            $this->failedStatement('The AQG service is limiting requests right now. Try again in about 42 seconds.', 'rate_limited', 429, 42)
        );

        $response = $this->subject()->generateAccessibilityStatementAction($this->request());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('42', $response->getHeaderLine('Retry-After'));
        self::assertSame([
            'success' => false,
            'code' => 'rate_limited',
            'message' => 'The AQG service is limiting requests right now. Try again in about 42 seconds.',
            'retryAfter' => 42,
        ], $this->decode($response));
    }

    /**
     * @return iterable<string, array{0: string, 1: ?int, 2: int}>
     */
    public static function failureStatusProvider(): iterable
    {
        yield 'upstream outage' => ['upstream_unavailable', 503, 503];
        yield 'invalid upstream response' => ['invalid_upstream_response', 502, 502];
        yield 'no completed scan' => ['not_found', 404, 404];
        yield 'licence unavailable' => ['licence_unavailable', 403, 403];
        yield 'scan without checked pages' => ['scan_without_pages', 422, 422];
        yield 'unexpected failure' => ['statement_failed', 500, 500];
        yield 'failure without a status' => ['statement_unavailable', null, 503];
    }

    #[DataProvider('failureStatusProvider')]
    #[Test]
    public function aFailedGenerationIsNeverAnHttp200(string $code, ?int $status, int $expectedStatus): void
    {
        $this->statementService->method('loadLatestSiteScan')->willReturn($this->failedStatement('Bounded message.', $code, $status));

        $response = $this->subject()->generateAccessibilityStatementAction($this->request());

        self::assertSame($expectedStatus, $response->getStatusCode());
        self::assertSame('', $response->getHeaderLine('Retry-After'));
        self::assertSame(['success' => false, 'code' => $code, 'message' => 'Bounded message.'], $this->decode($response));
    }

    #[Test]
    public function anInvalidDraftIsRejectedBeforeAnyStatementIsLoaded(): void
    {
        $this->statementService->method('validateDraftOptions')->willReturn([
            'field' => 'contactEmail',
            'code' => 'invalid_email',
            'message' => 'Enter a valid contact email address.',
        ]);
        $this->expectNoStatementLoaded();

        $response = $this->subject()->generateAccessibilityStatementAction($this->request(['draftOptions' => ['contactEmail' => 'nope']]));

        self::assertSame(400, $response->getStatusCode());
        self::assertSame(['success' => false, 'code' => 'invalid_email', 'message' => 'Enter a valid contact email address.'], $this->decode($response));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function rejectedRequestProvider(): iterable
    {
        yield 'tampered scope' => [['scope' => 'all_sites'], 'invalid_scope'];
        yield 'unsupported language' => [['language' => 'fr'], 'unsupported_language'];
        yield 'missing site' => [['siteId' => ''], 'site_required'];
        yield 'missing job ID' => [['scope' => 'specific_job', 'jobId' => ''], 'job_id_required'];
        yield 'malformed job ID' => [['scope' => 'specific_job', 'jobId' => '../../admin'], 'invalid_job_id'];
        yield 'missing page URL' => [['scope' => 'latest_page', 'startUrl' => ''], 'page_url_required'];
        yield 'script page URL' => [['scope' => 'latest_page', 'startUrl' => 'javascript:alert(1)'], 'invalid_page_url'];
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('rejectedRequestProvider')]
    #[Test]
    public function malformedRequestsAreRejectedWithoutLoading(array $body, string $expectedCode): void
    {
        $this->expectNoStatementLoaded();

        $response = $this->subject()->generateAccessibilityStatementAction($this->request($body));

        self::assertSame(400, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertFalse($payload['success']);
        self::assertSame($expectedCode, $payload['code']);
        self::assertStringStartsWith('translated:', $payload['message']);
    }

    #[Test]
    public function aSpecificJobIsPinnedToTheSelectedSite(): void
    {
        $draft = ['organisation' => 'Example Organisation'];
        $this->statementService->expects(self::once())
            ->method('loadByJobId')
            ->with(self::SITE_BASE, self::JOB_ID, 'en', $draft, 'main')
            ->willReturn($this->availableStatement());

        $response = $this->subject()->generateAccessibilityStatementAction(
            $this->request(['scope' => 'specific_job', 'jobId' => self::JOB_ID, 'draftOptions' => $draft])
        );

        self::assertSame(200, $response->getStatusCode());
        $payload = $this->decode($response);
        self::assertTrue($payload['success']);
        self::assertSame('<article class="aqg-accessibility-statement"></article>', $payload['statement']['html']);
    }

    #[Test]
    public function latestSiteScopeUsesTheInstallationsLatestSiteScan(): void
    {
        $this->statementService->expects(self::once())
            ->method('loadLatestSiteScan')
            ->with(self::SITE_BASE, 'main', 'de', [])
            ->willReturn($this->availableStatement());
        $this->statementService->expects(self::never())->method('loadLatest');

        $response = $this->subject()->generateAccessibilityStatementAction($this->request(['language' => 'de']));

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function latestPageScopeLooksUpTheSinglePageScan(): void
    {
        $this->statementService->expects(self::once())
            ->method('loadLatest')
            ->with(self::SITE_BASE, 'main', 'single_page', 'https://example.org/contact', 'en', [])
            ->willReturn($this->availableStatement());

        $response = $this->subject()->generateAccessibilityStatementAction(
            $this->request(['scope' => 'latest_page', 'startUrl' => 'https://example.org/contact'])
        );

        self::assertSame(200, $response->getStatusCode());
    }

    #[Test]
    public function usersWithoutSettingsAccessAreDenied(): void
    {
        $accessControl = $this->createMock(AccessControlService::class);
        $accessControl->method('canShowSettings')->willReturn(false);
        $this->accessControl = $accessControl;
        $this->expectNoStatementLoaded();

        $response = $this->subject()->generateAccessibilityStatementAction($this->request());

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('access_denied', $this->decode($response)['code']);
    }

    #[Test]
    public function aPdfRenderingFailureIsBounded(): void
    {
        $this->statementService->method('loadLatestSiteScan')->willReturn($this->availableStatement());
        $this->pdfGenerator->method('render')->willThrowException(
            new \RuntimeException('mPDF error: cannot write /var/www/html/var/transient/mpdf/ttfontdata')
        );

        $response = $this->subject()->generateAccessibilityStatementPdfAction($this->request());

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([
            'success' => false,
            'code' => 'pdf_generation_failed',
            'message' => 'translated:settings.statement.error.pdfUnavailable',
        ], $this->decode($response));
    }

    #[Test]
    public function aRateLimitedPdfKeepsItsStatus(): void
    {
        $this->statementService->method('loadLatestSiteScan')->willReturn($this->failedStatement('Rate limited.', 'rate_limited', 429, 30));
        $this->pdfGenerator->expects(self::never())->method('render');

        $response = $this->subject()->generateAccessibilityStatementPdfAction($this->request());

        self::assertSame(429, $response->getStatusCode());
        self::assertSame('30', $response->getHeaderLine('Retry-After'));
    }

    private function expectNoStatementLoaded(): void
    {
        $this->statementService->expects(self::never())->method('loadByJobId');
        $this->statementService->expects(self::never())->method('loadLatest');
        $this->statementService->expects(self::never())->method('loadLatestSiteScan');
    }

    /**
     * @return array<string, mixed>
     */
    private function failedStatement(string $message, string $code, ?int $status, ?int $retryAfter = null): array
    {
        return [
            'available' => false,
            'message' => $message,
            'html' => '',
            'error' => array_filter(
                ['code' => $code, 'httpStatus' => $status, 'retryAfter' => $retryAfter],
                static fn (mixed $value): bool => $value !== null
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function availableStatement(): array
    {
        return [
            'available' => true,
            'message' => '',
            'html' => '<article class="aqg-accessibility-statement"></article>',
            'text' => "Draft\n",
            'language' => 'en',
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function request(array $overrides = []): ServerRequest
    {
        return (new ServerRequest('https://example.org/typo3/ajax/a11y/settings/statement/generate', 'POST'))
            ->withParsedBody(array_replace([
                'siteId' => 'main',
                'scope' => 'latest_site',
                'language' => 'en',
                'draftOptions' => [],
            ], $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function decode(ResponseInterface $response): array
    {
        $decoded = json_decode((string)$response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }

    private function subject(): SettingsController
    {
        $backendContext = $this->createMock(BackendContextService::class);
        $backendContext->method('getBackendUser')->willReturn($this->createMock(BackendUserAuthentication::class));
        $backendContext->method('translate')->willReturnCallback(static fn (string $key): string => 'translated:' . $key);

        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri(self::SITE_BASE));
        $siteResolution = $this->createMock(SiteResolutionService::class);
        $siteResolution->method('resolveSiteByIdentifier')->willReturnCallback(
            static fn (string $identifier): ?Site => $identifier === 'main' ? $site : null
        );

        $proStatus = $this->createMock(ProStatusResolverService::class);
        $proStatus->method('resolveForSiteIdentifier')->willReturn((object)['valid' => true, 'isTrial' => false, 'hasCrawler' => true]);

        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        foreach ([
            [AbstractBackendModuleController::class, 'backendContextService', $backendContext],
            [AbstractBackendModuleController::class, 'siteResolutionService', $siteResolution],
            [SettingsController::class, 'accessControlService', $this->accessControl],
            [SettingsController::class, 'accessibilityStatementService', $this->statementService],
            [SettingsController::class, 'proStatusResolverService', $proStatus],
            [SettingsController::class, 'pdfGenerator', $this->pdfGenerator],
            [SettingsController::class, 'responseFactory', new ResponseFactory()],
            [SettingsController::class, 'streamFactory', new StreamFactory()],
        ] as [$class, $property, $value]) {
            (new ReflectionProperty($class, $property))->setValue($subject, $value);
        }

        return $subject;
    }
}
