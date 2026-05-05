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

    public function check(int $pageUid, string $siteIdentifier): QualityGateVerdict
    {
        $ruleset = $this->findOrCreateRuleset($siteIdentifier);

        if ($ruleset === null) {
            return QualityGateVerdict::pass();
        }

        $publishMode = (int)($ruleset['publish_mode'] ?? 0);
        $counts = $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier);

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

        if ($counts['critical'] > $thresholdCritical) {
            $triggered = true;
            $reasons[] = sprintf(
                '%d critical issue(s) exceed threshold %d',
                $counts['critical'],
                $thresholdCritical
            );
        }

        if ($thresholdWarning >= 0 && $counts['warning'] > $thresholdWarning) {
            $triggered = true;
            $reasons[] = sprintf(
                '%d warning(s) exceed threshold %d',
                $counts['warning'],
                $thresholdWarning
            );
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
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOrCreateRuleset(string $siteIdentifier): ?array
    {
        $ruleset = $this->rulesetRepository->findForSiteOrDefault($siteIdentifier);

        if ($ruleset !== null) {
            return $ruleset;
        }

        try {
            return $this->rulesetRepository->findOrCreateDefault();
        } catch (\Throwable) {
            return null;
        }
    }
}