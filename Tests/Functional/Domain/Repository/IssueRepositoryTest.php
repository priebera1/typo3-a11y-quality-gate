<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for IssueRepository.
 *
 * Tests the core contracts the scan engine depends on:
 *   - upsert() creates a new issue on first sight
 *   - upsert() updates last_seen_scan_uid on rescan (no duplicate)
 *   - upsert() re-opens Resolved issues found again
 *   - upsert() NEVER touches Ignored issues (isProtected contract)
 *   - resolveUnseen() marks Open/Resolved issues as Resolved when not in seenFingerprints
 *   - resolveUnseen() does NOT touch Ignored issues
 */
final class IssueRepositoryTest extends AbstractFunctionalTestCase
{
    private IssueRepository $subject;

    private const SITE = 'test-site';
    private const PAGE = 42;

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new IssueRepository(
            GeneralUtility::makeInstance(ConnectionPool::class)
        );
    }

    // -------------------------------------------------------------------------
    // upsert() — new issue
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function upsertInsertsNewIssueWithStatusOpen(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.img_alt_missing', '<img src="x.jpg">');

        $outcome = $this->subject->upsert($violation, $ctx, scanUid: 1);

        self::assertSame('inserted', $outcome);

        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertNotNull($row);
        self::assertSame(IssueStatus::Open->value, (int)$row['status']);
        self::assertSame(1, (int)$row['first_seen_scan_uid']);
        self::assertSame(1, (int)$row['last_seen_scan_uid']);
    }

    /**
     * @test
     */
    public function upsertOnRescanUpdatesLastSeenWithoutDuplicate(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.img_alt_missing', '<img src="x.jpg">');

        $this->subject->upsert($violation, $ctx, scanUid: 1);
        $outcome = $this->subject->upsert($violation, $ctx, scanUid: 2);

        self::assertSame('updated', $outcome);

        $all = $this->findAllIssuesByFingerprint($violation->fingerprint($ctx));
        self::assertCount(1, $all, 'There must be exactly one row — no duplicate inserted');

        $row = $all[0];
        self::assertSame(1, (int)$row['first_seen_scan_uid'], 'first_seen must not change on rescan');
        self::assertSame(2, (int)$row['last_seen_scan_uid'], 'last_seen must be updated to latest scan');
    }

    // -------------------------------------------------------------------------
    // upsert() — re-open resolved
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function upsertReOpensResolvedIssueWhenFoundAgain(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.empty_link', '<a href="#">click</a>');

        $this->subject->upsert($violation, $ctx, scanUid: 1);

        // Manually mark as Resolved (simulates resolveUnseen from scan 1)
        $this->setIssueStatus($violation->fingerprint($ctx), IssueStatus::Resolved);

        // Scan 2 finds it again
        $outcome = $this->subject->upsert($violation, $ctx, scanUid: 2);

        self::assertSame('updated', $outcome);
        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertSame(IssueStatus::Open->value, (int)$row['status'], 'Resolved issue must be re-opened');
        self::assertSame(2, (int)$row['last_seen_scan_uid']);
    }

    // -------------------------------------------------------------------------
    // upsert() — isProtected contract
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function upsertNeverOverwritesIgnoredIssue(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.empty_heading', '<h2></h2>');

        $this->subject->upsert($violation, $ctx, scanUid: 1);
        $this->setIssueStatus($violation->fingerprint($ctx), IssueStatus::Ignored);

        $outcome = $this->subject->upsert($violation, $ctx, scanUid: 2);

        self::assertSame('protected', $outcome, 'Ignored issue must return protected and be left untouched');

        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertSame(IssueStatus::Ignored->value, (int)$row['status'], 'Status must remain Ignored');
        self::assertSame(1, (int)$row['last_seen_scan_uid'], 'last_seen must NOT be updated for protected issues');
    }

    /**
     * @test
     */
    public function upsertNeverOverwritesMutedIssue(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.non_descriptive_link', '<a href="#">click here</a>');

        $this->subject->upsert($violation, $ctx, scanUid: 1);
        $this->setIssueStatus($violation->fingerprint($ctx), IssueStatus::Muted);

        $outcome = $this->subject->upsert($violation, $ctx, scanUid: 2);

        self::assertSame('protected', $outcome);
        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertSame(IssueStatus::Muted->value, (int)$row['status']);
    }

    // -------------------------------------------------------------------------
    // resolveUnseen()
    // -------------------------------------------------------------------------

    /**
     * @test
     */
    public function resolveUnseenMarksOpenIssuesAsResolvedWhenNotInSeenList(): void
    {
        [$v1, $ctx1] = $this->makeViolationAndContext('rte.img_alt_missing', '<img src="a.jpg">');
        [$v2, $ctx2] = $this->makeViolationAndContext('rte.img_alt_missing', '<img src="b.jpg">');

        $this->subject->upsert($v1, $ctx1, scanUid: 1);
        $this->subject->upsert($v2, $ctx2, scanUid: 1);

        // Scan 2 only sees v1 — v2 has been fixed
        $resolved = $this->subject->resolveUnseen(
            pageUid:          self::PAGE,
            siteIdentifier:   self::SITE,
            seenFingerprints: [$v1->fingerprint($ctx1)],
            scanUid:          2,
        );

        self::assertSame(1, $resolved, 'Exactly one issue should be marked resolved');

        $row2 = $this->findIssueByFingerprint($v2->fingerprint($ctx2));
        self::assertSame(IssueStatus::Resolved->value, (int)$row2['status']);

        $row1 = $this->findIssueByFingerprint($v1->fingerprint($ctx1));
        self::assertSame(IssueStatus::Open->value, (int)$row1['status'], 'Still-seen issue must remain Open');
    }

    /**
     * @test
     */
    public function resolveUnseenDoesNotTouchIgnoredIssues(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.empty_heading', '<h3></h3>');

        $this->subject->upsert($violation, $ctx, scanUid: 1);
        $this->setIssueStatus($violation->fingerprint($ctx), IssueStatus::Ignored);

        // Scan 2 does NOT see this issue (would normally resolve it)
        $resolved = $this->subject->resolveUnseen(
            pageUid:          self::PAGE,
            siteIdentifier:   self::SITE,
            seenFingerprints: [],
            scanUid:          2,
        );

        self::assertSame(0, $resolved, 'Ignored issues must not be counted as resolved');

        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertSame(IssueStatus::Ignored->value, (int)$row['status'], 'Ignored status must survive resolveUnseen');
    }

    // -------------------------------------------------------------------------
    // Test helpers
    // -------------------------------------------------------------------------

    /**
     * @return array{RuleViolation, CheckContext}
     */
    private function makeViolationAndContext(string $ruleId, string $snippet): array
    {
        $ctx = new CheckContext(
            siteIdentifier: self::SITE,
            pageUid:        self::PAGE,
            sourceLangUid:  0,
            sourceTable:    Tables::TT_CONTENT,
            sourceUid:      100,
            sourceField:    'bodytext',
            content:        $snippet,
            contextPath:    'Page:42 > tt_content:100 > bodytext',
        );

        $violation = new RuleViolation(
            ruleId:         $ruleId,
            severity:       Severity::Critical,
            message:        'Test violation',
            hint:           'Fix it.',
            contextSnippet: $snippet,
            contextPath:    'div > p > ' . strtok($snippet, ' '),
        );

        return [$violation, $ctx];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findIssueByFingerprint(string $fingerprint): ?array
    {
        $rows = $this->findAllIssuesByFingerprint($fingerprint);
        return $rows[0] ?? null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function findAllIssuesByFingerprint(string $fingerprint): array
    {
        $connection = $this->getTestConnectionPool()->getConnectionForTable(Tables::ISSUE);

        return $connection
            ->select(
                ['uid', 'status', 'first_seen_scan_uid', 'last_seen_scan_uid'],
                Tables::ISSUE,
                ['fingerprint' => $fingerprint, 'site_identifier' => self::SITE]
            )
            ->fetchAllAssociative();
    }

    private function setIssueStatus(string $fingerprint, IssueStatus $status): void
    {
        $connection = $this->getTestConnectionPool()->getConnectionForTable(Tables::ISSUE);
        $connection->update(
            Tables::ISSUE,
            ['status' => $status->value],
            ['fingerprint' => $fingerprint, 'site_identifier' => self::SITE]
        );
    }

    private function getTestConnectionPool(): \TYPO3\CMS\Core\Database\ConnectionPool
    {
        return GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class);
    }
}
