<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Exception\ApiRequestFailedException;
use Priebera\A11yQualityGate\Pro\Exception\ProNotConfiguredException;
use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Service\AccessibilityStatementService;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\RuleMetadataPresentationService;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use ReflectionProperty;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Statement Assistant: server-side draft validation, the statement contract with the crawler API,
 * bounded failures, and what the generated draft may and may not claim.
 */
final class AccessibilityStatementServiceTest extends TestCase
{
    private const JOB_ID = '0404f4e3-4031-4403-8401-403404401403';
    private const SITE_BASE = 'https://example.org/';

    /** @var array<string, array<string, string>> */
    private static array $catalogues = [];

    private ProCrawlerService $crawler;
    private RemoteScanRepository $remoteScanRepository;

    /** @var AbstractLogger&object{records: list<array{level: mixed, message: string, context: array<string, mixed>}>} */
    private AbstractLogger $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->crawler = $this->createMock(ProCrawlerService::class);
        $this->remoteScanRepository = $this->createMock(RemoteScanRepository::class);

        $this->logger = new class () extends AbstractLogger {
            /** @var list<array{level: mixed, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => $level, 'message' => (string)$message, 'context' => $context];
            }
        };
        GeneralUtility::setSingletonInstance(LogManager::class, new class ($this->logger) extends LogManager {
            public function __construct(private readonly LoggerInterface $recordingLogger)
            {
                parent::__construct();
            }

            public function getLogger(string $name = ''): LoggerInterface
            {
                return $this->recordingLogger;
            }
        });
    }

    protected function tearDown(): void
    {
        GeneralUtility::purgeInstances();
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------------------------
    // Draft validation
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function aMinimalDraftIsValid(): void
    {
        self::assertNull($this->subject()->validateDraftOptions([]));
        self::assertNull($this->subject()->validateDraftOptions(self::formDefaults()));
    }

    #[Test]
    public function aFullyPopulatedDraftIsValid(): void
    {
        self::assertNull($this->subject()->validateDraftOptions(self::fullDraft()));
    }

    #[Test]
    public function unicodeAndInternationalisedAddressesAreAccepted(): void
    {
        self::assertNull($this->subject()->validateDraftOptions([
            'organisation' => 'Müller & Söhne – Barrierefreiheit „Straße“',
            'contactEmail' => 'info@müller.de',
            'evaluationReportUrl' => 'https://bücher.example/prüfbericht',
            'commitmentText' => str_repeat('ä', 1600),
        ]));
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string, 2: string}>
     */
    public static function invalidDraftProvider(): iterable
    {
        yield 'tampered conformance status' => [['conformityStatus' => 'fully_compliant', 'statusConfirmed' => true], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'tampered legacy status alias' => [['status' => 'certified'], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'tampered standard' => [['accessibilityStandard' => 'wcag30'], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'tampered enforcement' => [['enforcementProcedure' => 'france'], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'unknown measure' => [['measures' => ['automated_scans', 'iso_certified']], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'technologies not a list' => [['technologies' => 'html'], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'unknown assessment' => [['assessmentApproach' => ['legal_certification']], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'non-boolean confirmation' => [['statusConfirmed' => 'maybe'], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'text field sent as array' => [['organisation' => ['a', 'b']], 'invalid_option', 'The form contains an unsupported option.'];
        yield 'organisation too long' => [['organisation' => str_repeat('x', 241)], 'too_long', '“Organization name” is too long (maximum 240 characters).'];
        yield 'legacy organisation alias too long' => [['organization' => str_repeat('x', 241)], 'too_long', '“Organization name” is too long (maximum 240 characters).'];
        yield 'multibyte commitment too long' => [['commitmentText' => str_repeat('ä', 1601)], 'too_long', '“Short accessibility commitment” is too long (maximum 1600 characters).'];
        yield 'custom enforcement too long' => [['enforcementProcedure' => 'custom', 'customEnforcementText' => str_repeat('y', 2001)], 'too_long', '“Custom enforcement text” is too long (maximum 2000 characters).'];
        yield 'localised date format' => [['statementCreatedDate' => '15.09.2026'], 'invalid_date', '“Statement created date” must be a valid date that is not in the future.'];
        yield 'impossible date' => [['statementCreatedDate' => '2026-02-30'], 'invalid_date', '“Statement created date” must be a valid date that is not in the future.'];
        yield 'implausibly old date' => [['approvalDate' => '1999-12-31'], 'invalid_date', '“Approval date” must be a valid date that is not in the future.'];
        yield 'future date' => [['approvalDate' => (new \DateTimeImmutable('+3 days'))->format('Y-m-d')], 'invalid_date', '“Approval date” must be a valid date that is not in the future.'];
        yield 'approval before creation' => [['statementCreatedDate' => self::daysAgo(5), 'approvalDate' => self::daysAgo(9)], 'date_order', 'The approval date cannot be earlier than the statement created date.'];
        yield 'unconfirmed status' => [['conformityStatus' => 'mostly_compliant', 'statusConfirmed' => false], 'status_not_confirmed', 'Confirm the selected conformance status manually'];
        yield 'custom standard left blank' => [['accessibilityStandard' => 'custom', 'customAccessibilityStandard' => "  \n "], 'custom_standard_missing', 'Enter a custom accessibility standard'];
        yield 'custom enforcement left blank' => [['enforcementProcedure' => 'custom', 'customEnforcementText' => '   '], 'custom_enforcement_missing', 'Enter custom enforcement text'];
        yield 'malformed email' => [['contactEmail' => 'not-an-email'], 'invalid_email', 'Enter a valid contact email address'];
        yield 'email without domain dot' => [['contactEmail' => 'office@localhost'], 'invalid_email', 'Enter a valid contact email address'];
        yield 'email with a space' => [['contactEmail' => 'office@exa mple.org'], 'invalid_email', 'Enter a valid contact email address'];
        yield 'script URL' => [['evaluationReportUrl' => 'javascript:alert(1)'], 'invalid_url', 'Enter a valid http/https evaluation report URL'];
        yield 'URL without host' => [['evaluationReportUrl' => 'https://'], 'invalid_url', 'Enter a valid http/https evaluation report URL'];
        yield 'non-web URL' => [['evaluationReportUrl' => 'ftp://example.org/report.pdf'], 'invalid_url', 'Enter a valid http/https evaluation report URL'];
        yield 'URL with whitespace' => [['evaluationReportUrl' => 'https://example.org/a report'], 'invalid_url', 'Enter a valid http/https evaluation report URL'];
    }

    /**
     * @param array<string, mixed> $draft
     */
    #[DataProvider('invalidDraftProvider')]
    #[Test]
    public function invalidDraftsAreRejectedWithALocalisedMessage(array $draft, string $expectedCode, string $expectedMessage): void
    {
        $error = $this->subject()->validateDraftOptions($draft);

        self::assertIsArray($error);
        self::assertSame($expectedCode, $error['code']);
        self::assertStringStartsWith($expectedMessage, $error['message']);
    }

    #[Test]
    public function invalidDraftsNeverReachTheApi(): void
    {
        // The controller validates first; this guards the service contract it relies on.
        $this->crawler->expects(self::never())->method('getAccessibilityStatement');

        $result = $this->subject()->loadByJobId(self::SITE_BASE, 'not-a-uuid', 'en', [], 'main');

        self::assertFalse($result['available']);
        self::assertSame(['code' => 'invalid_job_id', 'httpStatus' => 400, 'retryAfter' => null], $result['error']);
    }

    // ---------------------------------------------------------------------------------------------
    // Generated statement
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function aMinimalDraftProducesAnHonestPlaceholderStatement(): void
    {
        $statement = $this->generate(self::payload(), []);

        self::assertTrue($statement['available']);
        self::assertSame(
            ['commitment', 'measures', 'scope', 'accessibility_standard', 'conformance_status', 'known_limitations', 'alternatives_remediation', 'feedback', 'compatibility', 'technical_specifications', 'assessment_approach', 'enforcement', 'technical_scan_summary', 'limitations', 'created_by'],
            array_column($statement['sections'], 'key'),
        );
        $html = $statement['html'];
        self::assertStringContainsString('<h1>Draft Accessibility Statement for example.org</h1>', $html);
        self::assertStringContainsString('[Insert organization name]', $html);
        self::assertStringContainsString('Organization name should be completed before publication.', $html);
        self::assertStringContainsString('Contact details are required before publishing this statement.', $html);
        self::assertStringContainsString('Automated assessment: accessibility issues found.', $html);
        self::assertStringNotContainsString('significant', $html);
        self::assertStringNotContainsString('Response time', $html, 'No response time may be invented for the organisation.');
        self::assertStringNotContainsString('5 business days', $statement['text']);
        self::assertSame(1, substr_count($html, 'Automated draft - manual review required before publication.'));
        self::assertStringNotContainsString('latest automated check', $html);
        $this->assertNoUnresolvedMarkup($statement);
    }

    #[Test]
    public function aFullyPopulatedDraftUsesEveryField(): void
    {
        $statement = $this->generate(self::payload(), self::fullDraft());
        $html = $statement['html'];
        $text = $statement['text'];

        self::assertStringContainsString('<h1>Draft Accessibility Statement for Example Portal</h1>', $html);
        self::assertStringContainsString('Example Organisation GmbH is committed to accessibility.', $html);
        self::assertStringContainsString('<dt>Statement created</dt>', $html);
        self::assertStringContainsString('<dd>' . self::formatted(self::daysAgo(14)) . '</dd>', $html);
        self::assertStringContainsString('Statement created: ' . self::formatted(self::daysAgo(14)), $text);
        self::assertStringContainsString('This draft refers to EN 301 549 / WCAG-based requirements.', $html);
        self::assertStringContainsString('<dd>Partially conformant</dd>', $html);
        self::assertStringContainsString('The selected draft status is: partially conformant.', $html);
        self::assertStringContainsString('Quarterly audits with users of assistive technology.', $html);
        self::assertStringContainsString('<dd>accessibility@example.org</dd>', $html);
        self::assertStringContainsString("<dd>Example Organisation GmbH<br>\nMusterstraße 1<br>\n10115 Berlin</dd>", $html);
        self::assertStringContainsString('<dt>Response time</dt>', $html);
        self::assertStringContainsString('<dd>10 business days</dd>', $html);
        self::assertStringContainsString('<li>WAI-ARIA</li>', $html);
        self::assertStringContainsString('An evaluation report is available at: https://example.org/accessibility-report', $html);
        self::assertStringContainsString('<dd>Erika Mustermann</dd>', $html);
        self::assertStringContainsString('<dd>' . self::formatted(self::daysAgo(4)) . '</dd>', $html);
        self::assertStringContainsString('Schlichtungsstelle nach § 16 BGG', $html);
        self::assertStringNotContainsString('[Insert', $html, 'A completed draft must not keep publication placeholders.');
        self::assertStringNotContainsString('should be completed before publication', $html);
        $this->assertNoUnresolvedMarkup($statement);
    }

    #[Test]
    public function userValuesAreEscapedAndNeverExpandedAsPlaceholders(): void
    {
        $statement = $this->generate(self::payload(), [
            'websiteName' => '<script>alert(1)</script> {page}',
            'organisation' => 'Org "><img src=x onerror=alert(2)> {website}',
            'customMeasure' => "<b>bold</b>\nsecond line",
            'responseNote' => 'Literal \\n stays {pages}',
            'measures' => ['automated_scans'],
        ]);
        $html = $statement['html'];

        self::assertStringNotContainsString('<script', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('<b>', $html);
        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt; {page}', $html);
        self::assertStringContainsString('Org &quot;&gt;&lt;img src=x onerror=alert(2)&gt; {website} takes the following measures', $html);
        self::assertStringContainsString('Literal \\n stays {pages}', $html);
        self::assertStringNotContainsString('{PAGENO}', $html);
        self::assertStringNotContainsString('{nb}', $html);
    }

    #[Test]
    public function aGermanStatementUsesGermanWording(): void
    {
        $statement = $this->generate(self::payload(), ['organisation' => 'Beispiel GmbH'], 'de');
        $html = $statement['html'];

        self::assertStringContainsString('Entwurf einer Barrierefreiheitserklärung für example.org', $html);
        self::assertStringContainsString('Bekannte Barrieren und Einschränkungen', $html);
        self::assertStringContainsString('Fundstellen', $html);
        self::assertStringContainsString('Datum der automatisierten Prüfung', $html);
        self::assertStringNotContainsString('Known accessibility limitations', $html);
        self::assertStringNotContainsString('Accessibility commitment', $html);
        $this->assertNoUnresolvedMarkup($statement);
    }

    #[Test]
    public function aScanWithoutFindingsDoesNotClaimIssues(): void
    {
        $payload = self::payload();
        $payload['status']['statementStatus'] = 'draft_no_issues_found';
        $payload['summary'] = array_replace($payload['summary'], ['issuesTotal' => 0, 'critical' => 0, 'serious' => 0, 'moderate' => 0, 'score' => 100]);
        $payload['knownIssues']['topIssueTypes'] = [];

        $html = $this->generate($payload, [])['html'];

        self::assertStringContainsString('the automated checks did not detect accessibility issues in the scanned scope', $html);
        self::assertStringContainsString('did not report recurring issue types for the scanned scope', $html);
        self::assertStringNotContainsString('accessibility issues found', $html);
        self::assertStringNotContainsString('identified the following recurring issue types', $html);
        self::assertStringNotContainsString('API response', $html);
    }

    #[Test]
    public function anIncompleteScanGetsNoConformanceSuggestion(): void
    {
        $payload = self::payload();
        $payload['status']['statementStatus'] = 'scan_failed_or_incomplete';
        $payload['summary']['score'] = 95;

        $statement = $this->generate($payload, []);

        self::assertSame('not_confirmed', $statement['draftOptions']['suggestedConformityStatus']);
        self::assertStringContainsString('the automated scan is incomplete because some pages could not be checked', $statement['html']);
        self::assertStringNotContainsString('Mostly conformant', $statement['html']);
    }

    #[Test]
    public function deselectedMeasuresAndTechnologiesAreNotReAdded(): void
    {
        $deselected = $this->generate(self::payload(), ['measures' => [], 'technologies' => []]);
        $keys = array_column($deselected['sections'], 'key');
        self::assertNotContains('measures', $keys);
        self::assertNotContains('technical_specifications', $keys);
        self::assertStringNotContainsString('Feedback channel for accessibility issues', $deselected['html']);

        $defaults = $this->generate(self::payload(), []);
        self::assertStringContainsString('Feedback channel for accessibility issues', $defaults['html']);
        self::assertStringContainsString('<li>HTML</li>', $defaults['html']);
    }

    #[Test]
    public function latestSiteScanIsTheNewestPaidSiteScanOfThisInstallation(): void
    {
        $this->remoteScanRepository->expects(self::once())
            ->method('findLastCompletedSiteScanBySite')
            ->with('main', -1, false)
            ->willReturn(['uid' => 12, 'job_id' => self::JOB_ID, 'source_type' => 'crawl']);
        $payload = self::payload();
        $payload['source']['sourceType'] = 'crawl';
        $this->crawler->expects(self::once())
            ->method('getAccessibilityStatement')
            ->with('example.org', '1.9.3', self::JOB_ID, 'en')
            ->willReturn($payload);
        $this->crawler->expects(self::never())->method('getLatestAccessibilityStatement');

        $statement = $this->subject()->loadLatestSiteScan(self::SITE_BASE, 'main', 'en', []);

        self::assertTrue($statement['available']);
        self::assertStringContainsString('Site crawl', $statement['html']);
    }

    #[Test]
    public function withoutACompletedSiteScanNothingIsRequested(): void
    {
        $this->remoteScanRepository->method('findLastCompletedSiteScanBySite')->willReturn(null);
        $this->crawler->expects(self::never())->method('getAccessibilityStatement');

        $statement = $this->subject()->loadLatestSiteScan(self::SITE_BASE, 'main', 'en', []);

        self::assertFalse($statement['available']);
        self::assertSame(['code' => 'no_site_scan', 'httpStatus' => 404, 'retryAfter' => null], $statement['error']);
        self::assertSame('No completed remote site scan was found for this site. Run a remote site scan first.', $statement['message']);
    }

    #[Test]
    public function aJobOfAnotherSiteIsRejected(): void
    {
        $payload = self::payload();
        $payload['source']['siteId'] = 'other-site';
        $this->crawler->method('getAccessibilityStatement')->willReturn($payload);

        $statement = $this->subject()->loadByJobId(self::SITE_BASE, self::JOB_ID, 'en', [], 'main');

        self::assertFalse($statement['available']);
        self::assertSame('job_site_mismatch', $statement['error']['code']);
        self::assertSame('', $statement['html']);
    }

    // ---------------------------------------------------------------------------------------------
    // Contract with the crawler API
    // ---------------------------------------------------------------------------------------------

    #[Test]
    public function theDocumentedDataEnvelopeIsAccepted(): void
    {
        $this->crawler->method('getAccessibilityStatement')->willReturn(['success' => true, 'data' => self::payload()]);

        self::assertTrue($this->subject()->loadByJobId(self::SITE_BASE, self::JOB_ID, 'en', [], 'main')['available']);
    }

    /**
     * @return iterable<string, array{0: \Closure(array<string, mixed>): array<string, mixed>, 1: string, 2: int}>
     */
    public static function incompletePayloadProvider(): iterable
    {
        yield 'bare success envelope' => [static fn (array $p): array => ['success' => true], 'invalid_upstream_response', 502];
        yield 'no contract version' => [static function (array $p): array { unset($p['contractVersion']); return $p; }, 'invalid_upstream_response', 502];
        yield 'unknown major contract' => [static fn (array $p): array => ['contractVersion' => '2.0'] + $p, 'invalid_upstream_response', 502];
        yield 'no job ID' => [static function (array $p): array { $p['source']['jobId'] = ''; return $p; }, 'invalid_upstream_response', 502];
        yield 'unknown scan type' => [static function (array $p): array { $p['source']['sourceType'] = 'full_audit'; return $p; }, 'invalid_upstream_response', 502];
        yield 'no scanned URL' => [static function (array $p): array { unset($p['source']['startUrl']); return $p; }, 'invalid_upstream_response', 502];
        yield 'no status' => [static function (array $p): array { unset($p['status']); return $p; }, 'invalid_upstream_response', 502];
        yield 'no page count' => [static function (array $p): array { unset($p['summary']['pagesScanned']); return $p; }, 'invalid_upstream_response', 502];
        yield 'issue types not a list' => [static function (array $p): array { $p['knownIssues']['topIssueTypes'] = 'image-alt'; return $p; }, 'invalid_upstream_response', 502];
        yield 'findings without issue types' => [static function (array $p): array { $p['knownIssues']['topIssueTypes'] = []; return $p; }, 'invalid_upstream_response', 502];
        yield 'no checked page' => [static function (array $p): array { $p['summary']['pagesScanned'] = 0; return $p; }, 'scan_without_pages', 422];
    }

    /**
     * @param \Closure(array<string, mixed>): array<string, mixed> $mutate
     */
    #[DataProvider('incompletePayloadProvider')]
    #[Test]
    public function anIncompletePayloadNeverBecomesAStatement(\Closure $mutate, string $expectedCode, int $expectedStatus): void
    {
        $this->crawler->method('getAccessibilityStatement')->willReturn($mutate(self::payload()));

        $statement = $this->subject()->loadByJobId(self::SITE_BASE, self::JOB_ID, 'en', [], 'main');

        self::assertFalse($statement['available']);
        self::assertSame($expectedCode, $statement['error']['code']);
        self::assertSame($expectedStatus, $statement['error']['httpStatus']);
        self::assertSame('', $statement['html']);
        self::assertSame([], $statement['sections']);
    }

    // ---------------------------------------------------------------------------------------------
    // Failure classification
    // ---------------------------------------------------------------------------------------------

    /**
     * @return iterable<string, array{0: \Throwable, 1: string, 2: int, 3: ?int, 4: string}>
     */
    public static function failureProvider(): iterable
    {
        $crawlerFailure = static fn (int $status, string $code, ?int $retryAfter = null, ?\Throwable $previous = null): TokenRefreshException => new TokenRefreshException(
            'Remote crawler accessibility statement request failed: AQG crawler HTTP ' . $status . ': upstream detail'
            . ' | code=' . $code
            . ' | url=https://api.priebera.sk/crawl/accessibility-statement/' . self::JOB_ID . '?language=en'
            . ' | auth={"authorizationHeaderType":"Bearer","accessTokenLength":403}'
            . ' | body={"success":false,"error":{"code":"' . $code . '","status":' . $status . '}}',
            0,
            new ApiRequestFailedException('AQG crawler HTTP ' . $status, $status, $previous, $code, [], $retryAfter),
        );

        // The job ID and token length above contain "401", "403" and "404": the substring mapper
        // reported this rate limit as a missing scan or a licence problem.
        yield 'rate limit with Retry-After' => [$crawlerFailure(429, 'internal_error', 42), 'rate_limited', 429, 42, 'The AQG service is limiting requests right now. Try again in about 42 seconds.'];
        yield 'rate limit without Retry-After' => [$crawlerFailure(429, 'rate_limit_exceeded'), 'rate_limited', 429, 60, 'The AQG service is limiting requests right now. Try again in about 60 seconds.'];
        yield 'statement build failed upstream' => [$crawlerFailure(500, 'accessibility_statement_failed'), 'upstream_unavailable', 503, null, 'The AQG service could not be reached or failed while preparing the statement.'];
        yield 'gateway error' => [$crawlerFailure(502, ''), 'upstream_unavailable', 503, null, 'The AQG service could not be reached or failed while preparing the statement.'];
        yield 'feature disabled upstream' => [$crawlerFailure(404, 'accessibility_statement_disabled'), 'statement_disabled', 503, null, 'Accessibility statement generation is currently disabled on the AQG service.'];
        yield 'no completed scan' => [$crawlerFailure(404, 'not_found'), 'not_found', 404, null, 'Accessibility statement is not available for this scan.'];
        yield 'token rejected after refresh' => [$crawlerFailure(401, 'invalid_token'), 'licence_unavailable', 403, null, 'Accessibility Statement Draft Assistant is not available for this licence or environment.'];
        yield 'capability missing' => [$crawlerFailure(403, 'feature_not_enabled'), 'licence_unavailable', 403, null, 'Accessibility Statement Draft Assistant is not available for this licence or environment.'];
        yield 'malformed job ID upstream' => [$crawlerFailure(400, 'invalid_job_id'), 'invalid_job_id', 400, null, 'Choose a valid job ID.'];
        yield 'unknown client error' => [$crawlerFailure(409, 'conflict'), 'request_rejected', 502, null, 'The AQG service rejected the statement request.'];
        yield 'connection timeout' => [
            new TokenRefreshException('Remote crawler accessibility statement request failed: AQG crawler request failed: cURL error 28', 0, new ApiRequestFailedException(
                'AQG crawler request failed: cURL error 28: Operation timed out | url=https://api.priebera.sk/crawl/accessibility-statement/latest',
                0,
                new class ('cURL error 28') extends \RuntimeException implements ClientExceptionInterface {},
            )),
            'upstream_unavailable', 503, null, 'The AQG service could not be reached or failed while preparing the statement.',
        ];
        yield 'malformed JSON' => [
            new TokenRefreshException('Remote crawler accessibility statement request failed', 0, new ApiRequestFailedException('AQG crawler returned invalid JSON | http=200', 0, new \JsonException('Syntax error'))),
            'invalid_upstream_response', 502, null, 'The AQG service returned an incomplete or invalid response.',
        ];
        yield 'empty body' => [
            new TokenRefreshException('Remote crawler accessibility statement request failed', 0, new ApiRequestFailedException('AQG crawler returned empty response body | http=504')),
            'invalid_upstream_response', 502, null, 'The AQG service returned an incomplete or invalid response.',
        ];
        yield 'logical error in HTTP 200' => [
            new TokenRefreshException('Remote crawler accessibility statement request failed', 0, new ApiRequestFailedException('AQG crawler logical error: failed', 200, null, 'logical_error')),
            'invalid_upstream_response', 502, null, 'The AQG service returned an incomplete or invalid response.',
        ];
        yield 'licence API unreachable' => [
            new TokenRefreshException('AQG API request failed.', 0, new ApiRequestFailedException('AQG API request failed.', 0, new class ('cURL error 7') extends \RuntimeException implements ClientExceptionInterface {}, 'transport_error')),
            'upstream_unavailable', 503, null, 'The AQG service could not be reached or failed while preparing the statement.',
        ];
        yield 'token refused by the licence API' => [new TokenRefreshException('Licence aqg_live_0123 expired for example.org'), 'licence_unavailable', 403, null, 'Accessibility Statement Draft Assistant is not available for this licence or environment.'];
        yield 'no licence configured' => [new ProNotConfiguredException('AQG PRO licence key is not configured.'), 'licence_unavailable', 403, null, 'Accessibility Statement Draft Assistant is not available for this licence or environment.'];
        yield 'unexpected error' => [new \RuntimeException('SQLSTATE[HY000] in /var/www/html/vendor/doctrine'), 'statement_failed', 500, null, 'Accessibility statement is not available right now.'];
    }

    #[DataProvider('failureProvider')]
    #[Test]
    public function failuresBecomeBoundedLocalisedErrors(\Throwable $failure, string $expectedCode, int $expectedStatus, ?int $expectedRetryAfter, string $expectedMessage): void
    {
        $this->crawler->method('getAccessibilityStatement')->willThrowException($failure);

        $statement = $this->subject()->loadByJobId(self::SITE_BASE, self::JOB_ID, 'en', [], 'main');

        self::assertFalse($statement['available']);
        self::assertSame(['code' => $expectedCode, 'httpStatus' => $expectedStatus, 'retryAfter' => $expectedRetryAfter], $statement['error']);
        self::assertStringStartsWith($expectedMessage, $statement['message']);
        foreach (['api.priebera.sk', 'url=', 'body=', 'Bearer', 'aqg_live_', 'SQLSTATE', 'cURL', 'upstream detail'] as $internal) {
            self::assertStringNotContainsString($internal, (string)json_encode($statement));
        }

        self::assertCount(1, $this->logger->records, 'The detail belongs in the server log.');
        self::assertSame($expectedCode, $this->logger->records[0]['context']['failureCode']);
        self::assertSame($failure->getMessage(), $this->logger->records[0]['context']['message']);
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $draft
     * @return array<string, mixed>
     */
    private function generate(array $payload, array $draft, string $language = 'en'): array
    {
        $this->crawler->method('getAccessibilityStatement')->willReturn($payload);
        $subject = $this->subject();
        self::assertNull($subject->validateDraftOptions($draft), 'Fixture drafts must be valid.');

        $statement = $subject->loadByJobId(self::SITE_BASE, self::JOB_ID, $language, $draft, 'main');
        self::assertTrue($statement['available'], (string)($statement['message'] ?? ''));

        return $statement;
    }

    /**
     * @param array<string, mixed> $statement
     */
    private function assertNoUnresolvedMarkup(array $statement): void
    {
        foreach (['html', 'text'] as $format) {
            self::assertDoesNotMatchRegularExpression('/\b(?:settings\.)?statement\.[a-z]+\.[A-Za-z.]+/', $statement[$format], 'Raw translation key in ' . $format);
            self::assertDoesNotMatchRegularExpression('/\{(?:website|organisation|url|standard|page|pages|seconds|field|max)\}/', $statement[$format], 'Unresolved placeholder in ' . $format);
        }
    }

    private function subject(): AccessibilityStatementService
    {
        $languageService = new BackendLanguageService();
        (new ReflectionProperty(BackendLanguageService::class, 'explicitLanguageCatalogues'))->setValue($languageService, [
            'en:locallang.xlf' => self::catalogue('locallang.xlf', 'en'),
            'de:locallang.xlf' => self::catalogue('de.locallang.xlf', 'de'),
        ]);

        $extensionContext = $this->createMock(ExtensionContextService::class);
        $extensionContext->method('getNormalizedDomainFromSiteBase')->willReturn('example.org');
        $extensionContext->method('getExtensionVersion')->willReturn('1.9.3');

        return new AccessibilityStatementService(
            $extensionContext,
            $this->crawler,
            new RuleMetadataPresentationService($languageService),
            $languageService,
            $this->remoteScanRepository,
        );
    }

    /**
     * @return array<string, string>
     */
    private static function catalogue(string $file, string $language): array
    {
        if (isset(self::$catalogues[$file])) {
            return self::$catalogues[$file];
        }

        $document = new \DOMDocument();
        self::assertTrue($document->load(__DIR__ . '/../../../Resources/Private/Language/' . $file));
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', 'urn:oasis:names:tc:xliff:document:1.2');
        $catalogue = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            $node = $xpath->query($language === 'de' ? './x:target' : './x:source', $unit)?->item(0);
            $value = trim((string)$node?->textContent);
            if ($unit instanceof \DOMElement && $value !== '') {
                $catalogue[$unit->getAttribute('id')] = $value;
            }
        }

        return self::$catalogues[$file] = $catalogue;
    }

    /**
     * Mirrors aqg-crawler docs/fixtures/statement-sitemap-en.sample.json (contract 1.0).
     *
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'contractVersion' => '1.0',
            'generatedAt' => '2026-09-15T08:00:00.000Z',
            'statementType' => 'automated_accessibility_statement',
            'source' => [
                'jobId' => self::JOB_ID,
                'siteId' => 'main',
                'sourceType' => 'sitemap',
                'startUrl' => 'https://example.org/',
                'domain' => 'example.org',
                'scannedAt' => '2026-09-15T07:30:00.000Z',
            ],
            'status' => [
                'automatedSignal' => 'issues_found',
                'manualReviewRequired' => true,
                'statementStatus' => 'draft_requires_review',
            ],
            'summary' => [
                'pagesScanned' => 15,
                'failedPagesTotal' => 0,
                'issuesTotal' => 42,
                'critical' => 8,
                'serious' => 14,
                'moderate' => 20,
                'minor' => 0,
                'overallImpact' => 'critical',
                'score' => 68,
            ],
            'knownIssues' => [
                'topIssueTypes' => [
                    ['ruleId' => 'image-alt', 'title' => 'Images without alternative text', 'impact' => 'critical', 'count' => 24, 'exampleUrl' => 'https://example.org/page'],
                    ['ruleId' => 'label', 'title' => 'Form fields without labels', 'impact' => 'critical', 'count' => 6, 'exampleUrl' => 'https://example.org/contact'],
                ],
                'affectedPagesSample' => [],
            ],
            'limitations' => [
                'failedPages' => [],
                'notCovered' => ['Manual keyboard testing'],
                'automatedChecksOnly' => true,
            ],
            'auditSupport' => [
                'notClaimed' => ['WCAG compliance is not claimed.'],
                'manualReviewNotice' => 'Manual review may still be required.',
            ],
        ];
    }

    /**
     * What the form sends when nothing was typed.
     *
     * @return array<string, mixed>
     */
    private static function formDefaults(): array
    {
        return [
            'websiteName' => '', 'organisation' => '', 'commitmentText' => '', 'statementCreatedDate' => '',
            'accessibilityStandard' => 'wcag22aa', 'customAccessibilityStandard' => '',
            'conformityStatus' => 'not_confirmed', 'statusConfirmed' => false,
            'measures' => ['automated_scans', 'feedback_channel'], 'customMeasure' => '', 'remediationNote' => '',
            'contactEmail' => '', 'phone' => '', 'postalAddress' => '', 'responseTime' => '', 'responseNote' => '',
            'compatibleEnvironments' => '', 'incompatibleEnvironments' => '',
            'technologies' => ['html', 'css', 'javascript'], 'assessmentApproach' => ['aqg_automated', 'axe_playwright', 'manual_required'],
            'manualReviewPerformed' => false, 'evaluationReportUrl' => '',
            'approvalOrganisation' => '', 'approvalPerson' => '', 'approvalRole' => '', 'approvalDate' => '',
            'enforcementProcedure' => 'generic', 'customEnforcementText' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function fullDraft(): array
    {
        return [
            'websiteName' => 'Example Portal',
            'organisation' => 'Example Organisation GmbH',
            'commitmentText' => '[Organisation] is committed to accessibility.',
            'statementCreatedDate' => self::daysAgo(14),
            'accessibilityStandard' => 'en301549',
            'customAccessibilityStandard' => '',
            'conformityStatus' => 'partially_compliant',
            'statusConfirmed' => true,
            'measures' => ['quality_assurance', 'training'],
            'customMeasure' => 'Quarterly audits with users of assistive technology.',
            'remediationNote' => 'Alternative texts are being added.',
            'contactEmail' => 'accessibility@example.org',
            'phone' => '+49 30 1234567',
            'postalAddress' => "Example Organisation GmbH\nMusterstraße 1\n10115 Berlin",
            'responseTime' => '10 business days',
            'responseNote' => 'Please describe the page and the barrier.',
            'compatibleEnvironments' => 'Current Firefox, Chrome and Safari with NVDA and VoiceOver.',
            'incompatibleEnvironments' => 'Internet Explorer 11',
            'technologies' => ['html', 'wai_aria', 'css'],
            'assessmentApproach' => ['aqg_automated', 'manual_review', 'external_audit'],
            'manualReviewPerformed' => true,
            'evaluationReportUrl' => 'https://example.org/accessibility-report',
            'approvalOrganisation' => 'Example Organisation GmbH',
            'approvalPerson' => 'Erika Mustermann',
            'approvalRole' => 'Accessibility officer',
            'approvalDate' => self::daysAgo(4),
            'enforcementProcedure' => 'custom',
            'customEnforcementText' => 'Schlichtungsstelle nach § 16 BGG, Mauerstraße 53, 10117 Berlin.',
        ];
    }

    private static function daysAgo(int $days): string
    {
        return (new \DateTimeImmutable('-' . $days . ' days'))->format('Y-m-d');
    }

    private static function formatted(string $isoDate): string
    {
        return \DateTimeImmutable::createFromFormat('!Y-m-d', $isoDate)->format('d.m.Y');
    }
}
