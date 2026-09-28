<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

/**
 * Compares two compatible stored frontend scans URL by URL and rule by rule.
 *
 * Only URLs that both scans covered completely are compared. Everything else — a page that failed or whose
 * findings were not stored in either scan, a URL only one scan covered — is listed as unverified, never as
 * fixed or new: an automated result can only speak for what it actually checked.
 */
final class ScanComparisonService
{
    private const IMPACT_ORDER = ['critical' => 0, 'serious' => 1, 'moderate' => 2, 'minor' => 3];

    public function __construct(
        private readonly RemoteScanFindingIndex $remoteScanFindingIndex,
    ) {
    }

    /**
     * @param array<string, mixed> $baselineScan
     * @param array<string, mixed> $currentScan
     * @return array{
     *     fixed:list<array{url:string,ruleId:string,impact:string,before:int,after:int}>,
     *     new:list<array{url:string,ruleId:string,impact:string,before:int,after:int}>,
     *     regressed:list<array{url:string,ruleId:string,impact:string,before:int,after:int}>,
     *     unresolved:list<array{url:string,ruleId:string,impact:string,before:int,after:int}>,
     *     unverified:list<array{url:string,reason:string}>,
     *     comparedPages:int
     * }
     */
    public function compare(array $baselineScan, array $currentScan): array
    {
        return $this->compareIndexes(
            $this->remoteScanFindingIndex->build($baselineScan),
            $this->remoteScanFindingIndex->build($currentScan),
        );
    }

    /**
     * @param array<string, array<string, mixed>> $baseline
     * @param array<string, array<string, mixed>> $current
     * @return array<string, mixed>
     */
    public function compareIndexes(array $baseline, array $current): array
    {
        $result = ['fixed' => [], 'new' => [], 'regressed' => [], 'unresolved' => [], 'unverified' => [], 'comparedPages' => 0];

        $keys = array_unique(array_merge(array_keys($baseline), array_keys($current)));
        sort($keys);
        foreach ($keys as $key) {
            $before = $baseline[$key] ?? null;
            $after = $current[$key] ?? null;
            $url = (string)($after['url'] ?? $before['url'] ?? $key);

            if ($before === null || $after === null) {
                $result['unverified'][] = ['url' => $url, 'reason' => $before === null ? 'not_in_baseline' : 'not_in_current'];
                continue;
            }
            if (!$before['evidenceComplete'] || !$after['evidenceComplete']) {
                $result['unverified'][] = [
                    'url' => $url,
                    'reason' => $before['failed'] || $after['failed'] ? 'page_failed' : 'evidence_incomplete',
                ];
                continue;
            }

            $result['comparedPages']++;
            $rules = array_unique(array_merge(array_keys($before['rules']), array_keys($after['rules'])));
            foreach ($rules as $ruleId) {
                $was = (int)($before['rules'][$ruleId]['occurrences'] ?? 0);
                $is = (int)($after['rules'][$ruleId]['occurrences'] ?? 0);
                $entry = [
                    'url' => $url,
                    'ruleId' => (string)$ruleId,
                    'impact' => (string)($after['rules'][$ruleId]['impact'] ?? $before['rules'][$ruleId]['impact'] ?? ''),
                    'before' => $was,
                    'after' => $is,
                ];
                $bucket = match (true) {
                    $was > 0 && $is === 0 => 'fixed',
                    $was === 0 && $is > 0 => 'new',
                    $is > $was => 'regressed',
                    default => 'unresolved',
                };
                $result[$bucket][] = $entry;
            }
        }

        foreach (['fixed', 'new', 'regressed', 'unresolved'] as $bucket) {
            usort($result[$bucket], fn (array $a, array $b): int => [self::IMPACT_ORDER[$a['impact']] ?? 9, $a['url'], $a['ruleId']] <=> [self::IMPACT_ORDER[$b['impact']] ?? 9, $b['url'], $b['ruleId']]);
        }

        return $result;
    }

    /**
     * A stable identity for a set of new and regressed findings, so an unchanged regression is not
     * notified twice. The occurrence counts are part of it: the same issue type on the same page getting
     * worse again is a change worth a notification.
     *
     * @param array<string, mixed> $comparison
     */
    public function regressionFingerprint(array $comparison): string
    {
        $items = [];
        foreach (['new', 'regressed'] as $bucket) {
            foreach ($comparison[$bucket] ?? [] as $entry) {
                $items[] = $bucket . '|' . $entry['url'] . '|' . $entry['ruleId'] . '|' . (int)($entry['before'] ?? 0) . '>' . (int)($entry['after'] ?? 0);
            }
        }
        sort($items);

        return $items === [] ? '' : hash('sha256', implode("\n", $items));
    }
}
