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
        // In a workspace this save created drafts, while the content scan reads live records only: neither feedback
        // nor a gate decision could describe what was saved, so none is given (and nothing live is re-hidden).
        if (isset($dataHandler->BE_USER) && (int)$dataHandler->BE_USER->workspace !== 0) {
            if (($this->pagesBecomingVisible[spl_object_id($dataHandler)] ?? []) !== []) {
                $this->addFlashMessage(
                    message: $this->translate(
                        'publish.flash.workspaceNotChecked',
                        'The Quality Gate checks workspace changes when the workspace is published, not when they are saved.'
                    ),
                    title: $this->translate('publish.flash.warningTitle', 'AQG Warning'),
                    severity: ContextualFeedbackSeverity::INFO,
                    deduplicationKey: 'workspace-not-checked',
                );
            }
            unset($this->pagesBecomingVisible[spl_object_id($dataHandler)]);

            return;
        }

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

            $this->addContentElementFeedback($contentUid, $site->getIdentifier());
        }
    }

    private function handlePageUnhide(DataHandler $dataHandler): void
    {
        $datamap = $dataHandler->datamap ?? [];

        if (!isset($datamap[Tables::PAGES]) || !is_array($datamap[Tables::PAGES])) {
            return;
        }

        $entries = [];
        foreach ($datamap[Tables::PAGES] as $rawPageUid => $data) {
            if (!is_array($data)) {
                continue;
            }

            $pageUid = is_numeric($rawPageUid) ? (int)$rawPageUid : 0;
            if ($pageUid <= 0 || !isset($this->pagesBecomingVisible[spl_object_id($dataHandler)][$pageUid])) {
                continue;
            }
            // G-01: only a page that really became visible is gated. A refused change (no edit permission, a failed
            // validation) leaves it hidden; TYPO3 reports that itself, and a gate verdict would describe an unhide
            // that never happened.
            if (!$this->isStoredVisible($pageUid)) {
                continue;
            }

            [$gatedPageUid, $languageUid] = $this->resolveGatedPage($pageUid, (int)($data['sys_language_uid'] ?? 0));
            $entries[] = [$pageUid, $gatedPageUid, $languageUid];
        }

        // Default-language pages first: a translation unhidden in the same save stays offline when its default page is
        // blocked, so it is neither judged nor reported as "now visible" (ML-01).
        usort($entries, static fn (array $a, array $b): int => ($a[2] === 0 ? 0 : 1) <=> ($b[2] === 0 ? 0 : 1));
        $blockedDefaultPages = [];
        foreach ($entries as [$pageUid, $gatedPageUid, $languageUid]) {
            if ($languageUid > 0 && isset($blockedDefaultPages[$gatedPageUid])) {
                continue;
            }
            $staysVisible = $this->scanAndCheckPageGate($gatedPageUid, $dataHandler, $languageUid, $pageUid);

            if ($languageUid === 0 && $gatedPageUid === $pageUid) {
                if (!$staysVisible) {
                    // A blocked default page takes its translations offline again: they are not published, so not judged.
                    $blockedDefaultPages[$pageUid] = true;
                    continue;
                }
                $this->checkTranslationsPublishedWithDefaultPage($pageUid, $dataHandler);
            }
        }
    }

    /**
     * ML-01: a hidden default-language page keeps every translation offline. Unhiding it publishes each translation
     * whose own record is visible, so each of them is judged on its own findings, in its own language, and only a
     * failing translation is re-hidden (the default page passed or failed on its own). A translation unhidden in the
     * same save is judged by its own entry and not twice; hidden, deleted and workspace translations stay offline.
     */
    private function checkTranslationsPublishedWithDefaultPage(int $pageUid, DataHandler $dataHandler): void
    {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        // Without a gate there is no verdict to give: no extra scans inside the save request.
        if ($site === null || !$this->qualityGateChecker->isEnabledForSite($site->getIdentifier())) {
            return;
        }

        try {
            $translations = $this->connectionPool->getConnectionForTable(Tables::PAGES)->fetchAllAssociative(
                'SELECT uid, sys_language_uid FROM pages WHERE l10n_parent = ? AND sys_language_uid > 0'
                . ' AND hidden = 0 AND deleted = 0 AND t3ver_wsid = 0 ORDER BY sys_language_uid',
                [$pageUid],
            );
        } catch (\Throwable) {
            $translations = [];
        }

        foreach ($translations as $translation) {
            $translationUid = (int)($translation['uid'] ?? 0);
            $translationLanguageUid = (int)($translation['sys_language_uid'] ?? 0);
            if ($translationUid <= 0 || $translationLanguageUid <= 0
                || isset($this->pagesBecomingVisible[spl_object_id($dataHandler)][$translationUid])) {
                continue;
            }
            try {
                $siteLanguage = $site->getLanguageById($translationLanguageUid);
            } catch (\Throwable) {
                // Not a language of this site: the frontend does not serve it, so nothing goes live.
                continue;
            }
            if (!$siteLanguage->isEnabled()) {
                continue;
            }

            $this->scanAndCheckPageGate($pageUid, $dataHandler, $translationLanguageUid, $translationUid);
        }
    }

    private function isStoredVisible(int $pageUid): bool
    {
        try {
            $hidden = $this->connectionPool->getConnectionForTable(Tables::PAGES)->fetchOne(
                'SELECT hidden FROM pages WHERE uid = ? AND deleted = 0',
                [$pageUid],
            );
        } catch (\Throwable) {
            // Unreadable: keep gating (the gate decides on the scan, never on a guess that nothing changed).
            return true;
        }

        return in_array($hidden, [0, '0'], true);
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
        // A positive pid is the page. A negative one is DataHandler's "after the element |pid|" (the Page module's
        // "+ Content" below an element, paste after): the saved element is asked for its page instead.
        if (isset($data['pid']) && is_numeric($data['pid']) && (int)$data['pid'] > 0) {
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

        return is_array($record) && (int)($record['pid'] ?? 0) > 0 ? (int)$record['pid'] : 0;
    }

    private function addContentElementFeedback(int $contentUid, string $siteIdentifier = ''): void
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
                    $siteIdentifier,
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

        $total = $counts['critical'] + $counts['warning'] + $counts['info'];
        $message = sprintf(
            $this->pluralLabel(
                $total,
                'publish.flash.contentIssues',
                'This content element has %1$d accessibility issue: %2$s. Open the Accessibility module to review details.',
                'This content element has %1$d accessibility issues: %2$s. Open the Accessibility module to review details.'
            ),
            $total,
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
     * @return bool false when the gate re-hid the page record
     */
    private function scanAndCheckPageGate(int $pageUid, DataHandler $dataHandler, int $languageUid = 0, int $pageRecordUid = 0): bool
    {
        $site = $this->siteResolutionService->resolveSiteByPageId($pageUid);
        if ($site === null) {
            return true;
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
                    deduplicationKey: 'page-not-checked:' . $pageUid . ':' . $languageUid,
                );
            }

            return true;
        }

        $scannedAt = time();
        $verdict = $this->qualityGateChecker->check($pageUid, $site->getIdentifier(), $languageUid);

        if ($verdict->isPassed()) {
            return true;
        }

        // A translation's verdict names its language: the editor may have unhidden the default page only.
        $languagePrefix = $this->languagePrefix($site, $languageUid);

        if ($verdict->isWarningOnly()) {
            // M-03: Warn does not stop or ask; the page is already visible. Say so, and name what tripped the gate.
            $message = $languagePrefix . $this->translate('publish.flash.warnPublished', 'The page is now visible. It does not meet the Quality Gate:')
                . ' ' . $this->buildVerdictMessage($verdict, $scannedAt, $this->failingIssueSummary($pageUid, $site->getIdentifier(), $languageUid));

            $this->addFlashMessage(
                message: $message,
                title: $this->translate('publish.flash.warningTitle', 'AQG Warning'),
                severity: ContextualFeedbackSeverity::WARNING,
                deduplicationKey: 'page-warning:' . $pageUid . ':' . md5($message),
            );

            return true;
        }

        $proStatus = $this->getProStatusForSite((string)$site->getBase());

        if ($verdict->isBlockingMode() && $proStatus->valid) {
            $this->reHidePage($pageRecordUid > 0 ? $pageRecordUid : $pageUid);

            $message = $languagePrefix . $this->buildVerdictMessage($verdict, $scannedAt);

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

            return false;
        }

        $message = $languagePrefix . $this->buildVerdictMessage($verdict, $scannedAt);

        $this->addFlashMessage(
            message: $message,
            title: $this->translate('publish.flash.warningTitle', 'AQG Warning'),
            severity: ContextualFeedbackSeverity::WARNING,
            deduplicationKey: 'page-fallback-warning:' . $pageUid . ':' . md5($message),
        );

        return true;
    }

    /**
     * M-03: the rule names behind the verdict (most severe first, at most three) and where to fix them, so the warning
     * is actionable without opening the Overview first. Flash messages carry no links; the path is named instead.
     */
    private function failingIssueSummary(int $pageUid, string $siteIdentifier, int $languageUid): string
    {
        try {
            $issues = $this->issueRepository->findOpenForPage($pageUid, $siteIdentifier, $languageUid, true);
        } catch (\Throwable) {
            return '';
        }
        $byRule = [];
        foreach ($issues as $issue) {
            $severity = (int)($issue['severity'] ?? 9);
            if ($severity > 2) {
                continue;
            }
            $label = trim((string)($issue['message'] ?? $issue['rule_id'] ?? ''));
            $key = (string)($issue['rule_id'] ?? $label);
            $byRule[$key] ??= ['label' => $label, 'severity' => $severity, 'count' => 0];
            $byRule[$key]['count']++;
        }
        if ($byRule === []) {
            return '';
        }
        uasort($byRule, static fn (array $a, array $b): int => [$a['severity'], -$a['count']] <=> [$b['severity'], -$b['count']]);
        $parts = [];
        foreach (array_slice($byRule, 0, 3) as $rule) {
            $parts[] = $rule['count'] > 1 ? sprintf('%s (%d×)', rtrim($rule['label'], '.'), $rule['count']) : rtrim($rule['label'], '.');
        }

        // The gate applies to every user who unhides a page; only users with the module can open it (F-06).
        return ' ' . sprintf(
            $this->backendUserService->canAccessAccessibilityModule()
                ? $this->translate('publish.flash.failingIssues', 'To fix: %1$s. Open Accessibility › this page to see every issue and its record.')
                : $this->translate('publish.flash.failingIssues.noModule', 'To fix: %1$s. Ask a colleague with access to the Accessibility module to show you every issue and its record.'),
            implode('; ', $parts)
        );
    }

    private function languagePrefix(\TYPO3\CMS\Core\Site\Entity\Site $site, int $languageUid): string
    {
        if ($languageUid <= 0) {
            return '';
        }
        try {
            $title = trim($site->getLanguageById($languageUid)->getTitle());
        } catch (\Throwable) {
            $title = '';
        }

        return sprintf(
            $this->translate('publish.flash.translationPrefix', 'Translation "%s":'),
            $title !== '' ? $title : (string)$languageUid
        ) . ' ';
    }

    private function buildVerdictMessage(QualityGateVerdict $verdict, int $scannedAt, string $failingIssues = ''): string
    {
        $reasons = [];
        foreach ($verdict->reasonDetails as $detail) {
            $template = $detail['severity'] === 'critical'
                ? $this->pluralLabel((int)$detail['count'], 'publish.flash.reason.critical', '%1$d critical issue exceeds threshold %2$d', '%1$d critical issues exceed threshold %2$d')
                : $this->pluralLabel((int)$detail['count'], 'publish.flash.reason.warning', '%1$d warning exceeds threshold %2$d', '%1$d warnings exceed threshold %2$d');
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
        return $message . $failingIssues . ' ' . sprintf(
            $this->translate('publish.flash.scanBasis', 'Decision based on the content scan from %s.'),
            BackendTimeUtility::formatDateTime($scannedAt)
        );
    }

    private function formatSeverityCount(string $severity, int $count): string
    {
        $template = match ($severity) {
            'critical' => $this->translate('publish.flash.count.critical', '%d critical'),
            'warning' => $this->pluralLabel($count, 'publish.flash.count.warning', '%d warning', '%d warnings'),
            'needs_review' => $this->translate('publish.flash.count.needsReview', '%d needs review'),
            default => $this->pluralLabel($count, 'publish.flash.count.info', '%d info', '%d info'),
        };

        return sprintf($template, $count);
    }

    /** The ".singular" label for exactly one, otherwise the ".plural" label (Atlas audit F-26: no "issue(s)"). */
    private function pluralLabel(int $count, string $key, string $singular, string $plural): string
    {
        return $count === 1
            ? $this->translate($key . '.singular', $singular)
            : $this->translate($key . '.plural', $plural);
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
