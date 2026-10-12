<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\Service;

use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Pro\ViewModel\RemoteOccurrenceTotals;

/**
 * One source for a frontend scan's occurrence counts: the stored findings once the results are persisted, the
 * crawler's run counter before that. The Overview, the toolbar, the PDF and the CSV all report these numbers.
 */
final class RemoteOccurrenceTotalsService
{
    public function __construct(
        private readonly RemoteIssueRepository $remoteIssueRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $scan a tx_a11y_remote_scan row
     */
    public function forScan(array $scan): RemoteOccurrenceTotals
    {
        $scanUid = (int)($scan['uid'] ?? 0);
        $reported = (int)($scan['issues_total'] ?? 0);
        if ($scanUid <= 0 || (int)($scan['persisted_at'] ?? 0) <= 0) {
            return RemoteOccurrenceTotals::reportedOnly($reported);
        }

        return RemoteOccurrenceTotals::fromStored(
            $this->remoteIssueRepository->countOccurrencesByImpactForScan($scanUid),
            $this->remoteIssueRepository->countIssueTypesForScan($scanUid),
            $reported,
        );
    }
}
