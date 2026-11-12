<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Usage;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Service\AiModelCompatibilityRegistry;
use Priebera\A11yQualityGate\Ai\Settings\AiBackendUserNames;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageCsvExporter;
use Priebera\A11yQualityGate\Ai\Usage\AiUsageSummaryService;
use Priebera\A11yQualityGate\Domain\Repository\Contract\AiUsageRepositoryInterface;

/**
 * "Export CSV" of the AI usage report: built from the same summary the page shows, so its rows reconcile with the site
 * total; exact amounts; one site and month only; account states for deleted/disabled/removed users; formula injection
 * neutralised; nothing but counts, tokens and amounts.
 */
final class AiUsageCsvExporterTest extends TestCase
{
    private function repository(): AiUsageRepositoryDouble
    {
        $repository = new AiUsageRepositoryDouble();
        $now = (new \DateTimeImmutable('first day of this month'))->setTime(0, 0)->modify('+1 day')->getTimestamp();
        $row = static fn (int $user, string $outcome, int $cost, string $basis, int $reserved = 0, int $known = 1, string $site = 'client-b', ?int $crdate = null): array => [
            'site_identifier' => $site, 'be_user' => $user, 'feature' => 'alt_text', 'provider' => 'openai', 'model' => 'gpt-5.4-mini',
            'outcome' => $outcome, 'input_tokens' => $basis === 'reported' ? 1000 : 0, 'output_tokens' => $basis === 'reported' ? 100 : 0,
            'usage_reported' => $basis === 'reported' ? 1 : 0, 'cache_read_tokens' => 0, 'estimated_cost_micro_usd' => $cost, 'cost_known' => $known,
            'cost_basis' => $basis, 'reserved_cost_micro_usd' => $reserved, 'crdate' => $crdate ?? $now,
            'conversation_ref' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        ];
        foreach ([
            $row(3, 'success', 6_000, 'reported'),
            $row(3, 'failed_1771002330', 900_000, 'bound'),
            $row(4, 'success', 6_000, 'reported'), // deleted account
            $row(5, 'pending', 0, 'pending', 2_000_000), // disabled account
            $row(6, 'success', 0, 'unknown', 0, 0), // formula-like name, no price
            $row(0, 'failed_1771002310', 0, 'none'), // no backend user
            $row(3, 'success', 7_000_000, 'reported', 0, 1, 'other-site'),
            $row(3, 'success', 5_000_000, 'reported', 0, 1, 'client-b', (new \DateTimeImmutable('first day of last month'))->getTimestamp()),
        ] as $usage) {
            $repository->add($usage);
        }

        return $repository;
    }

    private function exporter(?AiUsageRepositoryInterface $repository = null): AiUsageCsvExporter
    {
        $names = $this->createMock(AiBackendUserNames::class);
        $names->method('describe')->willReturnCallback(static fn (int $uid): array => [
            3 => ['name' => 'Eva Editor', 'username' => 'eva', 'state' => 'active'],
            4 => ['name' => 'Greta Gone', 'username' => 'gone', 'state' => 'deleted'],
            5 => ['name' => 'paused', 'username' => 'paused', 'state' => 'disabled'],
            6 => ['name' => '=HYPERLINK("https://evil.test","x")', 'username' => '+cmd', 'state' => 'active'],
        ][$uid] ?? ['name' => '#' . $uid, 'username' => '', 'state' => 'missing']);

        return new AiUsageCsvExporter(new AiUsageSummaryService($repository ?? $this->repository(), new AiModelCompatibilityRegistry()), $names);
    }

    /** @return list<list<string>> */
    private function rows(string $csv): array
    {
        $lines = [];
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);
        while (($line = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $lines[] = $line;
        }
        fclose($stream);

        return $lines;
    }

