<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scheduler;

use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

final class A11yScanTask extends AbstractTask
{
    public int $pageUid = 0;
    public int $rootPid = 0;
    public int $depth = 99;
    public int $languageUid = -1;
    public bool $changedOnly = false;

    public function execute(): bool
    {
        if ($this->pageUid <= 0 && $this->rootPid <= 0) {
            $this->logger?->error('A11yScanTask: either pageUid or rootPid must be configured.');

            return false;
        }

        try {
            $container = GeneralUtility::getContainer();

            /** @var ScanOrchestrator $orchestrator */
            $orchestrator = $container->get(ScanOrchestrator::class);

            /** @var SiteResolutionService $siteResolutionService */
            $siteResolutionService = $container->get(SiteResolutionService::class);

            if ($this->pageUid > 0) {
                $siteIdentifier = $siteResolutionService->resolveSiteIdentifierFromPageId($this->pageUid);

                $result = $orchestrator->scanPage(
                    siteIdentifier: $siteIdentifier,
                    pageUid: $this->pageUid,
                    languageUid: $this->languageUid,
                    changedOnly: $this->changedOnly,
                );
            } else {
                $siteIdentifier = $siteResolutionService->resolveSiteIdentifierFromPageId($this->rootPid);

                $result = $orchestrator->scanSubtree(
                    siteIdentifier: $siteIdentifier,
                    rootPid: $this->rootPid,
                    depth: $this->depth,
                    languageUid: $this->languageUid,
                    changedOnly: $this->changedOnly,
                );
            }

            $this->logger?->info('A11yScanTask completed', $this->buildLogContext([
                'summary' => $result->toSummaryString(),
            ]));

            return true;
        } catch (\Throwable $e) {
            $this->logger?->error('A11yScanTask failed', $this->buildLogContext([
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]));

            throw $e;
        }
    }

    public function getAdditionalInformation(): string
    {
        $mode = $this->changedOnly ? 'changed-only' : 'full';
        $language = $this->languageUid === -1 ? 'all' : (string)$this->languageUid;

        if ($this->pageUid > 0) {
            return sprintf(
                'Single page | Page UID: %d | Language: %s | Mode: %s',
                $this->pageUid,
                $language,
                $mode
            );
        }

        return sprintf(
            'Subtree | Root PID: %d | Depth: %d | Language: %s | Mode: %s',
            $this->rootPid,
            $this->depth,
            $language,
            $mode
        );
    }

    /**
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function buildLogContext(array $extra = []): array
    {
        return $extra + [
                'pageUid' => $this->pageUid,
                'rootPid' => $this->rootPid,
                'depth' => $this->depth,
                'languageUid' => $this->languageUid,
                'changedOnly' => $this->changedOnly,
            ];
    }
}