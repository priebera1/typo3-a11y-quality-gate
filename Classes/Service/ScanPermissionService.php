<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * Whether the current backend user may start a scan: the rule every scan endpoint enforces
 * (ScanAjaxController, ProCrawlerAjaxController), so a scan control is only rendered when the request would succeed.
 *
 * A page scan needs the `showScanNow` user TSconfig flag and edit access to the page; a site scan needs `showScanAll`
 * and edit access to the site root page.
 */
final class ScanPermissionService
{
    public function __construct(
        private readonly AccessControlService $accessControlService,
        private readonly ScopeAccessService $scopeAccessService,
    ) {
    }

    public function canScanPage(int $pageUid): bool
    {
        return $pageUid > 0
            && $this->accessControlService->canShowScanNow()
            && $this->scopeAccessService->canEditPage($pageUid);
    }

    public function canScanSite(int $rootPageUid): bool
    {
        return $rootPageUid > 0
            && $this->accessControlService->canShowScanAll()
            && $this->scopeAccessService->canEditPage($rootPageUid);
    }

    /**
     * Cancelling the running content scan (ScanAjaxController::cancelScanAction()): a scan flag and edit access to
     * the scan's page, or its site root for a site scan.
     *
     * @param array<string, mixed> $status ScanStatusService status
     */
    public function canCancelLocalScan(array $status): bool
    {
        $pageUid = (int)($status['pageUid'] ?? 0);
        $scopeUid = $pageUid > 0 ? $pageUid : (int)($status['rootPid'] ?? 0);

        return $scopeUid > 0
            && ($this->accessControlService->canShowScanNow() || $this->accessControlService->canShowScanAll())
            && $this->scopeAccessService->canEditPage($scopeUid);
    }

    /**
     * Cancelling a frontend scan (ProCrawlerAjaxController::cancelSiteAction()): the scan flag of its scope and edit
     * access to its page, or the site root for a site crawl.
     *
     * @param array<string, mixed> $remoteScan stored tx_a11y_remote_scan row
     */
    public function canCancelRemoteScan(array $remoteScan): bool
    {
        $scopeFlag = (string)($remoteScan['scan_scope'] ?? '') === 'page'
            ? $this->accessControlService->canShowScanNow()
            : $this->accessControlService->canShowScanAll();

        return $scopeFlag && $this->scopeAccessService->canEditRemoteScan($remoteScan);
    }
}
