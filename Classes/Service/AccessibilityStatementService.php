<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Pro\Exception\TokenRefreshException;
use Priebera\A11yQualityGate\Pro\Service\ProCrawlerService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AccessibilityStatementService
{
    /** @var array<string, array<string, string>> */
    private array $translationCache = [];

    public function __construct(
        private readonly ExtensionContextService $extensionContextService,
        private readonly ProCrawlerService $proCrawlerService,
        private readonly RuleMetadataPresentationService $ruleMetadataPresentationService,
        private readonly BackendLanguageService $backendLanguageService,
    ) {
    }

    /**
     * @param array<string, mixed> $draftOptions
     * @return array<string, mixed>
     */
    public function loadByJobId(string $siteBase, string $jobId, string $language = 'en', array $draftOptions = []): array
    {
        $jobId = trim($jobId);
        $language = $this->normalizeStatementLanguage($language);
        if (trim($siteBase) === '' || $jobId === '') {
            return $this->emptyStatement($this->t('statement.error.validJobId', $language));
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return $this->emptyStatement($this->t('statement.error.validDomain', $language));
            }

            $payload = $this->proCrawlerService->getAccessibilityStatement(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                jobId: $jobId,
                language: $language,
            );
        } catch (TokenRefreshException $exception) {
            $this->logStatementError('AQG accessibility statement request failed', $exception);
            return $this->emptyStatement($this->mapStatementErrorMessage($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logStatementError('AQG accessibility statement request failed unexpectedly', $exception);
            return $this->emptyStatement($this->t('statement.error.unavailable', $language));
        }

        return $this->normalizeStatementPayload($payload, $language, $draftOptions);
    }

    /**
     * @param array<string, mixed> $draftOptions
     * @return array<string, mixed>
     */
    public function loadLatest(
        string $siteBase,
        string $siteId,
        string $sourceType,
        string $startUrl = '',
        string $language = 'en',
        array $draftOptions = []
    ): array {
        $siteId = trim($siteId);
        $sourceType = strtolower(trim($sourceType));
        $startUrl = trim($startUrl);
        $language = $this->normalizeStatementLanguage($language);

        if (trim($siteBase) === '' || $siteId === '') {
            return $this->emptyStatement($this->t('statement.error.validSite', $language));
        }

        if (!in_array($sourceType, ['sitemap', 'crawl', 'single_page'], true)) {
            return $this->emptyStatement($this->t('statement.error.invalidScanType', $language));
        }

        if ($sourceType === 'single_page' && $startUrl === '') {
            return $this->emptyStatement($this->t('statement.error.pageUrlRequired', $language));
        }

        try {
            $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
            if ($domain === '') {
                return $this->emptyStatement($this->t('statement.error.validDomain', $language));
            }

            $payload = $this->proCrawlerService->getLatestAccessibilityStatement(
                domain: $domain,
                version: $this->extensionContextService->getExtensionVersion(),
                siteId: $siteId,
                sourceType: $sourceType,
                startUrl: $startUrl,
                language: $language,
            );
        } catch (TokenRefreshException $exception) {
            $this->logStatementError('AQG latest accessibility statement request failed', $exception);
            return $this->emptyStatement($this->mapStatementErrorMessage($exception->getMessage()));
        } catch (\Throwable $exception) {
            $this->logStatementError('AQG latest accessibility statement request failed unexpectedly', $exception);
            return $this->emptyStatement($this->t('statement.error.unavailable', $language));
        }

        return $this->normalizeStatementPayload($payload, $language, $draftOptions);
    }

    /**
     * @param array<string, mixed> $statement
     */
    public function buildPdfHtml(array $statement): string
    {
        $html = (string)($statement['html'] ?? '');
        if ($html === '') {
            $html = '<article class="aqg-accessibility-statement"><h1>' . $this->escape($this->t('statement.pdf.noContentTitle', 'en')) . '</h1><p>' . $this->escape($this->t('statement.pdf.noContent', 'en')) . '</p></article>';
        }

        $footer = '<htmlpagefooter name="aqgStatementFooter"><div class="aqg-pdf-footer">' . $this->escape($this->t('statement.pdf.footer', (string)($statement['language'] ?? 'en'))) . '</div></htmlpagefooter><sethtmlpagefooter name="aqgStatementFooter" value="on" />';

        return $footer . '<div class="aqg-statement-pdf">' . $html . '</div>';
    }

    public function buildPdfCss(): string
    {
        $cssPath = GeneralUtility::getFileAbsFileName(
            'EXT:a11y_quality_gate/Resources/Public/Css/pdf/statement.css'
        );

        if ($cssPath === '' || !is_file($cssPath) || !is_readable($cssPath)) {
            return '';
        }

        $css = file_get_contents($cssPath);

        return is_string($css) ? $css : '';
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyStatement(string $message): array
    {
        return [
            'available' => false,
            'message' => $message,
            'contractVersion' => '',
            'statementType' => '',
            'language' => 'en',
            'generatedAt' => 0,
            'generatedAtFormatted' => '',
            'source' => [],
            'status' => [
                'statementStatus' => '',
                'statementStatusLabel' => '',
                'statementStatusTone' => 'neutral',
            ],
            'summary' => [],
            'knownIssues' => [
                'topIssueTypes' => [],
                'affectedPagesSample' => [],
            ],
            'sourceSummaryRows' => [],
            'limitations' => [],
            'auditSupport' => [
                'notClaimed' => [],
                'manualReviewNotice' => '',
            ],
            'draftOptions' => [],
            'sections' => [],
            'hasSections' => false,
            'html' => '',
            'text' => '',
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $draftOptions
     * @return array<string, mixed>
     */
    private function normalizeStatementPayload(array $payload, string $language, array $draftOptions): array
    {
        if (isset($payload['data']) && is_array($payload['data'])) {
            $payload = $payload['data'];
        }

        $language = $this->normalizeStatementLanguage($language);
        $generatedAt = $this->parseTimestamp($payload['generatedAt'] ?? $payload['generated_at'] ?? null);
        $source = $this->normalizeSource(is_array($payload['source'] ?? null) ? $payload['source'] : []);
        $status = $this->normalizeStatus(is_array($payload['status'] ?? null) ? $payload['status'] : [], $language);
        $summary = $this->normalizeSummary(is_array($payload['summary'] ?? null) ? $payload['summary'] : []);
        $knownIssues = $this->normalizeKnownIssues(is_array($payload['knownIssues'] ?? null) ? $payload['knownIssues'] : (is_array($payload['known_issues'] ?? null) ? $payload['known_issues'] : []));
        $limitations = $this->normalizeLimitations(is_array($payload['limitations'] ?? null) ? $payload['limitations'] : []);
        $auditSupport = $this->normalizeAuditSupport(is_array($payload['auditSupport'] ?? null) ? $payload['auditSupport'] : (is_array($payload['audit_support'] ?? null) ? $payload['audit_support'] : []));
        $draftOptions = $this->normalizeDraftOptions($draftOptions, $summary, $language);

        if ($auditSupport['notClaimed'] === []) {
            $auditSupport['notClaimed'] = [$language === 'de'
                ? $this->t('statement.audit.notClaimed', 'de')
                : $this->t('statement.audit.notClaimed', 'en')];
        }
        if ($auditSupport['manualReviewNotice'] === '') {
            $auditSupport['manualReviewNotice'] = $language === 'de'
                ? $this->t('statement.audit.manualReview', 'de')
                : $this->t('statement.audit.manualReview', 'en');
        }

        $statement = [
            'available' => true,
            'message' => '',
            'contractVersion' => $this->normalizeString($payload['contractVersion'] ?? $payload['contract_version'] ?? '', 40),
            'statementType' => $this->normalizeString($payload['statementType'] ?? $payload['statement_type'] ?? '', 80),
            'language' => $language,
            'generatedAt' => $generatedAt,
            'generatedAtFormatted' => $generatedAt > 0 ? BackendTimeUtility::formatDateTime($generatedAt, 'd.m.Y H:i') : '',
            'source' => $source,
            'status' => $status,
            'summary' => $summary,
            'knownIssues' => $knownIssues,
            'sourceSummaryRows' => $this->buildSourceSummaryRows($source, $summary, $status, $generatedAt, $language),
            'limitations' => $limitations,
            'auditSupport' => $auditSupport,
            'draftOptions' => $draftOptions,
            'sections' => [],
            'hasSections' => true,
        ];

        $statement['sections'] = $this->buildDraftSections($statement);
        $statement['html'] = $this->buildSafeHtml($statement);
        $statement['text'] = $this->buildPlainText($statement);

        return $statement;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function normalizeSource(array $source): array
    {
        $scannedAt = $this->parseTimestamp($source['scannedAt'] ?? $source['scanned_at'] ?? null);

        return [
            'jobId' => $this->normalizeString($source['jobId'] ?? $source['job_id'] ?? '', 120),
            'siteId' => $this->normalizeString($source['siteId'] ?? $source['site_id'] ?? '', 80),
            'sourceType' => $this->normalizeString($source['sourceType'] ?? $source['source_type'] ?? '', 40),
            'startUrl' => $this->normalizeString($source['startUrl'] ?? $source['start_url'] ?? '', 500),
            'scannedAt' => $scannedAt,
            'scannedAtFormatted' => $scannedAt > 0 ? BackendTimeUtility::formatDateTime($scannedAt, 'd.m.Y H:i') : '',
        ];
    }

    /**
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    private function normalizeStatus(array $status, string $language): array
    {
        $statementStatus = $this->normalizeString($status['statementStatus'] ?? $status['statement_status'] ?? '', 120);

        return [
            'automatedSignal' => $this->normalizeString($status['automatedSignal'] ?? $status['automated_signal'] ?? '', 120),
            'manualReviewRequired' => (bool)($status['manualReviewRequired'] ?? $status['manual_review_required'] ?? true),
            'statementStatus' => $statementStatus,
            'statementStatusLabel' => $this->mapStatementStatusLabel($statementStatus, $language),
            'statementStatusTone' => $this->mapStatementStatusTone($statementStatus),
        ];
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function normalizeSummary(array $summary): array
    {
        return [
            'pagesScanned' => $this->normalizeNullableInt($summary['pagesScanned'] ?? $summary['pages_scanned'] ?? null),
            'issuesTotal' => $this->normalizeNullableInt($summary['issuesTotal'] ?? $summary['issues_total'] ?? null),
            'critical' => $this->normalizeNullableInt($summary['critical'] ?? null),
            'serious' => $this->normalizeNullableInt($summary['serious'] ?? null),
            'moderate' => $this->normalizeNullableInt($summary['moderate'] ?? null),
            'minor' => $this->normalizeNullableInt($summary['minor'] ?? null),
            'score' => $this->normalizeNullableInt($summary['score'] ?? null),
        ];
    }

    /**
     * @param array<string, mixed> $knownIssues
     * @return array<string, mixed>
     */
    private function normalizeKnownIssues(array $knownIssues): array
    {
        return [
            'topIssueTypes' => $this->normalizeTopIssueTypes($knownIssues['topIssueTypes'] ?? $knownIssues['top_issue_types'] ?? []),
            'affectedPagesSample' => $this->normalizeStringList($knownIssues['affectedPagesSample'] ?? $knownIssues['affected_pages_sample'] ?? [], 10, 500),
        ];
    }

    /**
     * @param array<string, mixed> $limitations
     * @return array<string, mixed>
     */
    private function normalizeLimitations(array $limitations): array
    {
        return [
            'notCovered' => $this->normalizeStringList($limitations['notCovered'] ?? $limitations['not_covered'] ?? [], 10, 240),
            'automatedChecksOnly' => (bool)($limitations['automatedChecksOnly'] ?? $limitations['automated_checks_only'] ?? true),
        ];
    }

    /**
     * @param array<string, mixed> $auditSupport
     * @return array{notClaimed:list<string>,manualReviewNotice:string}
     */
    private function normalizeAuditSupport(array $auditSupport): array
    {
        return [
            'notClaimed' => $this->normalizeStringList($auditSupport['notClaimed'] ?? $auditSupport['not_claimed'] ?? [], 12, 300),
            'manualReviewNotice' => $this->normalizeString($auditSupport['manualReviewNotice'] ?? $auditSupport['manual_review_notice'] ?? '', 900),
        ];
    }

    /**
     * @param array<string, mixed> $draftOptions
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function normalizeDraftOptions(array $draftOptions, array $summary, string $language): array
    {
        $requestedStatus = strtolower(trim((string)($draftOptions['conformityStatus'] ?? $draftOptions['status'] ?? 'not_confirmed')));
        if (!in_array($requestedStatus, ['not_confirmed', 'not_compliant', 'partially_compliant', 'mostly_compliant'], true)) {
            $requestedStatus = 'not_confirmed';
        }
        $statusConfirmed = filter_var($draftOptions['statusConfirmed'] ?? $draftOptions['conformityStatusConfirmed'] ?? false, FILTER_VALIDATE_BOOL);
        $suggestedStatus = $this->suggestConformityStatus($summary['score'] ?? null);
        $resolvedStatus = $statusConfirmed ? $requestedStatus : 'not_confirmed';

        $enforcement = strtolower(trim((string)($draftOptions['enforcementProcedure'] ?? 'generic')));
        if (!in_array($enforcement, ['none', 'generic', 'germany', 'austria', 'custom'], true)) {
            $enforcement = 'generic';
        }

        $standard = strtolower(trim((string)($draftOptions['accessibilityStandard'] ?? 'wcag22aa')));
        if (!in_array($standard, ['wcag22aa', 'wcag21aa', 'en301549', 'custom'], true)) {
            $standard = 'wcag22aa';
        }

        $organisation = $this->normalizeString($draftOptions['organisation'] ?? $draftOptions['organization'] ?? '', 240);
        $contactEmail = $this->normalizeString($draftOptions['contactEmail'] ?? '', 240);
        $websiteName = $this->normalizeString($draftOptions['websiteName'] ?? $draftOptions['serviceName'] ?? '', 240);
        $commitmentText = $this->normalizeString($draftOptions['commitmentText'] ?? '', 1600);
        if ($commitmentText === '') {
            $commitmentText = $language === 'de'
                ? $this->t('statement.default.commitment', 'de')
                : $this->t('statement.default.commitment', 'en');
        }
        $organisationPlaceholder = $this->t('statement.placeholder.organisation', $language);
        $commitmentText = str_replace(['[Organization]', '[Organisation]'], $organisation !== '' ? $organisation : $organisationPlaceholder, $commitmentText);

        $selectedMeasures = $this->normalizeStringList($draftOptions['measures'] ?? [], 12, 120);
        $selectedTechnologies = $this->normalizeStringList($draftOptions['technologies'] ?? [], 12, 120);
        $selectedAssessmentApproach = $this->normalizeStringList($draftOptions['assessmentApproach'] ?? [], 12, 120);

        $responseTime = $this->normalizeString($draftOptions['responseTime'] ?? '', 120);
        if ($responseTime === '') {
            $responseTime = $this->t('statement.default.responseTime', $language);
        }

        $remediationNote = $this->normalizeString($draftOptions['remediationNote'] ?? '', 1800);
        if ($remediationNote === '') {
            $remediationNote = $language === 'de'
                ? $this->t('statement.default.remediation', 'de')
                : $this->t('statement.default.remediation', 'en');
        }

        $compatibleEnvironments = $this->normalizeString($draftOptions['compatibleEnvironments'] ?? '', 1200);
        if ($compatibleEnvironments === '') {
            $compatibleEnvironments = $language === 'de'
                ? $this->t('statement.default.compatible', 'de')
                : $this->t('statement.default.compatible', 'en');
        }

        return [
            'language' => $language,
            'websiteName' => $websiteName,
            'organisation' => $organisation,
            'organisationPlaceholder' => $organisationPlaceholder,
            'commitmentText' => $commitmentText,
            'statementCreatedDate' => $this->normalizeString($draftOptions['statementCreatedDate'] ?? '', 120),
            'accessibilityStandard' => $standard,
            'accessibilityStandardLabel' => $this->mapAccessibilityStandardLabel($standard, $this->normalizeString($draftOptions['customAccessibilityStandard'] ?? '', 240), $language),
            'customAccessibilityStandard' => $this->normalizeString($draftOptions['customAccessibilityStandard'] ?? '', 240),
            'conformityStatus' => $requestedStatus,
            'resolvedConformityStatus' => $resolvedStatus,
            'statusConfirmed' => $statusConfirmed,
            'suggestedConformityStatus' => $suggestedStatus,
            'suggestedConformityStatusLabel' => $this->mapConformityStatusLabel($suggestedStatus, $language),
            'conformityStatusLabel' => $this->mapConformityStatusLabel($resolvedStatus, $language),
            'conformityStatusText' => $this->mapConformityStatusText($resolvedStatus, $language, $statusConfirmed),
            'conformityStatusWarning' => $language === 'de'
                ? $this->t('statement.conformance.warning', 'de')
                : $this->t('statement.conformance.warning', 'en'),
            'organisationMissing' => $organisation === '',
            'selectedMeasures' => $selectedMeasures !== [] ? $selectedMeasures : ['automated_scans', 'feedback_channel'],
            'customMeasure' => $this->normalizeString($draftOptions['customMeasure'] ?? '', 1000),
            'remediationNote' => $remediationNote,
            'contactEmail' => $contactEmail,
            'phone' => $this->normalizeString($draftOptions['phone'] ?? '', 120),
            'postalAddress' => $this->normalizeString($draftOptions['postalAddress'] ?? $draftOptions['address'] ?? '', 1000),
            'responseTime' => $responseTime,
            'responseNote' => $this->normalizeString($draftOptions['responseNote'] ?? $draftOptions['additionalContactNote'] ?? '', 1000),
            'contactMissing' => $organisation === '' || $contactEmail === '',
            'compatibleEnvironments' => $compatibleEnvironments,
            'incompatibleEnvironments' => $this->normalizeString($draftOptions['incompatibleEnvironments'] ?? '', 1000),
            'selectedTechnologies' => $selectedTechnologies !== [] ? $selectedTechnologies : ['html', 'css', 'javascript'],
            'selectedAssessmentApproach' => $selectedAssessmentApproach !== [] ? $selectedAssessmentApproach : ['aqg_automated', 'axe_playwright', 'manual_required'],
            'manualReviewPerformed' => filter_var($draftOptions['manualReviewPerformed'] ?? false, FILTER_VALIDATE_BOOL) || in_array('manual_review', $selectedAssessmentApproach, true),
            'evaluationReportUrl' => $this->normalizePublicUrl($draftOptions['evaluationReportUrl'] ?? ''),
            'approvalOrganisation' => $this->normalizeString($draftOptions['approvalOrganisation'] ?? $draftOptions['approvalOrganization'] ?? '', 240),
            'approvalPerson' => $this->normalizeString($draftOptions['approvalPerson'] ?? '', 180),
            'approvalRole' => $this->normalizeString($draftOptions['approvalRole'] ?? '', 180),
            'approvalDate' => $this->normalizeString($draftOptions['approvalDate'] ?? '', 120),
            'enforcementProcedure' => $enforcement,
            'customEnforcementText' => $this->normalizeString($draftOptions['customEnforcementText'] ?? '', 2000),
        ];
    }

    private function suggestConformityStatus(mixed $score): string
    {
        $score = $this->normalizeNullableInt($score);
        if ($score === null) {
            return 'not_confirmed';
        }
        if ($score <= 30) {
            return 'not_compliant';
        }
        if ($score <= 70) {
            return 'partially_compliant';
        }
        return 'mostly_compliant';
    }

    /**
     * @param array<string, mixed> $statement
     * @return list<array{key:string,heading:string,body:string,list?:list<string>,definitionRows?:list<array{label:string,value:string}>,warning?:string}>
     */
    private function buildDraftSections(array $statement): array
    {
        $language = (string)($statement['language'] ?? 'en');
        $source = is_array($statement['source'] ?? null) ? $statement['source'] : [];
        $summary = is_array($statement['summary'] ?? null) ? $statement['summary'] : [];
        $knownIssues = is_array($statement['knownIssues'] ?? null) ? $statement['knownIssues'] : [];
        $draftOptions = is_array($statement['draftOptions'] ?? null) ? $statement['draftOptions'] : [];
        $url = $this->normalizeString($source['startUrl'] ?? $source['siteId'] ?? '', 500);
        $sourceType = (string)($source['sourceType'] ?? '');
        $isSinglePage = $sourceType === 'single_page';
        $websiteName = $this->resolveWebsiteName($draftOptions, $source, $language);
        $organisation = (string)($draftOptions['organisation'] ?? '');
        $organisationLabel = $organisation !== '' ? $organisation : (string)($draftOptions['organisationPlaceholder'] ?? $this->t('statement.placeholder.organisation', $language));

        $sections = [];
        $sections[] = [
            'key' => 'commitment',
            'heading' => $this->t('statement.section.commitment', $language),
            'body' => (string)($draftOptions['commitmentText'] ?? ''),
            'warning' => !empty($draftOptions['organisationMissing'])
                ? ($this->t('statement.warning.organizationMissing', $language))
                : '',
        ];

        $sections[] = [
            'key' => 'measures',
            'heading' => $this->t('statement.section.measures', $language),
            'body' => $this->t('statement.body.measures', $language, ['organisation' => $organisationLabel, 'website' => $websiteName]),
            'list' => $this->buildMeasuresList($draftOptions, $language),
        ];

        $sections[] = [
            'key' => 'scope',
            'heading' => $this->t('statement.section.scope', $language),
            'body' => $this->buildScopeText($language, $url, $isSinglePage),
        ];

        $sections[] = [
            'key' => 'accessibility_standard',
            'heading' => $this->t('statement.section.standard', $language),
            'body' => $this->t('statement.body.standard', $language, ['standard' => (string)($draftOptions['accessibilityStandardLabel'] ?? $this->t('settings.statement.standard.wcag22aa', $language))]),
        ];

        $conformityWarning = (string)($draftOptions['conformityStatusWarning'] ?? '');
        $resolvedConformityStatus = (string)($draftOptions['resolvedConformityStatus'] ?? 'not_confirmed');
        $suggestedConformityStatus = (string)($draftOptions['suggestedConformityStatus'] ?? 'not_confirmed');
        if (
            !empty($draftOptions['statusConfirmed'])
            && $resolvedConformityStatus !== 'not_confirmed'
            && $suggestedConformityStatus !== 'not_confirmed'
            && $resolvedConformityStatus !== $suggestedConformityStatus
        ) {
            $conformityWarning = trim($conformityWarning . "\n\n" . $this->t('statement.warning.statusDiffers', $language));
        }

        $sections[] = [
            'key' => 'conformance_status',
            'heading' => $this->t('statement.section.conformance', $language),
            'body' => (string)($draftOptions['conformityStatusText'] ?? ''),
            'definitionRows' => $this->buildConformityStatusRows($draftOptions, $language),
            'warning' => $conformityWarning,
        ];

        $topIssueLines = $this->formatTopIssueLines($knownIssues['topIssueTypes'] ?? [], $language);
        $sections[] = [
            'key' => 'known_limitations',
            'heading' => $this->t('statement.section.knownLimitations', $language),
            'body' => $this->t('statement.body.knownLimitations', $language),
            'list' => $topIssueLines !== [] ? $topIssueLines : [$this->t('statement.body.noTopIssues', $language)],
            'warning' => $this->t('statement.body.knownLimitationsWarning', $language),
        ];

        $sections[] = [
            'key' => 'alternatives_remediation',
            'heading' => $this->t('statement.section.remediation', $language),
            'body' => (string)($draftOptions['remediationNote'] ?? ''),
        ];

        $sections[] = [
            'key' => 'feedback',
            'heading' => $this->t('statement.section.feedback', $language),
            'body' => $this->t('statement.body.feedback', $language, ['website' => $websiteName]),
            'definitionRows' => $this->buildContactRows($draftOptions, $language),
            'warning' => !empty($draftOptions['contactMissing'])
                ? ($this->t('statement.warning.contactMissing', $language))
                : '',
        ];

        $sections[] = [
            'key' => 'compatibility',
            'heading' => $this->t('statement.section.compatibility', $language),
            'body' => (string)($draftOptions['compatibleEnvironments'] ?? ''),
            'warning' => (string)($draftOptions['incompatibleEnvironments'] ?? ''),
        ];

        $sections[] = [
            'key' => 'technical_specifications',
            'heading' => $this->t('statement.section.technicalSpecifications', $language),
            'body' => $this->t('statement.body.technicalSpecifications', $language, ['website' => $websiteName]),
            'list' => $this->buildTechnologyList($draftOptions, $language),
        ];

        $sections[] = [
            'key' => 'assessment_approach',
            'heading' => $this->t('statement.section.assessment', $language),
            'body' => $this->t('statement.body.assessment', $language, ['website' => $websiteName]),
            'list' => $this->buildAssessmentList($draftOptions, $language),
        ];

        if ((string)($draftOptions['evaluationReportUrl'] ?? '') !== '') {
            $sections[] = [
                'key' => 'evaluation_report',
                'heading' => $this->t('statement.section.evaluationReport', $language),
                'body' => $this->t('statement.body.evaluationReport', $language, ['url' => (string)($draftOptions['evaluationReportUrl'] ?? '')]),
            ];
        }

        $approvalRows = $this->buildFormalApprovalRows($draftOptions, $language);
        if ($approvalRows !== []) {
            $sections[] = [
                'key' => 'formal_approval',
                'heading' => $this->t('statement.section.formalApproval', $language),
                'body' => $this->t('statement.body.formalApproval', $language),
                'definitionRows' => $approvalRows,
            ];
        }

        $sections[] = [
            'key' => 'enforcement',
            'heading' => $this->mapEnforcementHeading((string)($draftOptions['enforcementProcedure'] ?? 'generic'), $language),
            'body' => $this->buildEnforcementText($draftOptions, $language),
        ];

        $sections[] = [
            'key' => 'technical_scan_summary',
            'heading' => $this->t('statement.section.technicalScanSummary', $language),
            'body' => '',
            'definitionRows' => $this->buildTechnicalRows($statement, $language),
        ];

        $sections[] = [
            'key' => 'limitations',
            'heading' => $this->t('statement.section.limitations', $language),
            'body' => $this->t('statement.body.limitations', $language),
        ];

        $sections[] = [
            'key' => 'created_by',
            'heading' => $this->t('statement.section.createdBy', $language),
            'body' => $this->t('statement.body.createdBy', $language),
        ];

        return $sections;
    }

    private function buildScopeText(string $language, string $url, bool $isSinglePage): string
    {
        $url = $url !== '' ? $url : $this->t('statement.placeholder.websitePublication', $language);

        return $this->t($isSinglePage ? 'statement.scope.single' : 'statement.scope.site', $language, ['url' => $url]);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{label:string,value:string}>
     */
    private function buildConformityStatusRows(array $options, string $language): array
    {
        $rows = [];
        $this->appendDefinitionRow(
            $rows,
            $this->t('statement.conformance.row.selectedDraftStatus', $language),
            (string)($options['conformityStatusLabel'] ?? '')
        );
        $this->appendDefinitionRow(
            $rows,
            $this->t('statement.conformance.row.suggestion', $language),
            (string)($options['suggestedConformityStatusLabel'] ?? '')
        );
        $this->appendDefinitionRow(
            $rows,
            $this->t('statement.conformance.row.confirmed', $language),
            !empty($options['statusConfirmed']) ? ($this->t('statement.yes', $language)) : ($this->t('statement.conformance.row.noSafeDefault', $language))
        );

        return $rows;
    }

    private function buildContactIntro(string $language): string
    {
        return $this->t('statement.contact.intro', $language);
    }

    /**
     * @param array<string, mixed> $options
     * @return list<array{label:string,value:string}>
     */
    private function buildContactRows(array $options, string $language): array
    {
        $placeholder = $this->t('statement.placeholder.accessibilityContact', $language);
        $rows = [];
        $this->appendDefinitionRow($rows, $this->t('statement.contact.responsibleUnit', $language), (string)($options['organisation'] ?? '') ?: $placeholder);
        $this->appendDefinitionRow($rows, $this->t('statement.contact.email', $language), (string)($options['contactEmail'] ?? '') ?: $placeholder);
        $this->appendDefinitionRow($rows, $this->t('statement.contact.phone', $language), (string)($options['phone'] ?? ''));
        $this->appendDefinitionRow($rows, $this->t('statement.contact.postalAddress', $language), (string)($options['postalAddress'] ?? ''));
        $this->appendDefinitionRow($rows, $this->t('statement.contact.responseTime', $language), (string)($options['responseTime'] ?? ''));
        $responseNote = (string)($options['responseNote'] ?? '');
        if ($responseNote !== '') {
            $this->appendDefinitionRow($rows, $this->t('statement.contact.additionalNote', $language), $responseNote);
        }
        return $rows;
    }

    /**
     * @param array<string, mixed> $options
     */
    private function buildEnforcementText(array $options, string $language): string
    {
        $procedure = (string)($options['enforcementProcedure'] ?? 'generic');
        if ($procedure === 'none') {
            return $this->t('statement.enforcement.noneText', $language);
        }
        if ($procedure === 'custom') {
            $custom = trim((string)($options['customEnforcementText'] ?? ''));
            if ($custom !== '') {
                return $custom;
            }
            return $this->t('statement.enforcement.customPlaceholder', $language);
        }
        if ($procedure === 'germany') {
            return $this->t('statement.enforcement.germanyText', $language);
        }
        if ($procedure === 'austria') {
            return $this->t('statement.enforcement.austriaText', $language);
        }

        return $this->t('statement.enforcement.genericText', $language);
    }

    private function mapEnforcementHeading(string $procedure, string $language): string
    {
        if ($procedure === 'germany') {
            return $this->t('statement.enforcement.heading.germany', $language);
        }

        return $this->t('statement.enforcement.heading.default', $language);
    }

    /**
     * @param array<string, mixed> $statement
     * @return list<array{label:string,value:string}>
     */
    private function buildTechnicalRows(array $statement, string $language): array
    {
        $source = is_array($statement['source'] ?? null) ? $statement['source'] : [];
        $summary = is_array($statement['summary'] ?? null) ? $statement['summary'] : [];
        $generatedAt = (int)($statement['generatedAt'] ?? 0);
        $rows = [];
        $this->appendDefinitionRow($rows, $this->t('statement.technical.testingMethod', $language), $this->t('statement.technical.testingMethodValue', $language));
        $this->appendDefinitionRow($rows, $this->t('statement.technical.testingEngine', $language), 'axe-core / Playwright');
        $this->appendDefinitionRow($rows, $this->t('statement.technical.scanScope', $language), $this->mapSourceTypeLabel((string)($source['sourceType'] ?? ''), $language));
        $this->appendDefinitionRow($rows, $this->t('statement.technical.checkedPages', $language), $this->formatNullableNumber($summary['pagesScanned'] ?? null));
        $this->appendDefinitionRow($rows, $this->t('statement.technical.automatedFindings', $language), $this->formatAutomatedFindingsSummary($summary, $language));
        $this->appendDefinitionRow($rows, $this->t('statement.technical.automatedSignal', $language), $this->formatScore($summary['score'] ?? null));
        $this->appendDefinitionRow($rows, $this->t('statement.meta.scanned', $language), $this->normalizeString($source['scannedAtFormatted'] ?? '', 80));
        $this->appendDefinitionRow($rows, $this->t('statement.meta.generated', $language), $generatedAt > 0 ? BackendTimeUtility::formatDateTime($generatedAt, 'd.m.Y H:i') : '');

        return $rows;
    }

    private function resolveWebsiteName(array $options, array $source, string $language): string
    {
        $name = $this->normalizeString($options['websiteName'] ?? '', 240);
        if ($name !== '') {
            return $name;
        }
        $url = $this->normalizeString($source['startUrl'] ?? '', 500);
        if ($url !== '') {
            $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
            return $host !== '' ? $host : $url;
        }
        $siteId = $this->normalizeString($source['siteId'] ?? '', 120);
        if ($siteId !== '') {
            return $siteId;
        }
        return $this->t('statement.placeholder.website', $language);
    }

    private function mapAccessibilityStandardLabel(string $standard, string $custom, string $language): string
    {
        return match ($standard) {
            'wcag21aa' => $this->t('settings.statement.standard.wcag21aa', $language),
            'en301549' => $this->t('settings.statement.standard.en301549', $language),
            'custom' => $custom !== '' ? $custom : $this->t('settings.statement.standard.customLabel', $language),
            default => $this->t('settings.statement.standard.wcag22aa', $language),
        };
    }

    private function normalizePublicUrl(mixed $value): string
    {
        $url = $this->normalizeString($value, 600);
        if ($url === '') {
            return '';
        }
        $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
        return in_array($scheme, ['http', 'https'], true) ? $url : '';
    }

    /**
     * @return list<string>
     */
    private function buildMeasuresList(array $options, string $language): array
    {
        $selected = $this->normalizeStringList($options['selectedMeasures'] ?? [], 12, 120);
        $labels = [
            'quality_assurance' => $this->t('settings.statement.measure.qualityAssurance', $language),
            'training' => $this->t('settings.statement.measure.training', $language),
            'release_checks' => $this->t('settings.statement.measure.releaseChecks', $language),
            'automated_scans' => $this->t('settings.statement.measure.automatedScans', $language),
            'manual_reviews' => $this->t('settings.statement.measure.manualReviews', $language),
            'feedback_channel' => $this->t('settings.statement.measure.feedbackChannel', $language),
        ];
        $items = [];
        foreach ($selected as $key) {
            if (isset($labels[$key])) {
                $items[] = $labels[$key];
            }
        }
        $custom = $this->normalizeString($options['customMeasure'] ?? '', 1000);
        if ($custom !== '') {
            $items[] = $custom;
        }
        return $items !== [] ? $items : [$labels['automated_scans'], $labels['feedback_channel']];
    }

    /**
     * @return list<string>
     */
    private function buildTechnologyList(array $options, string $language): array
    {
        $selected = $this->normalizeStringList($options['selectedTechnologies'] ?? [], 12, 120);
        $labels = [
            'html' => $this->t('settings.statement.tech.html', $language),
            'wai_aria' => $this->t('settings.statement.tech.waiAria', $language),
            'css' => $this->t('settings.statement.tech.css', $language),
            'javascript' => $this->t('settings.statement.tech.javascript', $language),
            'pdf' => $this->t('settings.statement.tech.pdf', $language),
            'media' => $this->t('settings.statement.tech.media', $language),
            'third_party' => $this->t('settings.statement.tech.thirdParty', $language),
        ];
        $items = [];
        foreach ($selected as $key) {
            if (isset($labels[$key])) {
                $items[] = $labels[$key];
            }
        }
        return $items !== [] ? $items : [$labels['html'], $labels['css'], $labels['javascript']];
    }

    /**
     * @return list<string>
     */
    private function buildAssessmentList(array $options, string $language): array
    {
        $selected = is_array($options['selectedAssessmentApproach'] ?? null) ? $options['selectedAssessmentApproach'] : [];
        $labels = [
            'aqg_automated' => $this->t('settings.statement.assessment.aqg', $language),
            'axe_playwright' => $this->t('settings.statement.assessment.axe', $language),
            'manual_required' => $this->t('settings.statement.assessment.manualRequired', $language),
            'manual_review' => $this->t('settings.statement.assessment.manualReview', $language),
            'external_audit' => $this->t('settings.statement.assessment.externalAudit', $language),
        ];

        $items = [];
        foreach ($selected as $key) {
            $key = (string)$key;
            if (isset($labels[$key])) {
                $items[] = $labels[$key];
            }
        }

        return $items !== [] ? $items : [
            $labels['aqg_automated'],
            $labels['axe_playwright'],
            $labels['manual_required'],
        ];
    }

    /**
     * @return list<array{label:string,value:string}>
     */
    private function buildFormalApprovalRows(array $options, string $language): array
    {
        $rows = [];
        $this->appendDefinitionRow($rows, $this->t('statement.contact.responsibleUnit', $language), (string)($options['approvalOrganisation'] ?? ''));
        $this->appendDefinitionRow($rows, $this->t('statement.label.name', $language), (string)($options['approvalPerson'] ?? ''));
        $this->appendDefinitionRow($rows, $this->t('settings.statement.approval.role', $language), (string)($options['approvalRole'] ?? ''));
        $this->appendDefinitionRow($rows, $this->t('settings.statement.approval.date', $language), (string)($options['approvalDate'] ?? ''));
        return $rows;
    }

    /**
     * @param array<string, mixed> $statement
     */
    private function buildSafeHtml(array $statement): string
    {
        $language = (string)($statement['language'] ?? 'en');
        $source = is_array($statement['source'] ?? null) ? $statement['source'] : [];
        $draftOptions = is_array($statement['draftOptions'] ?? null) ? $statement['draftOptions'] : [];
        $websiteName = $this->resolveWebsiteName($draftOptions, $source, $language);
        $title = $this->t('statement.title', $language, ['website' => $websiteName]);
        $status = is_array($statement['status'] ?? null) ? $statement['status'] : [];
        $generatedAtFormatted = (string)($statement['generatedAtFormatted'] ?? '');
        $website = $this->normalizeString($source['startUrl'] ?? $source['siteId'] ?? '', 500);
        $scannedAt = $this->normalizeString($source['scannedAtFormatted'] ?? '', 80);

        $parts = [];
        $parts[] = '<article class="aqg-accessibility-statement">';
        $parts[] = '<h1>' . $this->escape($title) . '</h1>';
        $parts[] = '<p class="aqg-statement-lead">' . $this->escape($this->t('statement.lead', $language)) . '</p>';
        $parts[] = '<section class="aqg-statement-meta">';
        $parts[] = '<dl>';
        $this->appendHtmlDefinition($parts, $this->t('statement.label.website', $language), $website);
        $this->appendHtmlDefinition($parts, $this->t('statement.meta.generated', $language), $generatedAtFormatted);
        $this->appendHtmlDefinition($parts, $this->t('statement.meta.scanned', $language), $scannedAt);
        $this->appendHtmlDefinition($parts, $this->t('statement.label.status', $language), (string)($status['statementStatusLabel'] ?? ''));
        $parts[] = '</dl>';
        $parts[] = '</section>';
        $parts[] = '<div class="aqg-warning-banner"><span class="aqg-statement-status-badge">' . $this->escape((string)($status['statementStatusLabel'] ?? '')) . '</span><p>' . $this->escape($this->t('statement.lead', $language)) . '</p></div>';

        foreach (($statement['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $parts[] = '<section class="aqg-statement-section aqg-statement-section--' . $this->escape((string)($section['key'] ?? 'section')) . '">';
            $parts[] = '<h2>' . $this->escape((string)($section['heading'] ?? '')) . '</h2>';
            $body = trim((string)($section['body'] ?? ''));
            if ($body !== '') {
                $parts[] = '<p>' . nl2br($this->escape($body), false) . '</p>';
            }
            $list = is_array($section['list'] ?? null) ? $section['list'] : [];
            if ($list !== []) {
                $parts[] = '<ul>';
                foreach ($list as $item) {
                    $item = $this->normalizeString($item, 500);
                    if ($item !== '') {
                        $parts[] = '<li>' . $this->escape($item) . '</li>';
                    }
                }
                $parts[] = '</ul>';
            }
            $definitionRows = is_array($section['definitionRows'] ?? null) ? $section['definitionRows'] : [];
            if ($definitionRows !== []) {
                $parts[] = '<dl>';
                foreach ($definitionRows as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $this->appendHtmlDefinition($parts, (string)($row['label'] ?? ''), (string)($row['value'] ?? ''));
                }
                $parts[] = '</dl>';
            }
            $warning = trim((string)($section['warning'] ?? ''));
            if ($warning !== '') {
                $parts[] = '<p class="aqg-statement-note">' . nl2br($this->escape($warning), false) . '</p>';
            }
            $parts[] = '</section>';
        }

        $auditSupport = is_array($statement['auditSupport'] ?? null) ? $statement['auditSupport'] : [];
        $notClaimed = $this->normalizeStringList($auditSupport['notClaimed'] ?? [], 12, 300);
        $manualReviewNotice = $this->normalizeString($auditSupport['manualReviewNotice'] ?? '', 900);
        if ($notClaimed !== [] || $manualReviewNotice !== '') {
            $parts[] = '<section class="aqg-statement-section aqg-statement-section--audit">';
            $parts[] = '<h2>' . $this->escape($this->t('statement.audit.heading', $language)) . '</h2>';
            if ($notClaimed !== []) {
                $parts[] = '<ul>';
                foreach ($notClaimed as $claim) {
                    $parts[] = '<li>' . $this->escape($claim) . '</li>';
                }
                $parts[] = '</ul>';
            }
            if ($manualReviewNotice !== '') {
                $parts[] = '<p>' . nl2br($this->escape($manualReviewNotice), false) . '</p>';
            }
            $parts[] = '</section>';
        }

        $parts[] = '</article>';

        return implode("\n", $parts);
    }

    /**
     * @param array<string, mixed> $statement
     */
    private function buildPlainText(array $statement): string
    {
        $language = (string)($statement['language'] ?? 'en');
        $source = is_array($statement['source'] ?? null) ? $statement['source'] : [];
        $draftOptions = is_array($statement['draftOptions'] ?? null) ? $statement['draftOptions'] : [];
        $websiteName = $this->resolveWebsiteName($draftOptions, $source, $language);
        $title = $this->t('statement.title', $language, ['website' => $websiteName]);
        $status = is_array($statement['status'] ?? null) ? $statement['status'] : [];
        $lines = [$title, ''];
        $this->appendTextLine($lines, $this->t('statement.label.website', $language), $this->normalizeString($source['startUrl'] ?? $source['siteId'] ?? '', 500));
        $this->appendTextLine($lines, $this->t('statement.meta.generated', $language), (string)($statement['generatedAtFormatted'] ?? ''));
        $this->appendTextLine($lines, $this->t('statement.meta.scanned', $language), $this->normalizeString($source['scannedAtFormatted'] ?? '', 80));
        $this->appendTextLine($lines, $this->t('statement.label.status', $language), (string)($status['statementStatusLabel'] ?? ''));
        $lines[] = '';

        foreach (($statement['sections'] ?? []) as $section) {
            if (!is_array($section)) {
                continue;
            }
            $heading = trim((string)($section['heading'] ?? ''));
            $body = trim((string)($section['body'] ?? ''));
            if ($heading !== '') {
                $lines[] = $heading;
            }
            if ($body !== '') {
                $lines[] = $body;
            }
            foreach ((is_array($section['list'] ?? null) ? $section['list'] : []) as $item) {
                $item = $this->normalizeString($item, 500);
                if ($item !== '') {
                    $lines[] = '- ' . $item;
                }
            }
            foreach ((is_array($section['definitionRows'] ?? null) ? $section['definitionRows'] : []) as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $this->appendTextLine($lines, (string)($row['label'] ?? ''), (string)($row['value'] ?? ''));
            }
            $warning = trim((string)($section['warning'] ?? ''));
            if ($warning !== '') {
                $lines[] = $warning;
            }
            $lines[] = '';
        }

        $auditSupport = is_array($statement['auditSupport'] ?? null) ? $statement['auditSupport'] : [];
        $notClaimed = $this->normalizeStringList($auditSupport['notClaimed'] ?? [], 12, 300);
        $manualReviewNotice = $this->normalizeString($auditSupport['manualReviewNotice'] ?? '', 900);
        if ($notClaimed !== [] || $manualReviewNotice !== '') {
            $lines[] = $this->t('statement.audit.heading', $language);
            foreach ($notClaimed as $claim) {
                $lines[] = '- ' . $claim;
            }
            if ($manualReviewNotice !== '') {
                $lines[] = $manualReviewNotice;
            }
        }

        return trim(implode("\n", $lines)) . "\n";
    }

    private function normalizeStatementLanguage(string $language): string
    {
        $language = strtolower(trim($language));

        return in_array($language, ['en', 'de'], true) ? $language : 'en';
    }

    private function mapStatementStatusLabel(string $statementStatus, string $language): string
    {
        return match ($statementStatus) {
            'draft_issues_found' => $this->t('statement.status.draftIssuesFound', $language),
            'draft_no_issues_found' => $this->t('statement.status.draftNoIssuesFound', $language),
            'scan_failed_or_incomplete' => $this->t('statement.status.scanIncomplete', $language),
            'no_scan_available' => $this->t('statement.status.noScan', $language),
            default => $this->t('statement.status.requiresReview', $language),
        };
    }

    private function mapStatementStatusTone(string $statementStatus): string
    {
        return match ($statementStatus) {
            'draft_no_issues_found' => 'neutral',
            'no_scan_available', 'scan_failed_or_incomplete' => 'muted',
            default => 'warning',
        };
    }

    private function mapConformityStatusLabel(string $status, string $language): string
    {
        return match ($status) {
            'not_compliant' => $this->t('statement.conformance.label.notCompliant', $language),
            'partially_compliant' => $this->t('statement.conformance.label.partiallyCompliant', $language),
            'mostly_compliant' => $this->t('statement.conformance.label.mostlyCompliant', $language),
            default => $this->t('statement.conformance.label.notConfirmed', $language),
        };
    }

    private function mapConformityStatusText(string $status, string $language, bool $statusConfirmed): string
    {
        if (!$statusConfirmed || $status === 'not_confirmed') {
            return $this->t('statement.conformance.text.safe', $language);
        }

        return match ($status) {
            'not_compliant' => $this->t('statement.conformance.text.notCompliant', $language),
            'partially_compliant' => $this->t('statement.conformance.text.partiallyCompliant', $language),
            'mostly_compliant' => $this->t('statement.conformance.text.mostlyCompliant', $language),
            default => $this->t('statement.conformance.text.safe', $language),
        };
    }

    /**
     * @return list<array{label:string,value:string}>
     */
    private function buildSourceSummaryRows(array $source, array $summary, array $status, int $generatedAt, string $language): array
    {
        $rows = [];
        $startUrl = $this->normalizeString($source['startUrl'] ?? '', 500);
        $siteId = $this->normalizeString($source['siteId'] ?? '', 80);
        $sourceType = $this->normalizeString($source['sourceType'] ?? '', 40);

        $this->appendSummaryRow($rows, $sourceType === 'single_page' ? $this->t('statement.source.websitePage', $language) : $this->t('statement.label.website', $language), $startUrl !== '' ? $startUrl : $siteId);
        $this->appendSummaryRow($rows, $this->t('statement.technical.scanScope', $language), $this->mapSourceTypeLabel($sourceType, $language));
        $this->appendSummaryRow($rows, $this->t('statement.source.scanDate', $language), $this->normalizeString($source['scannedAtFormatted'] ?? '', 80));
        $this->appendSummaryRow($rows, $this->t('statement.source.generated', $language), $generatedAt > 0 ? BackendTimeUtility::formatDateTime($generatedAt, 'd.m.Y H:i') : '');
        $this->appendSummaryRow($rows, $this->t('statement.source.scannedPages', $language), $this->formatNullableNumber($summary['pagesScanned'] ?? null));
        $this->appendSummaryRow($rows, $this->t('statement.technical.automatedFindings', $language), $this->formatAutomatedFindingsSummary($summary, $language));
        $this->appendSummaryRow($rows, $this->t('statement.technical.automatedSignal', $language), $this->formatScore($summary['score'] ?? null));
        $this->appendSummaryRow($rows, $this->t('statement.label.status', $language), $this->normalizeString($status['statementStatusLabel'] ?? '', 160));

        return $rows;
    }

    /**
     * @param list<array{label:string,value:string}> $rows
     */
    private function appendSummaryRow(array &$rows, string $label, string $value): void
    {
        $this->appendDefinitionRow($rows, $label, $value);
    }

    /**
     * @param list<array{label:string,value:string}> $rows
     */
    private function appendDefinitionRow(array &$rows, string $label, string $value): void
    {
        $label = trim($label);
        $value = trim($value);
        if ($label === '' || $value === '') {
            return;
        }

        $rows[] = [
            'label' => $label,
            'value' => $value,
        ];
    }

    /**
     * @param list<string> $parts
     */
    private function appendHtmlDefinition(array &$parts, string $label, string $value): void
    {
        $label = trim($label);
        $value = trim($value);
        if ($label === '' || $value === '') {
            return;
        }
        $parts[] = '<dt>' . $this->escape($label) . '</dt>';
        $parts[] = '<dd>' . nl2br($this->escape($value), false) . '</dd>';
    }

    /**
     * @param list<string> $lines
     */
    private function appendTextLine(array &$lines, string $label, string $value): void
    {
        $label = trim($label);
        $value = trim($value);
        if ($label === '' || $value === '') {
            return;
        }
        $lines[] = $label . ': ' . $value;
    }

    private function mapSourceTypeLabel(string $sourceType, string $language = 'en'): string
    {
        return match ($sourceType) {
            'single_page' => $this->t('statement.source.singlePage', $language),
            'sitemap' => $this->t('statement.source.siteScan', $language),
            'crawl' => $this->t('statement.source.siteCrawl', $language),
            default => $sourceType !== '' ? $sourceType : '',
        };
    }

    private function formatNullableNumber(mixed $value): string
    {
        $number = $this->normalizeNullableInt($value);

        return $number === null ? '' : (string)$number;
    }

    private function formatScore(mixed $value): string
    {
        $score = $this->normalizeNullableInt($value);

        return $score === null ? '' : $score . ' / 100';
    }

    /**
     * @param array<string, mixed> $summary
     */
    private function formatAutomatedFindingsSummary(array $summary, string $language): string
    {
        $total = $this->normalizeNullableInt($summary['issuesTotal'] ?? null);
        $parts = [];
        foreach ([
            'critical' => $this->t('statement.findings.critical', $language),
            'serious' => $this->t('statement.findings.serious', $language),
            'moderate' => $this->t('statement.findings.moderate', $language),
            'minor' => $this->t('statement.findings.minor', $language),
        ] as $key => $label) {
            $count = $this->normalizeNullableInt($summary[$key] ?? null);
            if ($count !== null && $count > 0) {
                $parts[] = $count . ' ' . $label;
            }
        }

        if ($total === null) {
            return implode(', ', $parts);
        }

        $text = $total . ' ' . ($this->t('statement.findings.total', $language));
        if ($parts !== []) {
            $text .= ' - ' . implode(', ', $parts);
        }

        return $text;
    }

    /**
     * @param mixed $value
     * @return list<array{title:string,ruleId:string,count:?int}>
     */
    private function normalizeTopIssueTypes(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $title = '';
            $count = null;
            $ruleId = '';
            if (is_array($item)) {
                $title = $this->normalizeString(
                    $item['title'] ?? $item['label'] ?? $item['name'] ?? $item['ruleTitle'] ?? $item['rule_title'] ?? $item['issueType'] ?? $item['issue_type'] ?? '',
                    180
                );
                $ruleId = $this->normalizeString($item['ruleId'] ?? $item['rule_id'] ?? $item['id'] ?? '', 180);
                $count = $this->normalizeNullableInt(
                    $item['count'] ?? $item['findings'] ?? $item['findingsTotal'] ?? $item['findings_total'] ?? $item['issuesTotal'] ?? $item['issues_total'] ?? null
                );
            } else {
                $title = $this->normalizeString($item, 180);
                if (preg_match('/^(.*?)\s+[-–—]\s+(\d+)\s+(?:finding|findings|issue|issues|fundstelle|fundstellen)$/i', $title, $matches) === 1) {
                    $title = trim($matches[1]);
                    $count = (int)$matches[2];
                }
            }

            if ($title !== '' || $ruleId !== '') {
                $items[] = [
                    'title' => $title,
                    'ruleId' => $ruleId,
                    'count' => $count,
                ];
            }
            if (count($items) >= 8) {
                break;
            }
        }

        return $items;
    }

    private function friendlyIssueTitle(string $title, string $language): string
    {
        $needle = strtolower($title);
        $map = [
            'alternative text' => [$this->t('statement.issue.alt', 'en'), $this->t('statement.issue.alt', 'de')],
            'button name' => [$this->t('statement.issue.button', 'en'), $this->t('statement.issue.button', 'de')],
            'form field' => [$this->t('statement.issue.form', 'en'), $this->t('statement.issue.form', 'de')],
            'label' => [$this->t('statement.issue.form', 'en'), $this->t('statement.issue.form', 'de')],
            'iframe title' => [$this->t('statement.issue.iframe', 'en'), $this->t('statement.issue.iframe', 'de')],
            'frame title' => [$this->t('statement.issue.iframe', 'en'), $this->t('statement.issue.iframe', 'de')],
            'link name' => [$this->t('statement.issue.link', 'en'), $this->t('statement.issue.link', 'de')],
            'discernible text' => [$this->t('statement.issue.link', 'en'), $this->t('statement.issue.link', 'de')],
            'contrast' => [$this->t('statement.issue.contrast', 'en'), $this->t('statement.issue.contrast', 'de')],
            'landmark' => [$this->t('statement.issue.landmark', 'en'), $this->t('statement.issue.landmark', 'de')],
            'region' => [$this->t('statement.issue.landmark', 'en'), $this->t('statement.issue.landmark', 'de')],
            'marquee' => [$this->t('statement.issue.marquee', 'en'), $this->t('statement.issue.marquee', 'de')],
            'select' => [$this->t('statement.issue.select', 'en'), $this->t('statement.issue.select', 'de')],
            'use one main landmark' => [$this->t('statement.issue.oneMainLandmark', 'en'), $this->t('statement.issue.oneMainLandmark', 'de')],
            'one main landmark' => [$this->t('statement.issue.oneMainLandmark', 'en'), $this->t('statement.issue.oneMainLandmark', 'de')],
            'page has heading one' => [$this->t('statement.issue.headingOne', 'en'), $this->t('statement.issue.headingOne', 'de')],
            'add a meaningful h1' => [$this->t('statement.issue.headingOne', 'en'), $this->t('statement.issue.headingOne', 'de')],
            'meaningful h1' => [$this->t('statement.issue.headingOne', 'en'), $this->t('statement.issue.headingOne', 'de')],
            'target size' => [$this->t('statement.issue.targetSize', 'en'), $this->t('statement.issue.targetSize', 'de')],
            'touch target' => [$this->t('statement.issue.targetSize', 'en'), $this->t('statement.issue.targetSize', 'de')],
        ];
        foreach ($map as $key => $labels) {
            if (str_contains($needle, $key)) {
                return $language === 'de' ? $labels[1] : $labels[0];
            }
        }
        return $title;
    }

    /**
     * @param mixed $topIssueTypes
     * @return list<string>
     */
    private function formatTopIssueLines(mixed $topIssueTypes, string $language): array
    {
        if (!is_array($topIssueTypes)) {
            return [];
        }
        $lines = [];
        foreach ($topIssueTypes as $issueType) {
            if (!is_array($issueType)) {
                continue;
            }
            $ruleId = $this->normalizeString($issueType['ruleId'] ?? $issueType['rule_id'] ?? '', 180);
            $rawTitle = $this->normalizeString($issueType['title'] ?? '', 180);
            $title = $ruleId !== ''
                ? $this->ruleMetadataPresentationService->friendlyTitleForRule($ruleId, $rawTitle, $language)
                : $this->friendlyIssueTitle($rawTitle, $language);
            if ($title === '') {
                continue;
            }
            $count = $this->normalizeNullableInt($issueType['count'] ?? null);
            $line = $title;
            if ($count !== null) {
                $line .= ' - ' . $count . ' ' . ($count === 1 ? $this->t('statement.findings.finding', $language) : $this->t('statement.findings.findings', $language));
            }
            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @return list<string>
     */
    private function normalizeStringList(mixed $value, int $limit, int $maxLength): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            if (is_array($item)) {
                $item = $item['label'] ?? $item['title'] ?? $item['text'] ?? $item['url'] ?? '';
            }
            $item = $this->normalizeString($item, $maxLength);
            if ($item !== '') {
                $items[] = $item;
            }
            if (count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    private function normalizeString(mixed $value, int $maxLength): string
    {
        if (is_array($value)) {
            $value = implode(' ', array_map(static fn (mixed $item): string => is_scalar($item) ? (string)$item : '', $value));
        }
        $normalized = trim((string)$value);
        if ($normalized === '') {
            return '';
        }

        return mb_substr($normalized, 0, max(1, $maxLength));
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }

        return (int)$value;
    }

    private function parseTimestamp(mixed $value): int
    {
        if (is_int($value)) {
            return max(0, $value);
        }
        if (is_numeric($value)) {
            return max(0, (int)$value);
        }
        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            return $timestamp !== false ? $timestamp : 0;
        }

        return 0;
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function mapStatementErrorMessage(string $message): string
    {
        $normalized = strtolower($message);

        if (str_contains($normalized, '401') || str_contains($normalized, '403')) {
            return $this->t('statement.error.licenceUnavailable', 'en');
        }
        if (str_contains($normalized, 'unsupported_statement_language')) {
            return $this->t('statement.error.unsupportedLanguage', 'en');
        }
        if (str_contains($normalized, 'invalid_job_id')) {
            return $this->t('statement.error.jobNotFound', 'en');
        }
        if (str_contains($normalized, 'missing_starturl') || str_contains($normalized, 'missing_start_url')) {
            return $this->t('statement.error.pageUrlRequired', 'en');
        }
        if (str_contains($normalized, 'invalid_sourcetype') || str_contains($normalized, 'invalid_source_type')) {
            return $this->t('statement.error.invalidScanType', 'en');
        }
        if (str_contains($normalized, '404') || str_contains($normalized, 'not_found')) {
            return $this->t('statement.error.notAvailableForScan', 'en');
        }

        return $this->t('statement.error.unavailable', 'en');
    }


    /**
     * @param array<string, string|int|float> $replacements
     */
    private function t(string $key, string $language = 'en', array $replacements = []): string
    {
        $language = $this->normalizeStatementLanguage($language);
        $catalogue = $this->loadStatementTranslations($language);
        $fallbackCatalogue = $language === 'en' ? $catalogue : $this->loadStatementTranslations('en');
        $value = $catalogue[$key] ?? $fallbackCatalogue[$key] ?? $this->backendLanguageService->translate($key);
        if ($value === '' || $value === $key) {
            $value = $fallbackCatalogue[$key] ?? $key;
        }

        foreach ($replacements as $placeholder => $replacement) {
            $value = str_replace('{' . $placeholder . '}', (string)$replacement, $value);
        }

        return str_replace(['\\n', '{page}', '{pages}'], ["\n", '{PAGENO}', '{nb}'], $value);
    }

    /**
     * @return array<string, string>
     */
    /**
     * @return array<string, string>
     */
    private function loadStatementTranslations(string $language): array
    {
        $language = $this->normalizeStatementLanguage($language);
        if (isset($this->translationCache[$language])) {
            return $this->translationCache[$language];
        }

        $file = $language === 'de' ? 'de.locallang.xlf' : 'locallang.xlf';
        $path = GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Language/' . $file);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            $this->translationCache[$language] = [];
            return [];
        }

        $content = file_get_contents($path);
        if (!is_string($content) || $content === '') {
            $this->translationCache[$language] = [];
            return [];
        }

        $catalogue = [];
        if (preg_match_all('/<trans-unit\s+id="([^"]+)"[^>]*>(.*?)<\/trans-unit>/s', $content, $matches, PREG_SET_ORDER) > 0) {
            foreach ($matches as $match) {
                $id = html_entity_decode((string)$match[1], ENT_QUOTES | ENT_XML1, 'UTF-8');
                $body = (string)$match[2];
                $source = '';
                $target = '';
                if (preg_match('/<source>(.*?)<\/source>/s', $body, $sourceMatch) === 1) {
                    $source = html_entity_decode(trim(strip_tags((string)$sourceMatch[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                if (preg_match('/<target[^>]*>(.*?)<\/target>/s', $body, $targetMatch) === 1) {
                    $target = html_entity_decode(trim(strip_tags((string)$targetMatch[1])), ENT_QUOTES | ENT_XML1, 'UTF-8');
                }
                if ($id !== '') {
                    $catalogue[$id] = $language === 'de' && $target !== '' ? $target : $source;
                }
            }
        }

        $this->translationCache[$language] = $catalogue;
        return $catalogue;
    }

    private function logStatementError(string $message, \Throwable $exception): void
    {
        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning($message, [
                    'exception' => get_class($exception),
                    'message' => $exception->getMessage(),
                ]);
        } catch (\Throwable) {
            // Logging must never break the backend module.
        }
    }
}