    #[Test]
    public function rowsReconcileWithTheReportAndCarryExactAmounts(): void
    {
        $export = $this->exporter()->export('client-b', '', static fn (string $column): string => 'H:' . $column, 'Site total');
        $rows = $this->rows($export['content']);
        $header = array_shift($rows);
        $total = array_pop($rows);

        self::assertSame(array_map(static fn (string $c): string => 'H:' . $c, AiUsageCsvExporter::COLUMNS), $header);
        self::assertSame('aqg-ai-usage-client-b-' . (new \DateTimeImmutable())->format('Y-m') . '.csv', $export['filename']);
        self::assertCount(5, $rows, 'Five people; another site and another month are not exported.');
        $byUser = [];
        foreach ($rows as $row) {
            $byUser[$row[2]] = array_combine(AiUsageCsvExporter::COLUMNS, $row);
        }
        $total = array_combine(AiUsageCsvExporter::COLUMNS, $total);

        self::assertSame('Site total', $total['name']);
        self::assertSame('6', $total['requests']);
        self::assertSame('0.912000', $total['estimatedUsd'], 'Exact: 0.006 + 0.900 + 0.006, not rounded per row.');
        self::assertSame('0.900000', $total['worstCaseUsd']);
        self::assertSame('2.000000', $total['reservedUsd']);
        self::assertSame('1', $total['rejectedBeforeProcessing']);
        self::assertSame('1', $total['withoutPrice']);
        foreach (['requests', 'succeeded', 'failed', 'pending', 'inputTokens', 'outputTokens'] as $column) {
            self::assertSame((int)$total[$column], array_sum(array_map(static fn (array $row): int => (int)$row[$column], $byUser)), $column);
        }
        self::assertSame(912_000, (int)array_sum(array_map(static fn (array $row): int => (int)round((float)$row['estimatedUsd'] * 1_000_000), $byUser)));

        self::assertSame(['Eva Editor', 'eva', 'active', '0.906000', '1', '1'], [$byUser['3']['name'], $byUser['3']['username'], $byUser['3']['account'], $byUser['3']['estimatedUsd'], $byUser['3']['failed'], $byUser['3']['worstCaseRequests']]);
        self::assertSame(['Greta Gone', 'deleted', '0.006000'], [$byUser['4']['name'], $byUser['4']['account'], $byUser['4']['estimatedUsd']]);
        self::assertSame(['disabled', '1', '0.000000', '2.000000'], [$byUser['5']['account'], $byUser['5']['pending'], $byUser['5']['estimatedUsd'], $byUser['5']['reservedUsd']]);
        self::assertSame('', $byUser['6']['estimatedUsd'], 'No price: empty, never 0.000000.');
        self::assertSame('1', $byUser['6']['withoutPrice']);
        self::assertSame(['', 'none', '1'], [$byUser['']['name'], $byUser['']['account'], $byUser['']['rejectedBeforeProcessing']]);
        self::assertSame('alt_text:2', $byUser['3']['features']);
    }

    #[Test]
    public function formulaInjectionIsNeutralisedAndNoContentIsExported(): void
    {
        $content = $this->exporter()->export('client-b', '', static fn (string $c): string => $c, 'Site total')['content'];

        self::assertStringContainsString('"\'=HYPERLINK(""https://evil.test"",""x"")"', $content);
        self::assertStringContainsString("'+cmd", $content);
        self::assertStringNotContainsString('aaaaaaaaaaaaaaaa', $content, 'No conversation id.');
        foreach ($this->rows($content) as $row) {
            foreach ($row as $cell) {
                self::assertDoesNotMatchRegularExpression('/^[=+\-@\t\r]/', $cell === '' || is_numeric($cell) ? '' : $cell);
            }
        }
    }

    #[Test]
    public function anEarlierMonthAndAnUnsafeSiteNameAreHandled(): void
    {
        $previous = (new \DateTimeImmutable('first day of last month'))->format('Y-m');
        $export = $this->exporter()->export('client-b', $previous, static fn (string $c): string => $c, 'Site total');
        $rows = $this->rows($export['content']);
        self::assertSame($previous, $export['month']);
        self::assertCount(3, $rows, 'Header, one person, total.');
        self::assertSame('5.000000', $rows[2][13]);

        $odd = $this->exporter(new AiUsageRepositoryDouble())->export('../ä b"; x', '', static fn (string $c): string => $c, 'Site total');
        self::assertMatchesRegularExpression('/^aqg-ai-usage-[A-Za-z0-9_-]+-\d{4}-\d{2}\.csv$/', $odd['filename']);
    }

    #[Test]
    public function aFailedReadIsNotExportedAsAnEmptyMonth(): void
    {
        $repository = $this->createMock(AiUsageRepositoryInterface::class);
        $repository->method('breakdown')->willThrowException(new \RuntimeException('db'));

        $this->expectException(\RuntimeException::class);
        $this->exporter($repository)->export('client-b', '', static fn (string $c): string => $c, 'Site total');
    }
}
