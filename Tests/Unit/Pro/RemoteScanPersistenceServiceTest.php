<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Pro;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueNodeRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanPersistenceService;
use Priebera\A11yQualityGate\Service\DateTimeService;

/**
 * A stored scan counts as evidence once `persisted_at` is set, so it is set last: after every page and finding
 * of the result is stored. An interrupted persist leaves the scan "completed, not persisted" for recovery —
 * never a complete-looking scan with part of its findings.
 */
final class RemoteScanPersistenceServiceTest extends TestCase
{
    /** @var list<string> */
    private array $steps = [];

    #[Test]
    public function theScanIsMarkedPersistedOnlyAfterItsPagesAndFindingsAreStored(): void
    {
        $this->service()->persistResults('main', 'job-1', RemoteScanSourceType::SinglePage, 'https://example.org/', null, $this->results());

        self::assertSame(['upsert:unpersisted', 'pages', 'issue:image-alt', 'issue:label', 'persisted'], $this->steps);
    }

    #[Test]
    public function anInterruptedPersistNeverMarksTheScanPersisted(): void
    {
        try {
            $this->service(failOnRule: 'label')->persistResults('main', 'job-1', RemoteScanSourceType::SinglePage, 'https://example.org/', null, $this->results());
            self::fail('The interruption must surface.');
        } catch (\RuntimeException) {
        }

        self::assertNotContains('persisted', $this->steps);
        self::assertSame('upsert:unpersisted', $this->steps[0]);
    }

    private function service(string $failOnRule = ''): RemoteScanPersistenceService
    {
        $scans = $this->createMock(RemoteScanRepository::class);
        $scans->method('upsertScan')->willReturnCallback(function (...$arguments): int {
            // persistedAt is the 18th parameter of upsertScan().
            $this->steps[] = (int)($arguments[17] ?? -1) === 0 ? 'upsert:unpersisted' : 'upsert:persisted';
            return 5;
        });
        $scans->method('saveScanPages')->willReturnCallback(function (): array {
            $this->steps[] = 'pages';
            return ['https://example.org/' => 50];
        });
        $scans->method('markPersisted')->willReturnCallback(function (int $uid): void {
            self::assertSame(5, $uid);
            $this->steps[] = 'persisted';
        });

        $issues = $this->createMock(RemoteIssueRepository::class);
        $issues->method('findUidsByRemoteScan')->willReturn([]);
        $issues->method('saveIssue')->willReturnCallback(function (int $remoteScanUid, int $remoteScanPageUid, array $issue) use ($failOnRule): int {
            if ($issue['ruleId'] === $failOnRule) {
                throw new \RuntimeException('Database connection lost');
            }
            $this->steps[] = 'issue:' . $issue['ruleId'];
            return 7;
        });

        return new RemoteScanPersistenceService($scans, $issues, $this->createMock(RemoteIssueNodeRepository::class), $this->createMock(DateTimeService::class));
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        return [
            'status' => 'completed',
            'pagesScanned' => 1,
            'issuesTotal' => 2,
            'languageUid' => 0,
            'pages' => [[
                'url' => 'https://example.org/',
                'httpStatus' => 200,
                'issuesCount' => 2,
                'issues' => [
                    ['ruleId' => 'image-alt', 'impact' => 'critical', 'nodes' => []],
                    ['ruleId' => 'label', 'impact' => 'serious', 'nodes' => []],
                ],
            ]],
        ];
    }
}
