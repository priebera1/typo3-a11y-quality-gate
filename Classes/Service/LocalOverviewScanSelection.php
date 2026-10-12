<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * The content scan the Overview's Content tab status describes, and its scope.
 *
 * The status keeps describing the last site (subtree) scan, or on a page the newer of that and the page's own scan.
 * Only when neither exists does it describe the newest completed content scan of the site, whatever its scope, so the
 * tab never claims "No scan on record" above open issues that page scans found. The scope is always named.
 */
final class LocalOverviewScanSelection
{
    public const SCOPE_SITE = 'site';
    public const SCOPE_PAGE = 'page';
    public const SCOPE_NONE = 'none';

    /**
     * @param array<string, mixed>|null $scan
     */
    private function __construct(
        public readonly ?array $scan,
        public readonly string $scope,
        public readonly int $scopePageUid,
    ) {
    }

    /**
     * @param array<string, mixed>|null $subtreeScan the site's newest completed subtree scan
     * @param array<string, mixed>|null $currentPageScan the current page's newest completed page scan (page context only)
     * @param array<string, mixed>|null $newestSiteScan the site's newest completed content scan of any scope
     */
    public static function select(?array $subtreeScan, ?array $currentPageScan, ?array $newestSiteScan, bool $isPageContext): self
    {
        $scan = $subtreeScan;
        if ($isPageContext && is_array($currentPageScan)
            && (!is_array($scan) || (int)($currentPageScan['finished_at'] ?? 0) > (int)($scan['finished_at'] ?? 0))) {
            $scan = $currentPageScan;
        }

        $scan ??= $newestSiteScan;
        if (!is_array($scan)) {
            return new self(null, self::SCOPE_NONE, 0);
        }

        return (string)($scan['scope'] ?? '') === 'page'
            ? new self($scan, self::SCOPE_PAGE, (int)($scan['root_pid'] ?? 0))
            : new self($scan, self::SCOPE_SITE, (int)($scan['root_pid'] ?? 0));
    }

    public function scanUid(): int
    {
        return (int)($this->scan['uid'] ?? 0);
    }
}
