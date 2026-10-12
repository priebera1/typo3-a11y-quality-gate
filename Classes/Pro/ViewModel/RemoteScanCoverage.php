<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

/**
 * How much of a stored frontend scan was actually checked.
 *
 * `pages_scanned` counts the pages the crawler loaded and analysed; pages that answered with an error or could not be
 * loaded are counted in `pages_failed` and stored as `is_failed` page rows. A scan that checked no page proves nothing
 * about the site, whatever score or tone the API derived for it.
 */
final class RemoteScanCoverage
{
    private function __construct(
        public readonly int $loadedPages,
        public readonly int $failedPages,
        public readonly int $expectedPages,
    ) {
    }

    /**
     * @param array<string, mixed> $scan a tx_a11y_remote_scan row
     * @param int $failedPageRows stored failed page rows of the scan, when known
     */
    public static function fromScanRow(array $scan, int $failedPageRows = 0): self
    {
        $loaded = max(0, (int)($scan['pages_scanned'] ?? 0));
        $failed = max(0, (int)($scan['pages_failed'] ?? 0), $failedPageRows);
        $expected = max($loaded + $failed, (int)($scan['pages_total'] ?? 0));

        return new self($loaded, $failed, $expected);
    }

    public function hasNoLoadedPage(): bool
    {
        return $this->loadedPages === 0;
    }

    public function isComplete(): bool
    {
        return $this->loadedPages > 0 && $this->failedPages === 0 && $this->loadedPages >= $this->expectedPages;
    }

    public function missingPages(): int
    {
        return max(0, $this->expectedPages - $this->loadedPages);
    }
}
