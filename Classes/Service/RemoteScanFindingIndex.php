<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Utility\ScanUrlUtility;

/**
 * What a stored frontend scan found, per URL and rule.
 *
 * Fix verification, monitoring and acceptance evidence compare scans with this index. A page only counts
 * as evidence when it was scanned successfully and its findings were stored completely: a failed page or
 * a page whose findings are missing — all of them or only some — says nothing about whether an issue is gone.
 *
 * Completeness is checked per page against the crawler's own count: `issues_count` is the number of rules
 * the crawler found violated on the page (one axe violation per rule; the crawler caps nodes per rule, never
 * rules per page), so every one of them must be among the stored findings.
 */
final class RemoteScanFindingIndex
{
    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly RemoteIssueRepository $remoteIssueRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $scan
     * @return array<string, array{url:string,remotePageUid:int,failed:bool,httpStatus:int,evidenceComplete:bool,rules:array<string, array{occurrences:int,impact:string}>}>
     *         keyed by ScanUrlUtility::comparable($url)
     */
    public function build(array $scan): array
    {
        $scanUid = (int)($scan['uid'] ?? 0);
        $pages = [];
        foreach ($this->remoteScanRepository->findAllPagesForScan($scanUid) as $page) {
            $key = ScanUrlUtility::comparable((string)($page['url'] ?? ''));
            if ($key === '') {
                continue;
            }

            $httpStatus = (int)($page['http_status'] ?? 0);
            $pages[$key] = [
                'url' => (string)($page['url'] ?? ''),
                'remotePageUid' => (int)($page['uid'] ?? 0),
                'failed' => (int)($page['is_failed'] ?? 0) === 1 || $httpStatus >= 400,
                'httpStatus' => $httpStatus,
                'evidenceComplete' => true,
                'rules' => [],
                'expectedIssueTypes' => (int)($page['issues_count'] ?? 0),
            ];
        }

        $pageKeysByUid = [];
        foreach ($pages as $key => $page) {
            $pageKeysByUid[$page['remotePageUid']] = $key;
        }

        foreach ($this->remoteIssueRepository->findIssueRowsForRemoteScan($scanUid) as $issue) {
            $key = $pageKeysByUid[(int)($issue['remote_scan_page'] ?? 0)] ?? null;
            $ruleId = trim((string)($issue['rule_id'] ?? ''));
            if ($key === null || $ruleId === '') {
                continue;
            }

            $existing = $pages[$key]['rules'][$ruleId] ?? ['occurrences' => 0, 'impact' => ''];
            $pages[$key]['rules'][$ruleId] = [
                'occurrences' => $existing['occurrences'] + max(1, (int)($issue['nodes_count'] ?? 0)),
                'impact' => $existing['impact'] !== '' ? $existing['impact'] : strtolower(trim((string)($issue['impact'] ?? ''))),
            ];
        }

        foreach ($pages as $key => $page) {
            // Fewer stored rules than the crawler reported — none ("details unavailable") or only some — and the
            // absence of a rule proves nothing: it may simply not have been stored.
            $pages[$key]['evidenceComplete'] = !$page['failed'] && count($page['rules']) >= $page['expectedIssueTypes'];
            unset($pages[$key]['expectedIssueTypes']);
        }

        return $pages;
    }
}
