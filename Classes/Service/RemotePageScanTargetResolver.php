<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Which TYPO3 page a stored frontend URL belongs to, for scanning that URL again.
 *
 * A page scan keeps its page. Otherwise the first finding mapped to a TYPO3 record names the page. A URL
 * that maps to no page of the scan's site belongs to the site root, so rescanning it needs the same access
 * as scanning the whole site. The Remote Page Detail button and the submit endpoint both use this, which
 * keeps the page a browser may name bound to the URL that is scanned.
 */
final class RemotePageScanTargetResolver
{
    public function __construct(
        private readonly RemoteIssueRepository $remoteIssueRepository,
        private readonly RemoteIssueNodeRepository $remoteIssueNodeRepository,
        private readonly SiteResolutionService $siteResolutionService,
    ) {
    }

    /**
     * @param array<string, mixed> $remotePage
     * @param array<string, mixed>|null $remoteScan
     */
    public function resolveScanPageUid(array $remotePage, ?array $remoteScan, ?Site $site): int
    {
        if (!$site instanceof Site) {
            return 0;
        }

        $issuesWithNodes = [];
        $remotePageUid = (int)($remotePage['uid'] ?? 0);
        if ((int)($remoteScan['page_uid'] ?? 0) <= 0 && $remotePageUid > 0) {
            foreach ($this->remoteIssueRepository->findByRemoteScanPage($remotePageUid) as $issue) {
                $issueUid = (int)($issue['uid'] ?? 0);
                $issuesWithNodes[] = [
                    'nodes' => $issueUid > 0 ? $this->remoteIssueNodeRepository->findByRemoteIssue($issueUid) : [],
                ];
            }
        }

        $mappedPageUid = $this->resolveMappedPageUid($remoteScan, $issuesWithNodes, $site);

        return $mappedPageUid > 0 ? $mappedPageUid : (int)$site->getRootPageId();
    }

    /**
     * The TYPO3 page a scan or its mapped findings point to, limited to pages of the given site; 0 when
     * there is none.
     *
     * @param array<string, mixed>|null $remoteScan
     * @param array<int, array<string, mixed>> $issuesWithNodes
     */
    public function resolveMappedPageUid(?array $remoteScan, array $issuesWithNodes, ?Site $site): int
    {
        $scanPageUid = (int)($remoteScan['page_uid'] ?? 0);
        if ($scanPageUid > 0) {
            return $this->isPageInSite($scanPageUid, $site) ? $scanPageUid : 0;
        }

        foreach ($issuesWithNodes as $issue) {
            $nodes = is_array($issue['nodes'] ?? null) ? $issue['nodes'] : [];

            foreach ($nodes as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $mappedTable = trim((string)($node['mapped_table'] ?? ''));
                $mappedUid = (int)($node['mapped_uid'] ?? 0);
                if ($mappedTable === '' || $mappedUid <= 0 || !isset($GLOBALS['TCA'][$mappedTable])) {
                    continue;
                }

                $pageUid = $mappedTable === 'pages' ? $mappedUid : 0;
                if ($pageUid <= 0) {
                    $record = BackendUtility::getRecord($mappedTable, $mappedUid, 'pid');
                    $pageUid = is_array($record) ? (int)($record['pid'] ?? 0) : 0;
                }

                if ($pageUid > 0 && $this->isPageInSite($pageUid, $site)) {
                    return $pageUid;
                }
            }
        }

        return 0;
    }

    private function isPageInSite(int $pageUid, ?Site $site): bool
    {
        if (!$site instanceof Site) {
            return true;
        }

        $pageSite = $this->siteResolutionService->resolveSiteByPageId($pageUid);

        return $pageSite instanceof Site && $pageSite->getIdentifier() === $site->getIdentifier();
    }
}
