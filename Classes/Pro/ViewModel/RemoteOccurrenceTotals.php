<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Pro\ViewModel;

/**
 * The occurrence totals of a frontend scan, as the Overview, toolbar, PDF and CSV report them.
 *
 * Occurrences are the stored findings (one per failing element). Every occurrence lands in exactly one impact bucket,
 * "unrated" included, so the breakdown always adds up to the total. The crawler's run counter (`issues_total`) is kept
 * only to state a gap: it also counted elements whose signature duplicated one already recorded, which were never
 * stored. Without stored results (nothing persisted yet) the counter is all there is, and no breakdown is shown.
 */
final class RemoteOccurrenceTotals
{
    public const IMPACTS = ['critical', 'serious', 'moderate', 'minor'];

    /**
     * @param array{critical:int,serious:int,moderate:int,minor:int,unrated:int} $byImpact
     */
    private function __construct(
        public readonly int $total,
        public readonly array $byImpact,
        public readonly int $issueTypes,
        public readonly int $notStored,
        public readonly bool $hasBreakdown,
    ) {
    }

    /**
     * @param array<string, int> $occurrencesByImpact stored occurrences per raw impact value
     */
    public static function fromStored(array $occurrencesByImpact, int $issueTypes, int $reportedTotal): self
    {
        $byImpact = ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0, 'unrated' => 0];
        foreach ($occurrencesByImpact as $impact => $count) {
            $key = strtolower(trim((string)$impact));
            $byImpact[in_array($key, self::IMPACTS, true) ? $key : 'unrated'] += max(0, (int)$count);
        }
        $total = array_sum($byImpact);

        return new self($total, $byImpact, max(0, $issueTypes), max(0, $reportedTotal - $total), true);
    }

    /**
     * The PDF severity row: one figure per impact, plus "Not rated" when some occurrences have no impact. The figures
     * always add up to the total.
     *
     * @return list<array{tone: string, label: string, count: int, foot: string}>
     */
    public function severityRow(): array
    {
        $row = [];
        foreach (['critical' => 'Critical', 'serious' => 'Serious', 'moderate' => 'Moderate', 'minor' => 'Minor', 'unrated' => 'Not rated'] as $key => $label) {
            if ($key === 'unrated' && $this->byImpact['unrated'] === 0) {
                continue;
            }
            $count = $this->byImpact[$key];
            $row[] = [
                'tone' => $key === 'unrated' ? 'none' : $key,
                'label' => $label,
                'count' => $count,
                'foot' => $count === 1 ? 'occurrence' : 'occurrences',
            ];
        }

        return $row;
    }

    public static function reportedOnly(int $reportedTotal): self
    {
        return new self(
            max(0, $reportedTotal),
            ['critical' => 0, 'serious' => 0, 'moderate' => 0, 'minor' => 0, 'unrated' => 0],
            0,
            0,
            false,
        );
    }
}
