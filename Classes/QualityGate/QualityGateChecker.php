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
        $ruleset = $this->rulesetRepository->findForSiteOrDefault($siteIdentifier);

        return $ruleset !== null && (int)($ruleset['publish_mode'] ?? 0) !== 0;
    }

    public function check(int $pageUid, string $siteIdentifier, int $languageUid = -1): QualityGateVerdict
    {
        $ruleset = $this->rulesetRepository->findForSiteOrDefault($siteIdentifier);

        if ($ruleset === null) {
            return QualityGateVerdict::pass(mode: 0);
        }

        $publishMode = (int)($ruleset['publish_mode'] ?? 0);
        $counts = $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier, $languageUid);

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
                '%d critical issue(s) exceed threshold %d',
                $counts['critical'],
                $thresholdCritical
            );
            $reasonDetails[] = ['severity' => 'critical', 'count' => (int)$counts['critical'], 'threshold' => $thresholdCritical];
        }

        if ($thresholdWarning >= 0 && $counts['warning'] > $thresholdWarning) {
            $triggered = true;
            $reasons[] = sprintf(
                '%d warning(s) exceed threshold %d',
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
}
