<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Hook;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Pro\Service\ProCapabilityService;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\Scan\ContentCollector;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\BackendContextService;
use Priebera\A11yQualityGate\Service\BackendUserService;
use Priebera\A11yQualityGate\Service\ExtensionContextService;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Type\ContextualFeedbackSeverity;

final class PublishHook
{
    /**
     * @var array<string, bool>
     */
    private array $shownFlashMessages = [];

    public function __construct(
        private readonly QualityGateChecker $qualityGateChecker,
        private readonly ScanOrchestrator $scanOrchestrator,
        private readonly IssueRepository $issueRepository,
        private readonly SiteFinder $siteFinder,
        private readonly ContentCollector $contentCollector,
        private readonly BackendUserService $backendUserService,
        private readonly BackendContextService $backendContextService,
        private readonly ProCapabilityService $proCapabilityService,
        private readonly ExtensionContextService $extensionContextService,
    ) {
    }

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        if (!$this->backendUserService->canAccessAccessibilityModule()) {
            return;
        }

        $this->handleContentElementChanges($dataHandler);
        $this->handlePageUnhide($dataHandler);
    }

    private function handleContentElementChanges(DataHandler $dataHandler): void
    {
        $changedContentByPage = $this->collectPrimaryChangedContentByPage($dataHandler);

        foreach ($changedContentByPage as $pageUid => $contentUid) {
            try {
                $site = $this->siteFinder->getSiteByPageId($pageUid);
            } catch (\Throwable) {
                continue;
            }

            try {
                $this->scanOrchestrator->scanPage(
                    siteIdentifier: $site->getIdentifier(),
                    pageUid: $pageUid,
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

            if (!array_key_exists('hidden', $data) || (int)$data['hidden'] !== 0) {
                continue;
            }

            $pageUid = is_numeric($rawPageUid) ? (int)$rawPageUid : 0;
            if ($pageUid <= 0) {
                continue;
            }

            $this->scanAndCheckPageGate($pageUid, $dataHandler);
        }
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

        if ($counts['critical'] > 0) {
            $parts[] = sprintf('%d critical', $counts['critical']);
        }

        if ($counts['warning'] > 0) {
            $parts[] = sprintf('%d warning', $counts['warning']);
        }

        if ($counts['info'] > 0) {
            $parts[] = sprintf('%d info', $counts['info']);
        }

        if ($parts === []) {
            return;
        }

        $message = sprintf(
            'This content element has %s accessibility issue(s). Open the Accessibility module to review details.',
            implode(', ', $parts),
        );

        $this->addFlashMessage(
            message: $message,
            title: 'Accessibility Quality Gate',
            severity: ContextualFeedbackSeverity::WARNING,
            deduplicationKey: 'content:' . $contentUid,
        );
    }

    private function scanAndCheckPageGate(int $pageUid, DataHandler $dataHandler): void
    {
        try {
            $site = $this->siteFinder->getSiteByPageId($pageUid);
        } catch (\Throwable) {
            return;
        }

        try {
            $this->scanOrchestrator->scanPage(
                siteIdentifier: $site->getIdentifier(),
                pageUid: $pageUid,
            );
        } catch (\Throwable) {
            return;
        }

        $verdict = $this->qualityGateChecker->check($pageUid, $site->getIdentifier());

        if ($verdict->isPassed()) {
            if (!$verdict->isDisabled() && $verdict->hasAnyIssues()) {
                $this->addFlashMessage(
                    message: $verdict->toPassedFlashMessage(),
                    title: 'AQG Quality Gate',
                    severity: ContextualFeedbackSeverity::OK,
                    deduplicationKey: 'page-pass:' . $pageUid,
                );
            }

            return;
        }

        if ($verdict->isWarningOnly()) {
            $message = $verdict->toFlashMessage();

            $this->addFlashMessage(
                message: $message,
                title: 'AQG Warning',
                severity: ContextualFeedbackSeverity::WARNING,
                deduplicationKey: 'page-warning:' . $pageUid . ':' . md5($message),
            );

            return;
        }

        $proStatus = $this->getProStatusForSite((string)$site->getBase());

        if ($verdict->isBlockingMode() && $proStatus->valid) {
            $dataHandler->datamap[Tables::PAGES][$pageUid]['hidden'] = 1;

            $message = sprintf(
                'Publishing blocked: %s. Open the Accessibility module to review issues.',
                implode(', ', $verdict->reasons)
            );

            if ($proStatus->isTrial) {
                $message .= ' Trial licence active — upgrade to PRO to keep this feature after the trial ends.';
            }

            $this->addFlashMessage(
                message: $message,
                title: 'AQG Quality Gate',
                severity: ContextualFeedbackSeverity::ERROR,
                deduplicationKey: 'page-block:' . $pageUid . ':' . md5($message),
            );

            return;
        }

        $message = $verdict->toFlashMessage();

        $this->addFlashMessage(
            message: $message,
            title: 'AQG Warning',
            severity: ContextualFeedbackSeverity::WARNING,
            deduplicationKey: 'page-fallback-warning:' . $pageUid . ':' . md5($message),
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
     * @return array{critical:int,warning:int,info:int}
     */
    private function countIssuesBySeverity(array $issues): array
    {
        $counts = [
            'critical' => 0,
            'warning' => 0,
            'info' => 0,
        ];

        foreach ($issues as $issue) {
            $severity = Severity::fromInt((int)$issue['severity']);

            $key = match ($severity) {
                Severity::Critical => 'critical',
                Severity::Warning => 'warning',
                Severity::Info => 'info',
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