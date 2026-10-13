<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Hook;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\QualityGate\QualityGateVerdict;
use Priebera\A11yQualityGate\Scan\ContentCollector;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use Priebera\A11yQualityGate\Utility\BackendTimeUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class PublishHook
{
    /**
     * @var array<string, bool>
     */
    private array $shownFlashMessages = [];

    /**
     * Page records each DataHandler run makes visible (hidden before, `hidden = 0` submitted), by DataHandler instance:
     * the hook is a shared service and DataHandler runs can be nested.
     *
     * @var array<int, array<int, true>>
     */
    private array $pagesBecomingVisible = [];

    public function __construct(
        private readonly QualityGateChecker $qualityGateChecker,
        private readonly ScanOrchestrator $scanOrchestrator,
        private readonly IssueRepository $issueRepository,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly ContentCollector $contentCollector,
        private readonly BackendUserService $backendUserService,
        private readonly ConnectionPool $connectionPool,
        private readonly BackendContextService $backendContextService,
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    /**
     * The Quality Gate decides when a page goes live: when a hidden page record is made visible (unhide in the page
     * tree or list, the "Page visible" toggle). FormEngine submits `hidden = 0` with every save of a visible page, so
     * the stored state before the save tells a publish from a routine edit of a page that is already online. A state
     * that cannot be read counts as hidden: the gate runs rather than being skipped.
     *
     * @param array<string, mixed> $incomingFieldArray
     */
    public function processDatamap_preProcessFieldArray(array &$incomingFieldArray, string $table, int|string $id, DataHandler $dataHandler): void
    {
        if ($table !== Tables::PAGES
            || !is_numeric($id)
            || (int)$id <= 0
            || !array_key_exists('hidden', $incomingFieldArray)
            || (int)$incomingFieldArray['hidden'] !== 0) {
            return;
        }

        try {
            $storedHidden = $this->connectionPool->getConnectionForTable(Tables::PAGES)->fetchOne(
                'SELECT hidden FROM pages WHERE uid = ? AND deleted = 0',
                [(int)$id],
            );
        } catch (\Throwable) {
            $storedHidden = null;
        }
        if (!in_array($storedHidden, [0, '0'], true)) {
            $this->pagesBecomingVisible[spl_object_id($dataHandler)][(int)$id] = true;
        }
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        // Content feedback points editors to the AQG module, so only module users get it. The Quality Gate
        // is enforcement: it applies to everyone who can publish or unhide a page, including users without
        // access to the AQG module, and it never grants that access.
        if ($this->backendUserService->canAccessAccessibilityModule()) {
            $this->handleContentElementChanges($dataHandler);
        }

        $this->handlePageUnhide($dataHandler);
        unset($this->pagesBecomingVisible[spl_object_id($dataHandler)]);
    }

    private function handleContentElementChanges(DataHandler $dataHandler): void
    {
        $changedContentByPage = $this->collectPrimaryChangedContentByPage($dataHandler);

        foreach ($changedContentByPage as $pageUid => $contentUid) {
            $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
            if ($site === null) {
                continue;
            }

            try {
                $this->scanOrchestrator->scanPage(
                    siteIdentifier: $site->getIdentifier(),
                    pageUid: $pageUid,
                    resolvedBy: $this->backendUserService->getBackendUserSnapshot(),
                );
            } catch (\Throwable) {
                continue;
            }

            $this->addContentElementFeedback($contentUid);
        }
    }

    private function handlePageUnhide(DataHandler $dataHandler): void
    {
        $datamap = $dataHandler->datamap ?? [];

        if (!isset($datamap[Tables::PAGES]) || !is_array($datamap[Tables::PAGES])) {
            return;
        }

        foreach ($datamap[Tables::PAGES] as $rawPageUid => $data) {
            if (!is_array($data)) {
                continue;
            }

            $pageUid = is_numeric($rawPageUid) ? (int)$rawPageUid : 0;
            if ($pageUid <= 0 || !isset($this->pagesBecomingVisible[spl_object_id($dataHandler)][$pageUid])) {
                continue;
            }

            [$gatedPageUid, $languageUid] = $this->resolveGatedPage($pageUid, (int)($data['sys_language_uid'] ?? 0));
            $this->scanAndCheckPageGate($gatedPageUid, $dataHandler, $languageUid, $pageUid);
        }
    }

    /**
     * A translated page record (connected mode) has no content of its own: the content is stored on the
     * default-language page in the record's language, and that is what the scan and the verdict must look at.
     * Unhiding a translation only sends `hidden`, so the language comes from the record, not from the datamap.
     *
     * @return array{0: int, 1: int} the default-language page uid and the language uid
     */
    private function resolveGatedPage(int $pageUid, int $submittedLanguageUid): array
    {
        try {
            $record = $this->connectionPool->getConnectionForTable(Tables::PAGES)->fetchAssociative(
                'SELECT l10n_parent, sys_language_uid FROM pages WHERE uid = ? AND deleted = 0',
                [$pageUid],
            );
        } catch (\Throwable) {
            $record = false;
        }
        if (!is_array($record)) {
            return [$pageUid, $submittedLanguageUid];
        }

        $languageUid = (int)($record['sys_language_uid'] ?? 0);
        $defaultPageUid = (int)($record['l10n_parent'] ?? 0);

        return $languageUid > 0 && $defaultPageUid > 0 ? [$defaultPageUid, $languageUid] : [$pageUid, $languageUid];
    }

    /**
     * @return array<int, int>
     */
    private function collectPrimaryChangedContentByPage(DataHandler $dataHandler): array
    {
        $datamap = $dataHandler->datamap ?? [];
        $primaryContentByPage = [];

        if (!isset($datamap[Tables::TT_CONTENT]) || !is_array($datamap[Tables::TT_CONTENT])) {
            return [];
        }

        foreach ($datamap[Tables::TT_CONTENT] as $rawContentUid => $data) {
            if (!is_array($data)) {
                continue;
            }

            $contentUid = $this->resolveContentUid($rawContentUid, $dataHandler);
            if ($contentUid <= 0) {
                continue;
            }

            $pageUid = $this->resolveContentPageUid($contentUid, $data);
            if ($pageUid <= 0) {
                continue;
            }

            if (!isset($primaryContentByPage[$pageUid])) {
                $primaryContentByPage[$pageUid] = $contentUid;
            }
        }

        return $primaryContentByPage;
    }

    private function resolveContentUid(string|int $rawContentUid, DataHandler $dataHandler): int
    {
        if (is_numeric($rawContentUid)) {
            return (int)$rawContentUid;
        }

        if (
            is_string($rawContentUid)
            && isset($dataHandler->substNEWwithIDs[$rawContentUid])
            && is_numeric($dataHandler->substNEWwithIDs[$rawContentUid])
        ) {
            return (int)$dataHandler->substNEWwithIDs[$rawContentUid];
        }

        return 0;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveContentPageUid(int $contentUid, array $data): int
    {
        if (isset($data['pid']) && is_numeric($data['pid'])) {
            return (int)$data['pid'];
        }

        if ($contentUid <= 0) {
            return 0;
        }

        try {
            $record = BackendUtility::getRecord(Tables::TT_CONTENT, $contentUid, 'pid');
        } catch (\Throwable) {
            return 0;
        }

        return is_array($record) && isset($record['pid']) ? (int)$record['pid'] : 0;
    }

    private function addContentElementFeedback(int $contentUid): void
    {
        $fieldsToCheck = array_values(array_unique(array_merge(
            $this->contentCollector->getRteFieldsForTable(Tables::TT_CONTENT),
            $this->contentCollector->getStructuredFieldsForTable(),
            $this->contentCollector->getFileReferenceFieldsForTable(Tables::TT_CONTENT),
        )));

        $allIssues = [];

        foreach ($fieldsToCheck as $field) {
            $allIssues = array_merge(
                $allIssues,
                $this->issueRepository->findOpenForRecord(
                    Tables::TT_CONTENT,
                    $contentUid,
                    $field,
                ),
            );
        }

        if ($allIssues === []) {
            return;
        }

        $counts = $this->countIssuesBySeverity($allIssues);
        $parts = [];

        foreach (['critical', 'warning', 'info'] as $severity) {
            if ($counts[$severity] > 0) {
                $parts[] = $this->formatSeverityCount($severity, $counts[$severity]);
            }
        }

        if ($parts === []) {
            return;
        }

        $message = sprintf(
            $this->translate(
                'publish.flash.contentIssues',
                'This content element has %s accessibility issue(s). Open the Accessibility module to review details.'
            ),
            implode(', ', $parts),
        );

        $this->addFlashMessage(
            message: $message,
            title: 'Accessibility Quality Gate',
            severity: ContextualFeedbackSeverity::WARNING,
            deduplicationKey: 'content:' . $contentUid,
        );
    }

    /**
     * @param int $pageRecordUid the unhidden page record (a translation is re-hidden, not its default-language page)
     */
    private function scanAndCheckPageGate(int $pageUid, DataHandler $dataHandler, int $languageUid = 0, int $pageRecordUid = 0): void
    {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if ($site === null) {
            return;
        }

        try {
            $this->scanOrchestrator->scanPage(
                siteIdentifier: $site->getIdentifier(),
                pageUid: $pageUid,
                languageUid: $languageUid,
                resolvedBy: $this->backendUserService->getBackendUserSnapshot(),
            );
        } catch (\Throwable) {
            // Without a fresh scan there is no decision; say so instead of letting the page pass silently.
            if ($this->qualityGateChecker->isEnabledForSite($site->getIdentifier())) {
                $this->addFlashMessage(
                    message: $this->translate(
                        'publish.flash.notChecked',
                        'The Quality Gate could not check this page because its content scan failed. Run a content scan in the Accessibility module to see its current issues.'
                    ),
                    title: $this->translate('publish.flash.warningTitle', 'AQG Warning'),
                    severity: ContextualFeedbackSeverity::WARNING,
                    deduplicationKey: 'page-not-checked:' . $pageUid,
                );
            }

            return;
        }

        $scannedAt = time();
        $verdict = $this->qualityGateChecker->check($pageUid, $site->getIdentifier(), $languageUid);

        if ($verdict->isPassed()) {
            return;
        }

        if ($verdict->isWarningOnly()) {
            $message = $this->buildVerdictMessage($verdict, $scannedAt);

            $this->addFlashMessage(
                message: $message,
                title: $this->translate('publish.flash.warningTitle', 'AQG Warning'),
                severity: ContextualFeedbackSeverity::WARNING,
                deduplicationKey: 'page-warning:' . $pageUid . ':' . md5($message),
            );

            return;
        }

        $proStatus = $this->getProStatusForSite((string)$site->getBase());

        if ($verdict->isBlockingMode() && $proStatus->valid) {
            $this->reHidePage($pageRecordUid > 0 ? $pageRecordUid : $pageUid);

            $message = $this->buildVerdictMessage($verdict, $scannedAt);

            if ($proStatus->isTrial) {
                $message .= ' ' . $this->translate(
                    'publish.flash.trialNote',
                    'Trial active — choose a PRO or Agency plan to keep blocking publishing after the trial ends.'
                );
            }

            $this->addFlashMessage(
                message: $message,
                title: 'AQG Quality Gate',
                severity: ContextualFeedbackSeverity::ERROR,
                deduplicationKey: 'page-block:' . $pageUid . ':' . md5($message),
            );

            return;
        }

        $message = $this->buildVerdictMessage($verdict, $scannedAt);

        $this->addFlashMessage(
            message: $message,
            title: $this->translate('publish.flash.warningTitle', 'AQG Warning'),
            severity: ContextualFeedbackSeverity::WARNING,
            deduplicationKey: 'page-fallback-warning:' . $pageUid . ':' . md5($message),
        );
    }

    private function buildVerdictMessage(QualityGateVerdict $verdict, int $scannedAt): string
    {
        $reasons = [];
        foreach ($verdict->reasonDetails as $detail) {
            $template = $detail['severity'] === 'critical'
                ? $this->translate('publish.flash.reason.critical', '%1$d critical issue(s) exceed threshold %2$d')
                : $this->translate('publish.flash.reason.warning', '%1$d warning(s) exceed threshold %2$d');
            $reasons[] = sprintf($template, $detail['count'], $detail['threshold']);
        }
        if ($reasons === []) {
            $reasons = $verdict->reasons;
        }

        $counts = [];
        foreach (['critical', 'warning', 'info', 'needs_review'] as $severity) {
            $count = (int)($verdict->counts[$severity] ?? 0);
            if ($count > 0) {
                $counts[] = $this->formatSeverityCount($severity, $count);
            }
        }

        $message = sprintf(
            $this->translate(
                'publish.flash.gate',
                'Accessibility quality gate: %1$s. Current open findings: %2$s. Needs review items are manual checks and do not block publishing.'
            ),
            implode(', ', $reasons),
            $counts !== [] ? implode(', ', $counts) : $this->translate('publish.flash.none', 'none'),
        );

        // The decision always rests on the content scan that ran just before it; frontend scans are not part of it.
        return $message . ' ' . sprintf(
            $this->translate('publish.flash.scanBasis', 'Decision based on the content scan from %s.'),
            BackendTimeUtility::formatDateTime($scannedAt)
        );
    }

    private function formatSeverityCount(string $severity, int $count): string
    {
        $template = match ($severity) {
            'critical' => $this->translate('publish.flash.count.critical', '%d critical'),
            'warning' => $this->translate('publish.flash.count.warning', '%d warning'),
            'needs_review' => $this->translate('publish.flash.count.needsReview', '%d needs review'),
            default => $this->translate('publish.flash.count.info', '%d info'),
        };

        return sprintf($template, $count);
    }

    private function translate(string $key, string $fallback): string
    {
        $translated = $this->backendContextService->translate($key);

        return $translated !== '' && $translated !== $key ? $translated : $fallback;
    }


    private function reHidePage(int $pageUid): void
    {
        $connection = $this->connectionPool->getConnectionForTable(Tables::PAGES);
        $connection->update(
            Tables::PAGES,
            [
                'hidden' => 1,
            ],
            ['uid' => $pageUid]
        );
    }

    private function getProStatusForSite(string $siteBase): object
    {
        $domain = $this->extensionContextService->getNormalizedDomainFromSiteBase($siteBase);
        $version = $this->extensionContextService->getExtensionVersion();

        return $this->proCapabilityService->getStatus($domain, $version);
    }

    /**
     * @param array<int, array<string, mixed>> $issues
     * @return array{critical:int,warning:int,info:int,needs_review:int}
     */
    private function countIssuesBySeverity(array $issues): array
    {
        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
            'needs_review' => 0,
        ];

        foreach ($issues as $issue) {
            $severity = Severity::fromInt((int)$issue['severity']);

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
                Severity::NeedsReview => 'needs_review',
            };

            $counts[$key]++;
        }

        return $counts;
    }

    private function addFlashMessage(
        string $message,
        string $title,
        ContextualFeedbackSeverity $severity,
        string $deduplicationKey,
    ): void {
        if (isset($this->shownFlashMessages[$deduplicationKey])) {
            return;
        }

        $this->shownFlashMessages[$deduplicationKey] = true;

        $this->backendContextService->addFlashMessage(
            $message,
            $severity,
            $title,
            true,
        );
    }
}
