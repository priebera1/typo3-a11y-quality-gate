<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

final class ScanResult
{
    public int $pagesScanned = 0;
    public int $recordsScanned = 0;
    public int $recordsSkipped = 0;
    public int $issuesNew = 0;
    public int $issuesResolved = 0;
    public int $issuesIgnored = 0;

    public function __construct(
        public readonly int $scanUid,
    ) {
    }

    public function toSummaryString(): string
    {
        $skippedPart = $this->recordsSkipped > 0
            ? sprintf(', skipped (unchanged): %d', $this->recordsSkipped)
            : '';

        return sprintf(
            'Scan #%d complete — pages: %d, records: %d%s, new issues: %d, resolved: %d, ignored/protected: %d',
            $this->scanUid,
            $this->pagesScanned,
            $this->recordsScanned,
            $skippedPart,
            $this->issuesNew,
            $this->issuesResolved,
            $this->issuesIgnored,
        );
    }
}
