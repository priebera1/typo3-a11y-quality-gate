<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\FixVerificationRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteIssueRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Service\FixVerificationService;
use Priebera\A11yQualityGate\Service\RemoteScanFindingIndex;
use Priebera\A11yQualityGate\Service\ScopeAccessService;

/**
 * A finding is only "Resolved" on a complete fresh result of the same URL. A failed or incomplete scan is
 * "Not verified", never a resolution.
 */
final class FixVerificationServiceTest extends TestCase
{
    private const URL = 'https://example.org/about';

    /** @var list<array{0:int,1:string,2:string,3:int,4:int}> */
    private array $stored = [];

    /**
     * @return iterable<string, array{0:array<string, mixed>|null, 1:array<string, mixed>, 2:string, 3:string}>
     */
    public static function outcomeProvider(): iterable
    {
        $completed = ['uid' => 90, 'status' => 'completed', 'persisted_at' => 1700000100];
        $page = static fn (array $rules, bool $failed = false, bool $complete = true): array => [
            'https://example.org/about' => [
                'url' => self::URL,
                'remotePageUid' => 900,
                'failed' => $failed,
                'httpStatus' => $failed ? 500 : 200,
                'evidenceComplete' => $complete,
                'rules' => $rules,
            ],
        ];

        yield 'rule gone on a complete result' => [$completed, $page([]), 'resolved', ''];
        yield 'rule still reported' => [$completed, $page(['image-alt' => ['occurrences' => 3, 'impact' => 'critical']]), 'still_present', ''];
        yield 'still reported even when other details are missing' => [$completed, $page(['image-alt' => ['occurrences' => 1, 'impact' => 'critical']], false, false), 'still_present', ''];
        yield 'page failed to load' => [$completed, $page([], true, false), 'not_verified', 'page_failed'];
        yield 'findings of the page not stored' => [$completed, $page([], false, false), 'not_verified', 'evidence_incomplete'];
        yield 'URL missing from the result' => [$completed, [], 'not_verified', 'page_missing'];
        yield 'scan failed' => [['uid' => 90, 'status' => 'failed', 'persisted_at' => 0], [], 'not_verified', 'scan_failed'];
        yield 'scan cancelled' => [['uid' => 90, 'status' => 'cancelled', 'persisted_at' => 0], [], 'not_verified', 'scan_cancelled'];
        yield 'scan still running' => [['uid' => 90, 'status' => 'running', 'persisted_at' => 0], [], 'pending', ''];
        yield 'scan completed but not yet stored' => [['uid' => 90, 'status' => 'completed', 'persisted_at' => 0], [], 'pending', ''];
    }

    /**
     * @param array<string, mixed>|null $scan
     * @param array<string, mixed> $index
     */
    #[DataProvider('outcomeProvider')]
    #[Test]
    public function outcomeFollowsTheFreshResult(?array $scan, array $index, string $expectedOutcome, string $expectedReason): void
    {
        $result = $this->service($scan, $index)->evaluate($this->pendingVerification());

        self::assertSame($expectedOutcome, $result['outcome']);
        self::assertSame($expectedReason, (string)($result['outcome_reason'] ?? ''));
        if ($expectedOutcome === 'pending') {
            self::assertSame([], $this->stored, 'A pending verification is not decided or stored.');
        } else {
            self::assertCount(1, $this->stored);
            self::assertSame(90, $this->stored[0][3], 'The verification records the scan that decided it.');
        }
    }

    #[Test]
    public function aDecidedVerificationIsNotEvaluatedAgain(): void
    {
        $decided = ['uid' => 5, 'outcome' => 'resolved', 'outcome_reason' => '', 'verification_job_id' => 'job'];

        self::assertSame($decided, $this->service(['uid' => 90, 'status' => 'failed'], [])->evaluate($decided));
        self::assertSame([], $this->stored);
    }

    #[Test]
    public function aFindingOfAScanTheUserCannotReadIsNotResolved(): void
    {
        $issues = $this->createMock(RemoteIssueRepository::class);
        $issues->method('findOneByUid')->willReturn(['uid' => 1, 'remote_scan' => 90, 'remote_scan_page' => 900, 'rule_id' => 'image-alt']);
        $scans = $this->createMock(RemoteScanRepository::class);
        $scans->method('findPageByUid')->willReturn(['uid' => 900, 'remote_scan' => 90, 'url' => self::URL]);
        $scope = $this->createMock(ScopeAccessService::class);
        $scope->method('resolveReadableScanForRemotePage')->willReturn(null);

        $service = new FixVerificationService(
            $this->createMock(FixVerificationRepository::class),
            $issues,
            $scans,
            $this->createMock(RemoteScanFindingIndex::class),
            $scope,
        );

        self::assertNull($service->resolveFinding(1));
    }

    #[Test]
    public function presentedOutcomesAreNamedForTheEditor(): void
    {
        $service = $this->service(null, []);
        $labels = [];
        foreach (['resolved', 'still_present', 'not_verified'] as $outcome) {
            $labels[$outcome] = $service->presentOutcome(['outcome' => $outcome, 'outcome_reason' => 'page_failed'], static fn (int $uid): string => '')['label'];
        }

        self::assertSame(['resolved' => 'Resolved', 'still_present' => 'Still present', 'not_verified' => 'Not verified'], $labels);
    }

