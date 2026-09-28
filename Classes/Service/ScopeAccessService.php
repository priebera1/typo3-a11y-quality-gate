<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Resource-level authorization for AQG results.
 *
 * Module access only opens the AQG screens. Every result belongs to a TYPO3 page or site, so reading it
 * needs access to that page (page scans, page findings) or to the site root (site scans, site-wide
 * lists). Acting on it — scanning, cancelling, exporting — needs edit access to the same page or root.
 * A site, scan or page identifier sent by the browser is only ever checked against these rules, never
 * trusted on its own.
 */
final class ScopeAccessService
{
    public function __construct(
        private readonly BackendUserService $backendUserService,
        private readonly BackendRecordAccessService $backendRecordAccessService,
        private readonly SiteResolutionService $siteResolutionService,
        private readonly RemoteScanRepository $remoteScanRepository,
    ) {
    }

    public function canReadPage(int $pageUid): bool
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if (!$backendUser instanceof BackendUserAuthentication || $pageUid <= 0) {
            return false;
        }

        $page = BackendUtility::readPageAccess($pageUid, $backendUser->getPagePermsClause(Permission::PAGE_SHOW));

        return is_array($page) && $page !== [];
    }

    public function canEditPage(int $pageUid): bool
    {
        return $pageUid > 0 && $this->backendRecordAccessService->canEditRecord(Tables::PAGES, $pageUid);
    }

    public function canReadSite(?Site $site): bool
    {
        return $site instanceof Site && $this->canReadPage((int)$site->getRootPageId());
    }

    public function canEditSite(?Site $site): bool
    {
        return $site instanceof Site && $this->canEditPage((int)$site->getRootPageId());
    }

    public function canReadSiteIdentifier(string $siteIdentifier): bool
    {
        return $this->canReadSite($this->siteResolutionService->resolveSiteByIdentifier($siteIdentifier));
    }

    /**
     * Whether the page is part of the given site. Nested site roots resolve to the innermost site, the
     * same way TYPO3 routes the page.
     */
    public function isPageInSite(int $pageUid, ?Site $site): bool
    {
        if (!$site instanceof Site || $pageUid <= 0) {
            return false;
        }

        $pageSite = $this->siteResolutionService->resolveSiteByPageId($pageUid);

        return $pageSite instanceof Site && $pageSite->getIdentifier() === $site->getIdentifier();
    }

    /**
     * @param array<string, mixed> $remoteScan
     */
    public function canReadRemoteScan(array $remoteScan): bool
    {
        return $this->checkRemoteScan($remoteScan, false);
    }

    /**
     * @param array<string, mixed> $remoteScan
     */
    public function canEditRemoteScan(array $remoteScan): bool
    {
        return $this->checkRemoteScan($remoteScan, true);
    }

    /**
     * The stored scan the remote page belongs to, or null when the page does not exist or the user may
     * not read it.
     *
     * @param array<string, mixed> $remotePage
     * @return array<string, mixed>|null
     */
    public function resolveReadableScanForRemotePage(array $remotePage): ?array
    {
        $scanUid = (int)($remotePage['remote_scan'] ?? 0);
        $remoteScan = $scanUid > 0 ? $this->remoteScanRepository->findScanByUid($scanUid) : null;

        return is_array($remoteScan) && $this->canReadRemoteScan($remoteScan) ? $remoteScan : null;
    }

    /**
     * A stored scan identified by its job ID that belongs to the given site and may be read.
     *
     * @return array<string, mixed>|null
     */
    public function resolveReadableScanByJobId(string $jobId, string $siteIdentifier): ?array
    {
        $jobId = trim($jobId);
        if ($jobId === '' || $siteIdentifier === '') {
            return null;
        }

        $remoteScan = $this->remoteScanRepository->findScanByJobId($jobId);
        if (!is_array($remoteScan) || (string)($remoteScan['site_identifier'] ?? '') !== $siteIdentifier) {
            return null;
        }

        return $this->canReadRemoteScan($remoteScan) ? $remoteScan : null;
    }

    /**
     * The installation-wide content scan status as this user may see it. Everyone needs to know that a
     * scan is running — the scan buttons are disabled meanwhile — but which page it covers, who started it
     * and what it found belongs to that page's scope.
     *
     * @param array<string, mixed> $status
     * @return array<string, mixed>
     */
    public function restrictLocalScanStatus(array $status): array
    {
        $pageUid = (int)($status['pageUid'] ?? 0);
        $rootPid = (int)($status['rootPid'] ?? 0);
        $scopePageUid = $pageUid > 0 ? $pageUid : $rootPid;
        if ($scopePageUid <= 0 || $this->canReadPage($scopePageUid)) {
            return $status;
        }

        return array_intersect_key($status, array_flip([
            'running',
            'startedAt',
            'finishedAt',
            'cancelRequested',
            'cancelled',
        ])) + ['restricted' => true];
    }

    /**
     * Page scans are scoped by their page while the page still belongs to the scan's site; everything
     * else — site scans, and page scans whose page moved or disappeared — needs the site root.
     *
     * @param array<string, mixed> $remoteScan
     */
    private function checkRemoteScan(array $remoteScan, bool $write): bool
    {
        $site = $this->siteResolutionService->resolveSiteByIdentifier(
            trim((string)($remoteScan['site_identifier'] ?? ''))
        );
        if (!$site instanceof Site) {
            return false;
        }

        $pageUid = (int)($remoteScan['page_uid'] ?? 0);
        if ($pageUid > 0 && $this->isPageInSite($pageUid, $site)) {
            return $write ? $this->canEditPage($pageUid) : $this->canReadPage($pageUid);
        }

        return $write ? $this->canEditSite($site) : $this->canReadSite($site);
    }
}
