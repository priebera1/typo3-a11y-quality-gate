<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Ai\Usage;

use Priebera\A11yQualityGate\Ai\Settings\AiBackendUserNames;
use Priebera\A11yQualityGate\Export\CsvValueSanitizer;

/**
 * "Export CSV" of Accessibility › Settings › AI suggestions › Usage & limits: the AI usage of one site and month by
 * backend user, from the same AiUsageSummaryService result the page shows (no own query, no own arithmetic), so the
 * file reconciles with the report. Amounts are the exact micro-USD values as USD with six decimals (the page rounds to
 * cents); the last row is the site total. Rows hold no prompt, answer, key or conversation id. Administrators only —
 * the caller (SettingsController::aiUsageExportAction()) checks that and the site before calling.
 */
final class AiUsageCsvExporter
{
    /** Column keys in file order; the header labels are settings.ai.usage.csv.<key>. */
    public const COLUMNS = [
        'site', 'month', 'beUser', 'name', 'username', 'account', 'requests', 'succeeded', 'failed', 'pending',
        'inputTokens', 'cachedInputTokens', 'outputTokens', 'estimatedUsd', 'worstCaseUsd', 'worstCaseRequests',
        'rejectedBeforeProcessing', 'withoutPrice', 'reservedUsd', 'features',
    ];

    public function __construct(
        private readonly AiUsageSummaryService $summaryService,
        private readonly AiBackendUserNames $userNames,
    ) {}

    /**
     * @param \Closure(string $column): string $header translated header label per column key
     * @param string $totalLabel name of the site total row ("Site total")
     * @param string $noUserLabel name of the row for requests without a backend user ("No backend user")
     * @return array{filename:string,content:string,month:string,report:array<string,mixed>}
     */
    public function export(string $siteIdentifier, string $month, \Closure $header, string $totalLabel, string $noUserLabel = ''): array
    {
        $report = $this->summaryService->month($siteIdentifier, $month);
        if (($report['loadFailed'] ?? false) === true) {
            throw new \RuntimeException('The AI usage could not be read.', 1771003001);
        }
        $period = (string)$report['month'];
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new \RuntimeException('No temporary stream.', 1771003002);
        }
        $write = static function (array $row) use ($stream): void {
            fputcsv($stream, CsvValueSanitizer::sanitizeRow($row), ',', '"', '');
        };
        $write(array_map(static fn (string $column): string => $header($column), self::COLUMNS));
        foreach ($report['people'] as $person) {
            $uid = (int)$person['beUser'];
            $account = $uid > 0 ? $this->userNames->describe($uid) : ['name' => $noUserLabel, 'username' => '', 'state' => 'none'];
            $write(self::row($siteIdentifier, $period, $person, [
                'beUser' => $uid > 0 ? (string)$uid : '',
                'name' => $account['name'],
                'username' => $account['username'],
                'account' => $account['state'],
            ]));
        }
        $write(self::row($siteIdentifier, $period, $report['totals'], ['beUser' => '', 'name' => $totalLabel, 'username' => '', 'account' => '']));
        rewind($stream);
        $content = (string)stream_get_contents($stream);
        fclose($stream);

        return [
            'filename' => sprintf('aqg-ai-usage-%s-%s.csv', self::filenamePart($siteIdentifier), $period),
            'content' => $content,
            'month' => $period,
            'report' => $report,
        ];
    }

    /**
     * @param array<string,mixed> $amounts a person row or the totals of the summary
     * @param array<string,string> $identity
     * @return list<string>
     */
    private static function row(string $site, string $month, array $amounts, array $identity): array
    {
        $features = [];
        foreach (is_array($amounts['features'] ?? null) ? $amounts['features'] : [] as $feature) {
            $features[] = $feature['feature'] . ':' . $feature['requests'];
        }

        return [
            $site,
            $month,
            $identity['beUser'],
            $identity['name'],
            $identity['username'],
            $identity['account'],
            (string)(int)$amounts['requests'],
            (string)(int)$amounts['succeeded'],
            (string)(int)$amounts['failed'],
            (string)(int)$amounts['pending'],
            (string)(int)$amounts['inputTokens'],
            (string)(int)$amounts['cachedInputTokens'],
            (string)(int)$amounts['outputTokens'],
            // An unknown price is not $0: the estimate is empty when no request of the row had a price.
            ($amounts['costState'] ?? '') === 'unknown' ? '' : self::usd((int)$amounts['estimatedMicroUsd']),
            self::usd((int)$amounts['boundMicroUsd']),
            (string)(int)$amounts['boundRequests'],
            (string)(int)$amounts['noCharge'],
            (string)(int)$amounts['unknown'],
            self::usd((int)$amounts['reservedMicroUsd']),
            implode('; ', $features),
        ];
    }

    /** Exact USD from micro-USD, six decimals, "." separator: "1.320000". */
    private static function usd(int $micro): string
    {
        $micro = max(0, $micro);

        return intdiv($micro, AiMoney::MICRO_PER_USD) . '.' . str_pad((string)($micro % AiMoney::MICRO_PER_USD), 6, '0', STR_PAD_LEFT);
    }

    private static function filenamePart(string $value): string
    {
        $part = trim((string)preg_replace('/[^A-Za-z0-9_-]+/', '-', $value), '-');

        return $part !== '' ? substr($part, 0, 60) : 'site';
    }
}
