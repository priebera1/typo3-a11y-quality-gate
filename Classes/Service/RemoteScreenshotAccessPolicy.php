<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;

/**
 * Who may load the screenshot of a stored frontend page (2026-10 Sol audit SEC-03).
 *
 * The screenshot is evidence of the report it belongs to, so it follows the report's read policy
 * (`ScopeAccessService::resolveReadableScanForRemotePage()`: the scan's site, page show access within the web mounts,
 * or the site root for a site scan). The proxy used to require edit access to the page instead, so a read-only editor
 * saw the report but got a 403 for its image, and it did not check that the page belongs to the scan's site.
 * A Free Remote Preview result never has a screenshot, whatever today's licence is: its provenance is immutable.
 * Module access is enforced by the module route itself; the AQG service checks licence and project ownership.
 */
class RemoteScreenshotAccessPolicy
{
    public function __construct(
        private readonly RemoteScanRepository $remoteScanRepository,
        private readonly ScopeAccessService $scopeAccessService,
    ) {
    }

    public function canRead(int $remotePageUid): bool
    {
        if ($remotePageUid <= 0) {
            return false;
        }

        $remotePage = $this->remoteScanRepository->findPageByUid($remotePageUid);
        if (!is_array($remotePage)) {
            return false;
        }

        $remoteScan = $this->scopeAccessService->resolveReadableScanForRemotePage($remotePage);

        return is_array($remoteScan) && (int)($remoteScan['is_free_preview'] ?? 0) !== 1;
    }
}