    #[Test]
    public function anOlderResolvedVerdictBesideANewerScanIsSupersededNotReassurance(): void
    {
        $resolved = ['uid' => 5, 'outcome' => 'resolved', 'rule_id' => 'image-alt', 'evaluated_at' => 1700000000, 'site_identifier' => 'main'];
        $service = $this->service(null, []);

        $besideNewerScan = $service->presentOutcome($resolved, static fn (int $uid): string => '', 1700009999);
        self::assertSame('superseded', $besideNewerScan['outcome']);
        self::assertStringNotContainsString('no longer reports', $besideNewerScan['message']);

        $besideOlderScan = $service->presentOutcome($resolved, static fn (int $uid): string => '', 1690000000);
        self::assertSame('resolved', $besideOlderScan['outcome']);

        $pending = $service->presentOutcome(['uid' => 6, 'outcome' => 'pending', 'verification_job_id' => 'job-1', 'site_identifier' => 'main'], static fn (int $uid): string => '', 1700009999);
        self::assertSame('pending', $pending['outcome']);
        self::assertSame(['job-1', 'main', ''], [$pending['verificationJobId'], $pending['siteIdentifier'], $pending['evaluatedAt']]);
    }

    #[Test]
    public function partiallyStoredFindingsNeverProveAFix(): void
    {
        // The crawler reported two issue types on the page, only one of them was stored, and the finding's own
        // rule is the missing one. Its absence proves nothing: Not verified, never Resolved.
        $index = $this->realIndex(issuesCount: 2, storedRules: ['color-contrast']);
        $this->assertOutcome('not_verified', 'evidence_incomplete', $this->service(self::completedScan(), $index->build(self::completedScan()))->evaluate($this->pendingVerification()));

        $this->stored = [];
        $none = $this->realIndex(issuesCount: 1, storedRules: []);
        $this->assertOutcome('not_verified', 'evidence_incomplete', $this->service(self::completedScan(), $none->build(self::completedScan()))->evaluate($this->pendingVerification()));
    }

    #[Test]
    public function onlyACompletelyStoredResultWithoutTheRuleIsResolved(): void
    {
        $index = $this->realIndex(issuesCount: 2, storedRules: ['color-contrast', 'label']);
        $this->assertOutcome('resolved', '', $this->service(self::completedScan(), $index->build(self::completedScan()))->evaluate($this->pendingVerification()));

        $this->stored = [];
        $clean = $this->realIndex(issuesCount: 0, storedRules: []);
        $this->assertOutcome('resolved', '', $this->service(self::completedScan(), $clean->build(self::completedScan()))->evaluate($this->pendingVerification()));

        $this->stored = [];
        $present = $this->realIndex(issuesCount: 3, storedRules: ['image-alt']);
        $this->assertOutcome('still_present', '', $this->service(self::completedScan(), $present->build(self::completedScan()))->evaluate($this->pendingVerification()));
    }

    #[Test]
    public function aScanOfAnotherLanguageVerifiesNothing(): void
    {
        $scan = ['language_uid' => 1] + self::completedScan();
        $index = $this->realIndex(issuesCount: 0, storedRules: []);

        $result = $this->service($scan, $index->build($scan))->evaluate(['language_uid' => 0] + $this->pendingVerification());

        $this->assertOutcome('not_verified', 'scope_mismatch', $result);
    }

    /**
     * @param array<string, mixed> $result
     */
    private function assertOutcome(string $outcome, string $reason, array $result): void
    {
        self::assertSame($outcome, $result['outcome']);
        self::assertSame($reason, (string)($result['outcome_reason'] ?? ''));
    }

    /** @return array<string, mixed> */
    private static function completedScan(): array
    {
        return ['uid' => 90, 'status' => 'completed', 'persisted_at' => 1700000100, 'language_uid' => 0];
    }

    /**
     * The real index over stored rows: one page with the crawler's issue type count and the rules stored for it.
     *
     * @param list<string> $storedRules
     */
    private function realIndex(int $issuesCount, array $storedRules): RemoteScanFindingIndex
    {
        $scans = $this->createMock(RemoteScanRepository::class);
        $scans->method('findAllPagesForScan')->willReturn([
            ['uid' => 900, 'url' => self::URL, 'is_failed' => 0, 'http_status' => 200, 'issues_count' => $issuesCount],
        ]);
        $issues = $this->createMock(RemoteIssueRepository::class);
        $issues->method('findIssueRowsForRemoteScan')->willReturn(array_map(
            static fn (string $ruleId): array => ['remote_scan_page' => 900, 'rule_id' => $ruleId, 'nodes_count' => 1, 'impact' => 'serious'],
            $storedRules
        ));

        return new RemoteScanFindingIndex($scans, $issues);
    }

    /** @return array<string, mixed> */
    private function pendingVerification(): array
    {
        return [
            'uid' => 5,
            'site_identifier' => 'main',
            'rule_id' => 'image-alt',
            'url' => self::URL,
            'verification_job_id' => '11111111-1111-4111-8111-111111111111',
            'outcome' => 'pending',
            'baseline_occurrences' => 3,
        ];
    }

    /**
     * @param array<string, mixed>|null $scan
     * @param array<string, mixed> $index
     */
    private function service(?array $scan, array $index): FixVerificationService
    {
        $repository = $this->createMock(FixVerificationRepository::class);
        $repository->method('storeOutcome')->willReturnCallback(function (int $uid, string $outcome, string $reason, int $scanUid, int $remaining): void {
            $this->stored[] = [$uid, $outcome, $reason, $scanUid, $remaining];
        });
        $scans = $this->createMock(RemoteScanRepository::class);
        $scans->method('findScanByJobId')->willReturn($scan);
        $findingIndex = $this->createMock(RemoteScanFindingIndex::class);
        $findingIndex->method('build')->willReturn($index);

        return new FixVerificationService(
            $repository,
            $this->createMock(RemoteIssueRepository::class),
            $scans,
            $findingIndex,
            $this->createMock(ScopeAccessService::class),
        );
    }
}
