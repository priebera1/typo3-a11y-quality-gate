<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;

final class QualityGateChecker
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly RulesetRepository $rulesetRepository,
    ) {
    }

    /**
     * Whether the ruleset that applies to the site warns or blocks at all. Only tells callers if a missing
     * decision is worth reporting; it never produces a verdict.
     */
    public function isEnabledForSite(string $siteIdentifier): bool
    {
        return (int)($this->effectiveRuleset($siteIdentifier)['publish_mode'] ?? 0) !== 0;
    }

    public function check(int $pageUid, string $siteIdentifier, int $languageUid = -1): QualityGateVerdict
    {
        return $this->verdictForCounts($siteIdentifier, $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid));
    }

    /**
     * The decision for open issue counts of one page (critical, warning, info, needs_review), by the site's ruleset.
     * Used for stored findings (check()) and for a workspace publish judged before it is written.
     *
     * @param array{critical:int, warning:int, info:int, needs_review:int} $counts
     */
    public function verdictForCounts(string $siteIdentifier, array $counts): QualityGateVerdict
    {
        $ruleset = $this->effectiveRuleset($siteIdentifier);

        $publishMode = (int)($ruleset['publish_mode'] ?? 0);

        if ($publishMode === 0) {
            return QualityGateVerdict::pass(
                mode: 0,
                counts: $counts,
            );
        }

        $thresholdCritical = (int)($ruleset['threshold_critical'] ?? 0);
        $thresholdWarning = (int)($ruleset['threshold_warning'] ?? -1);

        $triggered = false;
        $reasons = [];
        $reasonDetails = [];

        if ($counts['critical'] > $thresholdCritical) {
            $triggered = true;
            $reasons[] = sprintf(
                $counts['critical'] === 1 ? '%d critical issue exceeds threshold %d' : '%d critical issues exceed threshold %d',
                $counts['critical'],
                $thresholdCritical
            );
            $reasonDetails[] = ['severity' => 'critical', 'count' => (int)$counts['critical'], 'threshold' => $thresholdCritical];
        }

        if ($thresholdWarning >= 0 && $counts['warning'] > $thresholdWarning) {
            $triggered = true;
            $reasons[] = sprintf(
                $counts['warning'] === 1 ? '%d warning exceeds threshold %d' : '%d warnings exceed threshold %d',
                $counts['warning'],
                $thresholdWarning
            );
            $reasonDetails[] = ['severity' => 'warning', 'count' => (int)$counts['warning'], 'threshold' => $thresholdWarning];
        }

        if (!$triggered) {
            return QualityGateVerdict::pass(
                mode: $publishMode,
                counts: $counts,
            );
        }

        return QualityGateVerdict::fail(
            mode: $publishMode,
            counts: $counts,
            reasons: $reasons,
            reasonDetails: $reasonDetails,
        );
    }

    /**
     * The ruleset that applies to the site; the shipped default while no default row has been written yet (a fresh
     * installation before an administrator opened Settings). Read-only: the gate never creates configuration.
     *
     * @return array<string, mixed>
     */
    private function effectiveRuleset(string $siteIdentifier): array
    {
        return $this->rulesetRepository->findForSiteOrDefault($siteIdentifier) ?? RulesetRepository::DEFAULT_GATE;
    }
}
