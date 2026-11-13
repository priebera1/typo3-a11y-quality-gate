<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Pro\ViewModel\RemoteOccurrenceTotals;

/**
 * What the current user may see of one stored frontend scan (RemoteResultAuthorizationService). Every list, count and
 * export of the scan's results is derived from this object.
 */
final class RemoteResultAuthorization
{
    /**
     * @param array<int, true> $resultPages result page uids the user may see (also those without findings)
     * @param list<array{uid: int, ruleId: string, impact: string, nodesCount: int, occurrences: int, resultPage: int}> $findings
     * @param array<int, true>|null $elements element uids the user may see; null = all (administrator)
     */
    public function __construct(
        public readonly bool $unrestricted,
        public readonly array $resultPages,
        public readonly array $findings,
        public readonly ?array $elements,
    ) {
    }

    public function isResultPageVisible(int $resultPageUid): bool
    {
        return isset($this->resultPages[$resultPageUid]);
    }

    public function isElementVisible(int $elementUid): bool
    {
        return $this->elements === null || isset($this->elements[$elementUid]);
    }

    /** @return array<int, true> finding uid set */
    public function findingUids(): array
    {
        $uids = [];
        foreach ($this->findings as $finding) {
            $uids[$finding['uid']] = true;
        }

        return $uids;
    }

    /** Visible findings per result page (issue types = distinct rules, occurrences). @return array<int, array{issueTypes: int, occurrences: int}> */
    public function countsByResultPage(): array
    {
        $rules = [];
        $occurrences = [];
        foreach ($this->findings as $finding) {
            $rules[$finding['resultPage']][$finding['ruleId']] = true;
            $occurrences[$finding['resultPage']] = ($occurrences[$finding['resultPage']] ?? 0) + ($finding['nodesCount'] > 0 ? $finding['occurrences'] : 1);
        }
        $counts = [];
        foreach ($rules as $page => $set) {
            $counts[$page] = ['issueTypes' => count($set), 'occurrences' => $occurrences[$page] ?? 0];
        }

        return $counts;
    }

    /** Totals as the module reports them, over the visible findings only. */
    public function totals(): RemoteOccurrenceTotals
    {
        $byImpact = [];
        $rules = [];
        foreach ($this->findings as $finding) {
            // As RemoteOccurrenceTotals counts stored findings: a finding without element count counts once.
            $byImpact[$finding['impact']] = ($byImpact[$finding['impact']] ?? 0) + ($finding['nodesCount'] > 0 ? $finding['occurrences'] : 1);
            $rules[$finding['ruleId']] = true;
        }

        return RemoteOccurrenceTotals::fromStored($byImpact, count($rules), 0);
    }
}
