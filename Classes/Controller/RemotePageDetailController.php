<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Controller;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanRecoveryService;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use Priebera\A11yQualityGate\Service\RequestParameterService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Page\PageRenderer;

#[AsController]
final class RemotePageDetailController extends AbstractBackendModuleController
{
    public function __construct(
        ModuleTemplateFactory $moduleTemplateFactory,
        UriBuilder $uriBuilder,
        IconFactory $iconFactory,
        BackendContextService $backendContextService,
        SiteResolutionService $siteResolutionService,
        RequestParameterService $requestParameterService,
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly PageRenderer $pageRenderer,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly BackendJavaScriptModuleService $backendJavaScriptModuleService,
        private readonly RemoteScanRecoveryService $remoteScanRecoveryService,
        private readonly ExportUrlBuilderService $exportUrlBuilderService,
        private readonly ProStatusResolverService $proStatusResolverService,
    ) {
        parent::__construct(
            $moduleTemplateFactory,
            $uriBuilder,
            $iconFactory,
            $backendContextService,
            $siteResolutionService,
            $requestParameterService
        );
    }

    public function showAction(ServerRequestInterface $request): ResponseInterface
    {
        $moduleTemplate = $this->createModuleTemplate($request);

        $remotePageUid = (int)$this->requestParameterService->getString($request, 'remotePageUid');
        $siteIdentifier = $this->requestParameterService->getSiteIdentifier($request);
        $pageUid = $this->requestParameterService->getPageUidOrZero($request);
        $languageUid = $this->requestParameterService->getLanguageUid($request, 0);

        if ($siteIdentifier === '' && $pageUid > 0) {
            $siteIdentifier = $this->siteResolutionService->resolveSiteIdentifierForPageId($pageUid);
        }

        if ($remotePageUid <= 0 && $siteIdentifier !== '' && $pageUid > 0) {
            $latestRemotePage = $this->remoteScanRepository->findLatestPageForCompletedPageScan(
                $siteIdentifier,
                $pageUid,
                $languageUid
            );

            if (is_array($latestRemotePage) && isset($latestRemotePage['uid'])) {
                return new RedirectResponse(
                    $this->buildRouteUrl('web_a11y.remotePageDetail', [
                        'remotePageUid' => (int)$latestRemotePage['uid'],
                        'site' => $siteIdentifier,
                        'id' => $pageUid,
                        'language' => $languageUid,
                    ])
                );
            }
        }

        $backUrl = $this->buildRemoteOverviewBackUrl($request, $siteIdentifier, $pageUid, $languageUid);

        if ($remotePageUid <= 0) {
            $site = $siteIdentifier !== ''
                ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)
                : null;

            $this->backendJavaScriptModuleService->loadBackendModule(
                $this->pageRenderer,
                $site
            );

            $moduleTemplate->assignMultiple([
                'errorMessage' => $this->translate('module.remotePageDetail.error.missingRemotePageUid'),
                'remotePage' => null,
                'remoteScan' => null,
                'activeRemoteScan' => null,
                'issues' => [],
                'backUrl' => $backUrl,
                'remotePageDebugUrl' => '',
                'exportCsvUrl' => '',
                'exportPdfUrl' => '',
                'exportPdfAllowed' => false,
                'resolvedTypo3PageUid' => 0,
                'scanPageUid' => 0,
                'canScanRemotePage' => false,
                'usesSiteRootContext' => false,
                'resolvedSiteIdentifier' => $siteIdentifier,
                'remoteDetail' => [],
                'proStatus' => null,
            ]);

            return $moduleTemplate->renderResponse('RemotePageDetail/Show');
        }

        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            $site = $siteIdentifier !== ''
                ? $this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier)
                : null;

            $this->backendJavaScriptModuleService->loadBackendModule(
                $this->pageRenderer,
                $site
            );

            $moduleTemplate->assignMultiple([
                'errorMessage' => $this->translate('module.remotePageDetail.error.remotePageNotFound'),
                'remotePage' => null,
                'remoteScan' => null,
                'activeRemoteScan' => null,
                'issues' => [],
                'backUrl' => $backUrl,
                'remotePageDebugUrl' => '',
                'exportCsvUrl' => '',
                'exportPdfUrl' => '',
                'exportPdfAllowed' => false,
                'resolvedTypo3PageUid' => 0,
                'scanPageUid' => 0,
                'canScanRemotePage' => false,
                'usesSiteRootContext' => false,
                'resolvedSiteIdentifier' => $siteIdentifier,
                'remoteDetail' => [],
                'proStatus' => null,
            ]);

            return $moduleTemplate->renderResponse('RemotePageDetail/Show');
        }

        $remoteScanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $remoteScanUid > 0
            ? $this->remoteScanRepository->findScanByUid($remoteScanUid)
            : null;

        $resolvedSiteIdentifier = is_array($remoteScan)
            ? (string)($remoteScan['site_identifier'] ?? $siteIdentifier)
            : $siteIdentifier;

        $latestRemotePage = $this->remoteScanRepository->findLatestPageByUrl(
            (string)($remotePage['url'] ?? ''),
            $resolvedSiteIdentifier
        );

        if (is_array($latestRemotePage) && (int)$latestRemotePage['uid'] !== $remotePageUid) {
            return new RedirectResponse(
                $this->buildRouteUrl('web_a11y.remotePageDetail', [
                    'remotePageUid' => (int)$latestRemotePage['uid'],
                    'site' => $resolvedSiteIdentifier,
                    'id' => (int)($remoteScan['page_uid'] ?? 0),
                    'language' => (int)($remoteScan['language_uid'] ?? 0),
                ])
            );
        }

        $resolvedSite = $resolvedSiteIdentifier !== ''
            ? $this->siteResolutionService->resolveSiteByIdentifier($resolvedSiteIdentifier)
            : null;

        $this->backendJavaScriptModuleService->loadBackendModule(
            $this->pageRenderer,
            $resolvedSite
        );

        $proStatus = $this->proStatusResolverService->resolveForSiteIdentifier($resolvedSiteIdentifier);

        $remotePageDebugUrl = $this->buildRemotePageDebugUrl((string)($remotePage['url'] ?? ''));

        $remoteScreenshotProxyUrl = '';
        if (!empty($remotePage['screenshot_path']) && $remotePageUid > 0) {
            $remoteScreenshotProxyUrl = $this->buildRemoteScreenshotProxyUrl(
                $resolvedSiteIdentifier,
                $remotePageUid
            );
        }

        $issues = $this->remoteIssueRepository->findByRemoteScanPage($remotePageUid);

        $issuesWithNodes = array_map(function (array $issue) use ($resolvedSiteIdentifier, $remotePageUid): array {
            $issueUid = (int)($issue['uid'] ?? 0);
            $nodes = $issueUid > 0
                ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid)
                : [];

            $nodes = array_map(function (array $node) use ($resolvedSiteIdentifier, $remotePageUid): array {
                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);

                $node['editRecordUrl'] = '';
                $node['hasRecordMapping'] = false;
                $node['hasEditAccess'] = false;
                $node['contrastDetails'] = $this->decodeContrastDetails((string)($node['contrast_details_json'] ?? ''));
                $node['hasContrastDetails'] = $node['contrastDetails'] !== [];
                $node['nodeRemediation'] = $this->decodeNodeRemediation((string)($node['node_remediation_json'] ?? ''));
                $node['hasNodeRemediation'] = $node['nodeRemediation'] !== [];

                if ($mappedTable !== '' && $mappedUid > 0) {
                    $node['hasRecordMapping'] = true;
                    $node['hasEditAccess'] = $this->backendRecordAccessService->canEditRecord(
                        $mappedTable,
                        $mappedUid
                    );

                    if ($node['hasEditAccess']) {
                        $node['editRecordUrl'] = $this->buildEditRecordUrl(
                            $mappedTable,
                            $mappedUid,
                            $resolvedSiteIdentifier,
                            $remotePageUid
                        );
                    }
                }

                return $node;
            }, $nodes);

            return $issue + [
                    'nodes' => $nodes,
                ];
        }, $issues);

        $siteRootPid = $resolvedSite !== null ? (int)$resolvedSite->getRootPageId() : 0;
        $resolvedTypo3PageUid = $this->resolveTypo3PageUid($remoteScan, $issuesWithNodes);
        $scanPageUid = $resolvedTypo3PageUid > 0 ? $resolvedTypo3PageUid : $siteRootPid;
        $canScanRemotePage = $scanPageUid > 0;
        $usesSiteRootContext = $canScanRemotePage && $resolvedTypo3PageUid <= 0;

        $activeRemoteScan = null;
        if ($resolvedSiteIdentifier !== '') {
            if ($scanPageUid > 0) {
                $activeRemoteScan = $this->remoteScanRepository->findLatestRelevantActiveScan(
                    $resolvedSiteIdentifier,
                    $scanPageUid
                );
            }

            if (!is_array($activeRemoteScan)) {
                $activeRemoteScan = $this->remoteScanRepository->findLatestActiveSiteScanBySite(
                    $resolvedSiteIdentifier
                );
            }
        }

        if (is_array($activeRemoteScan) && $resolvedSite !== null) {
            $activeRemoteScan = $this->remoteScanRecoveryService->recoverScanIfNeeded(
                $activeRemoteScan,
                (string)$resolvedSite->getBase(),
            );
        }

        $groupedIssues = $this->groupIssuesByRule($issuesWithNodes);
        $pageRemediationSummary = $this->decodeRemediationSummary((string)($remotePage['remediation_summary_json'] ?? ''));
        $pageRecommendation = $this->decodePageRecommendation((string)($remotePage['page_recommendation_json'] ?? ''));
        $remoteDetail = $this->buildRemoteDetailViewData($remotePage, $remoteScan, $activeRemoteScan);
        $backUrl = $this->buildRemoteOverviewBackUrl(
            $request,
            $resolvedSiteIdentifier,
            (int)($remoteScan['page_uid'] ?? 0) ?: $scanPageUid,
            (int)($remoteScan['language_uid'] ?? 0)
        );

        $exportCsvUrl = $this->exportUrlBuilderService->buildRemotePageCsvUrl(
            $resolvedSiteIdentifier,
            $remotePageUid
        );

        $exportPdfUrl = $this->exportUrlBuilderService->buildRemotePagePdfUrl(
            $resolvedSiteIdentifier,
            $remotePageUid
        );

        $this->configureDocHeader($moduleTemplate, $backUrl, $remotePageDebugUrl);

        $moduleTemplate->assignMultiple([
            'errorMessage' => null,
            'remotePage' => $remotePage + [
                    'screenshot_proxy_url' => $remoteScreenshotProxyUrl,
                ],
            'remoteScan' => $remoteScan,
            'remoteDetail' => $remoteDetail,
            'activeRemoteScan' => $activeRemoteScan,
            'issues' => $groupedIssues,
            'pageRemediationSummary' => $pageRemediationSummary,
            'hasPageRemediationSummary' => $pageRemediationSummary !== [],
            'pageRecommendation' => $pageRecommendation,
            'hasPageRecommendation' => $pageRecommendation !== [],
            'backUrl' => $backUrl,
            'remotePageDebugUrl' => $remotePageDebugUrl,
            'resolvedSiteIdentifier' => $resolvedSiteIdentifier,
            'exportCsvUrl' => $exportCsvUrl,
            'exportPdfUrl' => $exportPdfUrl,
            'exportPdfAllowed' => $proStatus->valid && !$proStatus->isTrial && $proStatus->hasExportPdf,
            'resolvedTypo3PageUid' => $resolvedTypo3PageUid,
            'scanPageUid' => $scanPageUid,
            'canScanRemotePage' => $canScanRemotePage,
            'usesSiteRootContext' => $usesSiteRootContext,
            'proStatus' => $proStatus,
        ]);

        return $moduleTemplate->renderResponse('RemotePageDetail/Show');
    }

    private function buildEditRecordUrl(
        string $table,
        int $uid,
        string $siteIdentifier,
        int $remotePageUid,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoute('record_edit', [
            'edit' => [
                $table => [
                    $uid => 'edit',
                ],
            ],
            'returnUrl' => (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remotePageDetail', [
                'site' => $siteIdentifier,
                'remotePageUid' => $remotePageUid,
            ]),
        ]);
    }

    private function buildRemotePageDebugUrl(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        if (str_contains($url, 'aqgDebug=')) {
            return $url;
        }

        return $url . $separator . 'aqgDebug=1';
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array<int, array<string, mixed>>
     */
    private function groupIssuesByRule(array $issues): array
    {
        $groups = [];

        foreach ($issues as $issue) {
            $ruleId = (string)($issue['rule_id'] ?? 'unknown');

            if (!isset($groups[$ruleId])) {
                $groups[$ruleId] = [
                    'rule_id' => $ruleId,
                    'impact' => (string)($issue['impact'] ?? ''),
                    'impact_tone' => $this->resolveImpactTone((string)($issue['impact'] ?? '')),
                    'help' => (string)($issue['help'] ?? ''),
                    'help_url' => (string)($issue['help_url'] ?? ''),
                    'guidanceWhyItMatters' => $this->normalizeNullableString($issue['guidance_why_it_matters'] ?? null),
                    'guidanceHowToFix' => $this->normalizeNullableString($issue['guidance_how_to_fix'] ?? null),
                    'guidanceHasApiText' => false,
                    'guidanceIsFallback' => false,
                    'whoShouldFix' => $this->normalizeMachineValue($issue['who_should_fix'] ?? null),
                    'whoShouldFixLabel' => $this->formatBadgeLabel($this->normalizeMachineValue($issue['who_should_fix'] ?? null)),
                    'fixType' => $this->normalizeMachineValue($issue['fix_type'] ?? null),
                    'fixTypeLabel' => $this->formatBadgeLabel($this->normalizeMachineValue($issue['fix_type'] ?? null)),
                    'confidence' => $this->normalizeMachineValue($issue['confidence'] ?? null),
                    'confidenceLabel' => $this->formatBadgeLabel($this->normalizeMachineValue($issue['confidence'] ?? null)),
                    'count' => 0,
                    'nodes' => [],
                    'mappedUids' => [],
                ];
            }

            foreach ([
                'guidanceWhyItMatters' => 'guidance_why_it_matters',
                'guidanceHowToFix' => 'guidance_how_to_fix',
            ] as $targetKey => $sourceKey) {
                if ($groups[$ruleId][$targetKey] === null) {
                    $value = $this->normalizeNullableString($issue[$sourceKey] ?? null);
                    if ($value !== null) {
                        $groups[$ruleId][$targetKey] = $value;
                    }
                }
            }

            foreach (['whoShouldFix' => 'who_should_fix', 'fixType' => 'fix_type', 'confidence' => 'confidence'] as $targetKey => $sourceKey) {
                if ((string)$groups[$ruleId][$targetKey] === '') {
                    $value = $this->normalizeMachineValue($issue[$sourceKey] ?? null);
                    if ($value !== '') {
                        $groups[$ruleId][$targetKey] = $value;
                        $groups[$ruleId][$targetKey . 'Label'] = $this->formatBadgeLabel($value);
                    }
                }
            }

            $groups[$ruleId]['count'] += (int)($issue['nodes_count'] ?? 1);

            if (!empty($issue['nodes']) && is_array($issue['nodes'])) {
                foreach ($issue['nodes'] as $node) {
                    if (!is_array($node)) {
                        continue;
                    }

                    $groups[$ruleId]['nodes'][] = $node;

                    $mappedUid = (int)($node['mapped_uid'] ?? 0);
                    if ($mappedUid > 0) {
                        $groups[$ruleId]['mappedUids'][$mappedUid] = $mappedUid;
                    }
                }
            }
        }

        foreach ($groups as &$group) {
            $uids = array_values($group['mappedUids']);
            $group['highlightUids'] = $uids !== [] ? implode(',', $uids) : '';
            $group['guidanceHasApiText'] = $group['guidanceWhyItMatters'] !== null || $group['guidanceHowToFix'] !== null;
            $group['guidanceIsFallback'] = $group['guidanceHowToFix'] === null;
            $group['guidanceHowToFix'] = $group['guidanceHowToFix'] ?? 'Review this finding in context.';
            unset($group['mappedUids']);
        }
        unset($group);

        return array_values($groups);
    }


    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim((string)$value);

        return $normalized !== '' ? $normalized : null;
    }

    private function normalizeMachineValue(mixed $value): string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return '';
        }

        $normalized = strtolower($normalized);
        $normalized = preg_replace('/[^a-z0-9_-]+/', '_', $normalized) ?? '';
        $normalized = substr(trim($normalized, '_-'), 0, 50);

        if (in_array($normalized, ['unknown', 'n_a', 'na', 'none', 'not_set', 'undefined'], true)) {
            return '';
        }

        return $normalized;
    }

    private function formatBadgeLabel(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return ucwords(str_replace(['_', '-'], ' ', $value));
    }

    private function resolveImpactTone(string $impact): string
    {
        return match (strtolower(trim($impact))) {
            'critical' => 'critical',
            'serious', 'moderate' => 'warning',
            'minor' => 'info',
            default => 'none',
        };
    }

    /**
     * @param array<string, mixed> $remotePage
     * @param array<string, mixed>|null $remoteScan
     * @param array<string, mixed>|null $activeRemoteScan
     * @return array<string, mixed>
     */
    private function buildRemoteDetailViewData(array $remotePage, ?array $remoteScan, ?array $activeRemoteScan): array
    {
        $httpStatus = (int)($remotePage['http_status'] ?? 0);
        $failureReason = trim((string)($remotePage['failure_reason'] ?? ''));
        $pageMarkedFailed = (int)($remotePage['is_failed'] ?? 0) === 1;
        $scanStatus = strtolower(trim((string)($remoteScan['status'] ?? '')));
        $hasActiveScan = is_array($activeRemoteScan) && trim((string)($activeRemoteScan['job_id'] ?? '')) !== '';
        $isFailed = !$hasActiveScan && (
            $scanStatus === 'failed'
            || $pageMarkedFailed
            || ($remoteScan === null && ($failureReason !== '' || $httpStatus >= 400))
        );

        $finishedAt = (int)($remoteScan['finished_at'] ?? 0);
        $startedAt = (int)($remoteScan['started_at'] ?? 0);
        $activeStartedAt = (int)($activeRemoteScan['started_at'] ?? 0);
        $activePagesScanned = (int)($activeRemoteScan['pages_scanned'] ?? 0);
        $activePagesTotal = (int)($activeRemoteScan['pages_total'] ?? 0);

        return [
            'scanType' => 'frontend_http',
            'httpStatusLabel' => $httpStatus > 0 ? (string)$httpStatus : '—',
            'httpStatusTone' => $httpStatus > 0 && $httpStatus < 400 ? 'tone-ok' : ($httpStatus >= 500 ? 'tone-critical' : 'tone-warning'),
            'lastScanAt' => $finishedAt,
            'lastScanAtFormatted' => BackendTimeUtility::formatDateTime($finishedAt, 'd.m.Y · H:i'),
            'lastAttemptAt' => $finishedAt > 0 ? $finishedAt : $startedAt,
            'lastAttemptAtFormatted' => BackendTimeUtility::formatDateTime($finishedAt > 0 ? $finishedAt : $startedAt, 'd.m.Y · H:i'),
            'startedAt' => $startedAt,
            'startedAtFormatted' => BackendTimeUtility::formatDateTime($startedAt, 'd.m.Y H:i:s'),
            'activeStartedAt' => $activeStartedAt,
            'activeStartedAtFormatted' => BackendTimeUtility::formatDateTime($activeStartedAt, 'H:i'),
            'activePagesLabel' => $activePagesTotal > 0
                ? $activePagesScanned . ' / ' . $activePagesTotal
                : (string)$activePagesScanned,
            'durationLabel' => $this->formatDuration($startedAt, $finishedAt),
            'failureReason' => $failureReason,
            'isFailed' => $isFailed,
            'hasActiveScan' => $hasActiveScan,
            'hasCompletedScan' => $remoteScan !== null && $finishedAt > 0,
        ];
    }

    private function formatDuration(int $startedAt, int $finishedAt): string
    {
        if ($startedAt <= 0 || $finishedAt <= $startedAt) {
            return '—';
        }

        $seconds = $finishedAt - $startedAt;
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remainingSeconds = $seconds % 60;

        return sprintf('%02d:%02d:%02d', $hours, $minutes, $remainingSeconds);
    }

    /**
     * @param array<string, mixed>|null $remoteScan
     * @param array<int, array<string, mixed>> $issuesWithNodes
     */
    private function resolveTypo3PageUid(?array $remoteScan, array $issuesWithNodes): int
    {
        $scanPageUid = (int)($remoteScan['page_uid'] ?? 0);
        if ($scanPageUid > 0) {
            return $scanPageUid;
        }

        foreach ($issuesWithNodes as $issue) {
            $nodes = is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [];

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);

                if ($mappedTable === '' || $mappedUid <= 0) {
                    continue;
                }

                if ($mappedTable === 'pages') {
                    return $mappedUid;
                }

                $record = BackendUtility::getRecord($mappedTable, $mappedUid, 'pid');
                if (is_array($record) && (int)($record['pid'] ?? 0) > 0) {
                    return (int)$record['pid'];
                }
            }
        }

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePageRecommendation(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $this->normalizePageRecommendation(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $recommendation
     * @return array<string, mixed>
     */
    private function normalizePageRecommendation(array $recommendation): array
    {
        if ($recommendation === []) {
            return [];
        }

        $summary = $this->normalizeNullableString($recommendation['summary'] ?? $recommendation['shortFix'] ?? $recommendation['short_fix'] ?? null);
        $primaryRuleId = $this->normalizeNullableString($recommendation['primaryRuleId'] ?? $recommendation['primary_rule_id'] ?? null);
        $primaryFixType = $this->normalizeMachineValue($recommendation['primaryFixType'] ?? $recommendation['primary_fix_type'] ?? null);
        $primaryOwner = $this->normalizeMachineValue($recommendation['primaryOwner'] ?? $recommendation['primary_owner'] ?? null);
        $quickWinsTotal = max(0, (int)($recommendation['quickWinsTotal'] ?? $recommendation['quick_wins_total'] ?? 0));
        $templateIssuesTotal = max(0, (int)($recommendation['templateIssuesTotal'] ?? $recommendation['template_issues_total'] ?? 0));
        $contentIssuesTotal = max(0, (int)($recommendation['contentIssuesTotal'] ?? $recommendation['content_issues_total'] ?? 0));
        $designIssuesTotal = max(0, (int)($recommendation['designIssuesTotal'] ?? $recommendation['design_issues_total'] ?? 0));
        $firstSteps = $this->normalizeStringList($recommendation['firstSteps'] ?? $recommendation['first_steps'] ?? []);
        $manualReviewRequired = (bool)($recommendation['manualReviewRequired'] ?? $recommendation['manual_review_required'] ?? false);

        if ($summary === null && $primaryRuleId === null && $primaryFixType === '' && $primaryOwner === '' && $quickWinsTotal === 0 && $templateIssuesTotal === 0 && $contentIssuesTotal === 0 && $designIssuesTotal === 0 && $firstSteps === [] && !$manualReviewRequired) {
            return [];
        }

        return [
            'title' => 'Start here',
            'subtitle' => 'Suggested first step based on automated findings',
            'summary' => $summary,
            'primaryRuleId' => $primaryRuleId,
            'primaryFixType' => $primaryFixType,
            'primaryFixTypeLabel' => $this->formatBadgeLabel($primaryFixType),
            'primaryOwner' => $primaryOwner,
            'primaryOwnerLabel' => $this->formatBadgeLabel($primaryOwner),
            'quickWinsTotal' => $quickWinsTotal,
            'quickWinsLabel' => $quickWinsTotal > 0 ? $quickWinsTotal . ' ' . ($quickWinsTotal === 1 ? 'quick win' : 'quick wins') : '',
            'templateIssuesTotal' => $templateIssuesTotal,
            'templateIssuesLabel' => $templateIssuesTotal > 0 ? $templateIssuesTotal . ' ' . ($templateIssuesTotal === 1 ? 'template issue' : 'template issues') : '',
            'contentIssuesTotal' => $contentIssuesTotal,
            'contentIssuesLabel' => $contentIssuesTotal > 0 ? $contentIssuesTotal . ' ' . ($contentIssuesTotal === 1 ? 'content issue' : 'content issues') : '',
            'designIssuesTotal' => $designIssuesTotal,
            'designIssuesLabel' => $designIssuesTotal > 0 ? $designIssuesTotal . ' ' . ($designIssuesTotal === 1 ? 'design issue' : 'design issues') : '',
            'firstSteps' => $firstSteps,
            'hasFirstSteps' => $firstSteps !== [],
            'manualReviewRequired' => $manualReviewRequired,
            'manualReviewLabel' => $manualReviewRequired ? 'Manual review required' : '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRemediationSummary(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $this->normalizeRemediationSummary(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $summary
     * @return array<string, mixed>
     */
    private function normalizeRemediationSummary(array $summary): array
    {
        if ($summary === []) {
            return [];
        }

        $items = [];
        foreach ([
            'editorContentFixes' => ['label' => 'Editor/content fixes', 'keys' => ['editorContentFixes', 'editor_content_fixes', 'editorFixes', 'editor_fixes', 'contentFixes', 'content_fixes']],
            'developerTemplateFixes' => ['label' => 'Developer/template fixes', 'keys' => ['developerTemplateFixes', 'developer_template_fixes', 'developerFixes', 'developer_fixes', 'templateFixes', 'template_fixes']],
            'designFixes' => ['label' => 'Design fixes', 'keys' => ['designFixes', 'design_fixes']],
            'quickWins' => ['label' => 'Quick wins', 'keys' => ['quickWins', 'quick_wins']],
        ] as $key => $config) {
            $value = null;
            foreach ($config['keys'] as $sourceKey) {
                if (array_key_exists($sourceKey, $summary)) {
                    $value = $summary[$sourceKey];
                    break;
                }
            }

            $item = $this->normalizeRemediationSummaryItem((string)$key, (string)$config['label'], $value);
            if ($item !== null) {
                $items[] = $item;
            }
        }

        $recommendation = $this->normalizeNullableString($summary['recommendation'] ?? $summary['recommendedNextStep'] ?? $summary['recommended_next_step'] ?? null);
        $note = $this->normalizeNullableString($summary['note'] ?? $summary['disclaimer'] ?? null);

        if ($items === [] && $recommendation === null && $note === null) {
            return [];
        }

        return [
            'title' => 'Suggested remediation grouping',
            'items' => $items,
            'hasItems' => $items !== [],
            'recommendation' => $recommendation,
            'note' => $note,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeRemediationSummaryItem(string $key, string $label, mixed $value): ?array
    {
        if ($value === null || $value === '') {
            return null;
        }

        $count = null;
        $description = null;
        if (is_array($value)) {
            $count = isset($value['count']) || isset($value['total']) || isset($value['issuesTotal']) || isset($value['issues_total'])
                ? max(0, (int)($value['count'] ?? $value['total'] ?? $value['issuesTotal'] ?? $value['issues_total'] ?? 0))
                : null;
            $description = $this->normalizeNullableString($value['label'] ?? $value['description'] ?? $value['note'] ?? null);
        } elseif (is_numeric($value)) {
            $count = max(0, (int)$value);
        } else {
            $description = $this->normalizeNullableString($value);
        }

        if ($count === null && $description === null) {
            return null;
        }
        if ($count === 0 && $description === null) {
            return null;
        }

        return [
            'key' => $key,
            'label' => $label,
            'count' => $count,
            'countLabel' => $count !== null ? $count . ' ' . ($count === 1 ? 'item' : 'items') : '',
            'description' => $description,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeContrastDetails(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        if (!is_array($decoded)) {
            return [];
        }

        // Older runtime rows and some API/debug payloads can contain one
        // contrast detail object instead of a list of detail objects. Treat
        // that shape as a single item so page detail/PDF stays backwards
        // compatible and does not silently hide contrastSuggestion data.
        if ($decoded !== [] && !array_is_list($decoded)) {
            $decoded = [$decoded];
        }

        $items = [];
        foreach ($decoded as $item) {
            if (!is_array($item)) {
                continue;
            }
            $normalized = $this->normalizeContrastDetail($item);
            if ($normalized !== null) {
                $items[] = $normalized;
            }
        }

        return array_slice($items, 0, 5);
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, mixed>|null
     */
    private function normalizeContrastDetail(array $item): ?array
    {
        $suggestion = $this->normalizeContrastSuggestion($item['contrastSuggestion'] ?? $item['contrast_suggestion'] ?? []);
        $actualRatio = $this->normalizeNullableString($item['actualRatio'] ?? $item['actual_ratio'] ?? $suggestion['actualRatio'] ?? null);
        $requiredRatio = $this->normalizeNullableString($item['requiredRatio'] ?? $item['required_ratio'] ?? $suggestion['requiredRatio'] ?? null);
        $foreground = $this->normalizeColorValue($item['foreground'] ?? $item['foregroundColor'] ?? $item['foreground_color'] ?? $suggestion['currentForeground'] ?? null);
        $background = $this->normalizeColorValue($item['background'] ?? $item['backgroundColor'] ?? $item['background_color'] ?? $suggestion['currentBackground'] ?? null);
        $issuesTotal = max(0, (int)($item['issuesTotal'] ?? $item['issues_total'] ?? 0));

        if ($actualRatio === null && $requiredRatio === null && $foreground === null && $background === null && $issuesTotal === 0 && $suggestion === []) {
            return null;
        }

        return [
            'actualRatio' => $actualRatio,
            'requiredRatio' => $requiredRatio,
            'foreground' => $foreground,
            'background' => $background,
            'issuesTotal' => $issuesTotal,
            'issuesLabel' => $issuesTotal === 1 ? '1 issue' : $issuesTotal . ' issues',
            'contrastSuggestion' => $suggestion,
            'hasContrastSuggestion' => $suggestion !== [],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function normalizeContrastSuggestion(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $foregroundCandidateDetails = $this->normalizeColorCandidateDetails(
            $value['suggestedForegroundCandidateDetails']
            ?? $value['suggested_foreground_candidate_details']
            ?? $value['suggestedForegroundCandidates']
            ?? $value['suggested_foreground_candidates']
            ?? []
        );
        $backgroundCandidateDetails = $this->normalizeColorCandidateDetails(
            $value['suggestedBackgroundCandidateDetails']
            ?? $value['suggested_background_candidate_details']
            ?? $value['suggestedBackgroundCandidates']
            ?? $value['suggested_background_candidates']
            ?? []
        );
        $foregroundCandidates = array_column($foregroundCandidateDetails, 'color');
        $backgroundCandidates = array_column($backgroundCandidateDetails, 'color');
        $actualRatio = $this->normalizeNullableString($value['actualRatio'] ?? $value['actual_ratio'] ?? null);
        $requiredRatio = $this->normalizeNullableString($value['requiredRatio'] ?? $value['required_ratio'] ?? null);
        $currentForeground = $this->normalizeColorValue($value['currentForeground'] ?? $value['current_foreground'] ?? null);
        $currentBackground = $this->normalizeColorValue($value['currentBackground'] ?? $value['current_background'] ?? null);
        $note = $this->normalizeNullableString($value['note'] ?? null);
        $riskLevel = $this->normalizeMachineValue($value['riskLevel'] ?? $value['risk_level'] ?? null);
        $reviewHint = $this->normalizeNullableString($value['reviewHint'] ?? $value['review_hint'] ?? null);
        $candidateType = $this->normalizeMachineValue($value['candidateType'] ?? $value['candidate_type'] ?? null);
        $preferredCandidate = $this->normalizePreferredContrastCandidate($value);

        if ($foregroundCandidates === [] && $backgroundCandidates === [] && $preferredCandidate === null && $actualRatio === null && $requiredRatio === null && $currentForeground === null && $currentBackground === null && $note === null && $riskLevel === '' && $reviewHint === null && $candidateType === '') {
            return [];
        }

        return [
            'currentForeground' => $currentForeground,
            'currentBackground' => $currentBackground,
            'actualRatio' => $actualRatio,
            'requiredRatio' => $requiredRatio,
            'preferredCandidate' => $preferredCandidate,
            'hasPreferredCandidate' => $preferredCandidate !== null,
            'riskLevel' => $riskLevel,
            'riskLevelLabel' => $this->formatBadgeLabel($riskLevel),
            'reviewHint' => $reviewHint,
            'candidateType' => $candidateType,
            'candidateTypeLabel' => $this->formatBadgeLabel($candidateType),
            'suggestedForegroundCandidates' => $foregroundCandidates,
            'suggestedBackgroundCandidates' => $backgroundCandidates,
            'suggestedForegroundCandidateDetails' => $foregroundCandidateDetails,
            'suggestedBackgroundCandidateDetails' => $backgroundCandidateDetails,
            'hasSuggestedForegroundCandidates' => $foregroundCandidates !== [],
            'hasSuggestedBackgroundCandidates' => $backgroundCandidates !== [],
            'note' => $note ?? $reviewHint ?? 'Candidate colors are generated as an automated remediation aid and must be reviewed in the brand/design context.',
        ];
    }

    /**
     * @return array<int, array{color:string,estimatedRatio:?string,label:string}>
     */
    private function normalizeColorCandidateDetails(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        if ($value !== [] && !array_is_list($value)) {
            $value = [$value];
        }

        $items = [];
        foreach ($value as $candidate) {
            $color = null;
            $estimatedRatio = null;

            $apiLabel = null;
            $explanation = null;
            if (is_array($candidate)) {
                $color = $this->normalizeColorValue($candidate['color'] ?? $candidate['candidate'] ?? $candidate['value'] ?? $candidate['hex'] ?? null);
                $estimatedRatio = $this->normalizeNullableString($candidate['estimatedRatio'] ?? $candidate['estimated_ratio'] ?? $candidate['ratio'] ?? null);
                $apiLabel = $this->normalizeNullableString($candidate['label'] ?? null);
                $explanation = $this->normalizeNullableString($candidate['explanation'] ?? $candidate['reason'] ?? null);
            } else {
                $color = $this->normalizeColorValue($candidate);
            }

            if ($color === null) {
                continue;
            }

            $label = $apiLabel ?? ($estimatedRatio !== null ? $color . ' · estimated contrast ratio ' . $estimatedRatio : $color);
            $items[$color . '|' . ($estimatedRatio ?? '') . '|' . ($label ?? '')] = [
                'color' => $color,
                'estimatedRatio' => $estimatedRatio,
                'label' => $label,
                'explanation' => $explanation,
            'reason' => $explanation,
            ];
        }

        return array_slice(array_values($items), 0, 6);
    }

    /**
     * @param array<string, mixed> $value
     * @return array{color:string,type:string,typeLabel:string,estimatedRatio:?string,label:string}|null
     */
    private function normalizePreferredContrastCandidate(array $value): ?array
    {
        $candidate = $value['preferredCandidate'] ?? $value['preferred_candidate'] ?? null;
        $estimatedRatio = $this->normalizeNullableString(
            $value['preferredCandidateEstimatedRatio']
            ?? $value['preferred_candidate_estimated_ratio']
            ?? $value['estimatedRatio']
            ?? $value['estimated_ratio']
            ?? null
        );
        $type = '';
        $color = null;

        $apiLabel = null;
        $explanation = null;
        if (is_array($candidate)) {
            $color = $this->normalizeColorValue($candidate['color'] ?? $candidate['candidate'] ?? $candidate['value'] ?? $candidate['hex'] ?? null);
            $estimatedRatio = $this->normalizeNullableString($candidate['estimatedRatio'] ?? $candidate['estimated_ratio'] ?? $candidate['ratio'] ?? null) ?? $estimatedRatio;
            $type = $this->normalizeMachineValue($candidate['type'] ?? $candidate['target'] ?? $candidate['appliesTo'] ?? $candidate['applies_to'] ?? null);
            $apiLabel = $this->normalizeNullableString($candidate['label'] ?? null);
            $explanation = $this->normalizeNullableString($candidate['explanation'] ?? $candidate['reason'] ?? null);
        } else {
            $color = $this->normalizeColorValue($candidate);
        }

        if ($color === null) {
            return null;
        }

        $typeLabel = match ($type) {
            'foreground', 'fg', 'text' => 'Foreground',
            'background', 'bg' => 'Background',
            default => 'Candidate',
        };

        return [
            'color' => $color,
            'type' => $type,
            'typeLabel' => $typeLabel,
            'estimatedRatio' => $estimatedRatio,
            'label' => $apiLabel ?? ($estimatedRatio !== null ? $color . ' · estimated contrast ratio ' . $estimatedRatio : $color),
            'explanation' => $explanation,
            'reason' => $explanation,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeNodeRemediation(string $json): array
    {
        if (trim($json) === '') {
            return [];
        }

        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }

        return $this->normalizeNodeRemediation(is_array($decoded) ? $decoded : []);
    }

    /**
     * @param array<string, mixed> $remediation
     * @return array<string, mixed>
     */
    private function normalizeNodeRemediation(array $remediation): array
    {
        if ($remediation === []) {
            return [];
        }

        $summary = $this->normalizeNullableString($remediation['summary'] ?? null);
        $steps = $this->normalizeStringList($remediation['steps'] ?? []);
        $recommendedOwner = $this->normalizeMachineValue($remediation['recommendedOwner'] ?? $remediation['recommended_owner'] ?? null);
        $fixType = $this->normalizeMachineValue($remediation['fixType'] ?? $remediation['fix_type'] ?? null);
        $confidence = $this->normalizeMachineValue($remediation['confidence'] ?? null);
        $documentationHint = $this->normalizeNullableString($remediation['documentationHint'] ?? $remediation['documentation_hint'] ?? null);

        if ($summary === null && $steps === [] && $recommendedOwner === '' && $fixType === '' && $confidence === '' && $documentationHint === null) {
            return [];
        }

        return [
            'summary' => $summary,
            'steps' => $steps,
            'hasSteps' => $steps !== [],
            'recommendedOwner' => $recommendedOwner,
            'recommendedOwnerLabel' => $this->formatBadgeLabel($recommendedOwner),
            'fixType' => $fixType,
            'fixTypeLabel' => $this->formatBadgeLabel($fixType),
            'confidence' => $confidence,
            'confidenceLabel' => $this->formatBadgeLabel($confidence),
            'documentationHint' => $documentationHint,
        ];
    }

    /**
     * @return array<int, string>
     */
    private function normalizeStringList(mixed $value): array
    {
        if (is_string($value)) {
            $value = [$value];
        }
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $item) {
            $text = $this->normalizeNullableString($item);
            if ($text !== null) {
                $items[] = $text;
            }
        }

        return array_slice(array_values(array_unique($items)), 0, 6);
    }

    /**
     * @return array<int, string>
     */
    private function normalizeColorCandidates(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }

        $items = [];
        foreach ($value as $candidate) {
            $color = $this->normalizeColorValue($candidate);
            if ($color !== null) {
                $items[$color] = $color;
            }
        }

        return array_slice(array_values($items), 0, 6);
    }

    private function normalizeColorValue(mixed $value): ?string
    {
        $normalized = $this->normalizeNullableString($value);
        if ($normalized === null) {
            return null;
        }

        if (!preg_match('/^#[0-9a-fA-F]{3,8}$/', $normalized)) {
            return substr($normalized, 0, 32);
        }

        return strtolower($normalized);
    }

    private function buildRemoteOverviewBackUrl(
        ServerRequestInterface $request,
        string $siteIdentifier,
        int $pageUid = 0,
        ?int $languageUid = null,
    ): string {
        $queryParams = $request->getQueryParams();
        $resolvedPageUid = $pageUid > 0 ? $pageUid : (int)($queryParams['id'] ?? 0);
        $resolvedLanguageUid = $languageUid;

        if ($resolvedLanguageUid === null && $this->requestParameterService->hasLanguageParameter($queryParams)) {
            $resolvedLanguageUid = $this->requestParameterService->getLanguageUidFromParameters(
                $queryParams,
                [],
                0,
                false,
            );
        }

        $parameters = [];
        if ($resolvedPageUid > 0) {
            $parameters['id'] = $resolvedPageUid;
        }
        if ($siteIdentifier !== '') {
            $parameters['site'] = $siteIdentifier;
        }
        $parameters['language'] = $resolvedLanguageUid ?? 0;

        return $this->buildRouteUrl('web_a11y', $parameters);
    }

    private function configureDocHeader(
        ModuleTemplate $moduleTemplate,
        string $backUrl,
        string $remotePageDebugUrl,
    ): void {
        $buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();

        $this->setModuleTitle(
            $moduleTemplate,
            'module.title',
            'module.remotePageDetail.title'
        );

        $backButton = $buttonBar->makeLinkButton()
            ->setHref($backUrl)
            ->setTitle($this->translate('settings.backToOverview'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('actions-view-go-back', IconSize::SMALL));

        $buttonBar->addButton($backButton, ButtonBar::BUTTON_POSITION_LEFT);

        if ($remotePageDebugUrl !== '') {
            $openFrontendButton = $buttonBar->makeLinkButton()
                ->setHref($remotePageDebugUrl)
                ->setTitle($this->translate('module.remotePageDetail.openFrontendDebug'))
                ->setShowLabelText(true)
                ->setDataAttributes(['open-new-tab' => '1'])
                ->setIcon($this->iconFactory->getIcon('actions-view-page', IconSize::SMALL));

            $buttonBar->addButton($openFrontendButton, ButtonBar::BUTTON_POSITION_RIGHT, 1);
        }
    }

    private function buildRemoteScreenshotProxyUrl(
        string $siteIdentifier,
        int $remotePageUid,
    ): string {
        return (string)$this->uriBuilder->buildUriFromRoute('web_a11y.remoteScreenshot', [
            'site' => $siteIdentifier,
            'remotePageUid' => $remotePageUid,
        ]);
    }
}
