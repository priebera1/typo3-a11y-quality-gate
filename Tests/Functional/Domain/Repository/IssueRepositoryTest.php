<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\IssueStatus;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Functional tests for IssueRepository.
 *
 * Tests the core contracts the scan engine depends on:
 *   - upsert() creates a new issue on first sight
 *   - upsert() updates last_seen_scan_uid on rescan (no duplicate)
 *   - upsert() re-opens Resolved issues found again
 *   - upsert() NEVER touches Ignored issues (isProtected contract)
 *   - resolveUnseen() marks Open issues as Resolved when not in seenFingerprints (already resolved ones stay as they are)
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
        $this->subject = $this->createIssueRepository();
    }

    // -------------------------------------------------------------------------
    // upsert() — new issue
    // -------------------------------------------------------------------------

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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

    #[Test]
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
            sourceLangUid:    0,
            seenFingerprints: [$v1->fingerprint($ctx1)],
            scanUid:          2,
        );

        self::assertSame(1, $resolved, 'Exactly one issue should be marked resolved');

        $row2 = $this->findIssueByFingerprint($v2->fingerprint($ctx2));
        self::assertSame(IssueStatus::Resolved->value, (int)$row2['status']);

        $row1 = $this->findIssueByFingerprint($v1->fingerprint($ctx1));
        self::assertSame(IssueStatus::Open->value, (int)$row1['status'], 'Still-seen issue must remain Open');
    }

    #[Test]
    public function resolveUnseenDoesNotResolveAnAlreadyResolvedIssueAgain(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.img_alt_missing', '<img src="c.jpg">');
        $this->subject->upsert($violation, $ctx, scanUid: 1);
        self::assertSame(1, $this->subject->resolveUnseen(self::PAGE, self::SITE, 0, [], 2), 'Scan 2: the fixed issue resolves once.');
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->update('tx_a11y_issue', ['resolved_at' => 1000, 'tstamp' => 1000], ['fingerprint' => $violation->fingerprint($ctx)]);

        // Regression guard: every unchanged scan after the fix counted the issue as resolved again ("resolved -1")
        // and moved its resolution time and author.
        $resolved = $this->subject->resolveUnseen(self::PAGE, self::SITE, 0, [], 3);

        self::assertSame(0, $resolved, 'Scan 3 (unchanged) resolves nothing.');
        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertSame(IssueStatus::Resolved->value, (int)$row['status']);
        self::assertSame(1000, (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['resolved_at'], 'tx_a11y_issue', ['fingerprint' => $violation->fingerprint($ctx)])->fetchOne(), 'The resolution keeps its original time.');
    }

    #[Test]
    public function resolveUnseenDoesNotTouchIgnoredIssues(): void
    {
        [$violation, $ctx] = $this->makeViolationAndContext('rte.empty_heading', '<h3></h3>');

        $this->subject->upsert($violation, $ctx, scanUid: 1);
        $this->setIssueStatus($violation->fingerprint($ctx), IssueStatus::Ignored);

        // Scan 2 does NOT see this issue (would normally resolve it)
        $resolved = $this->subject->resolveUnseen(
            pageUid:          self::PAGE,
            siteIdentifier:   self::SITE,
            sourceLangUid:    0,
            seenFingerprints: [],
            scanUid:          2,
        );

        self::assertSame(0, $resolved, 'Ignored issues must not be counted as resolved');

        $row = $this->findIssueByFingerprint($violation->fingerprint($ctx));
        self::assertSame(IssueStatus::Ignored->value, (int)$row['status'], 'Ignored status must survive resolveUnseen');
    }

    /**
     * Audit C6: a rendered finding that duplicates a rich-text finding of the same element is retired (deleted = 1).
     * Nothing was fixed, so the following resolve step must neither resolve nor count it.
     */
    #[Test]
    public function aRetiredRenderedDuplicateIsNotResolvedOrCounted(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: self::SITE,
            pageUid: self::PAGE,
            sourceLangUid: 0,
            sourceTable: Tables::PAGES,
            sourceUid: self::PAGE,
            sourceField: '__rendered_html',
            content: '',
            contextPath: 'Page:42 > rendered HTML',
            sourceType: 'rendered',
        );
        $duplicate = new RuleViolation(
            ruleId: 'rendered.empty_heading',
            severity: Severity::Critical,
            message: 'Rendered heading is empty.',
            hint: 'Fix it.',
            contextSnippet: '<h2></h2>',
            contextPath: 'body > div > h2',
            sourceType: 'rendered',
            sourceTable: Tables::TT_CONTENT,
            sourceUid: 100,
            sourceField: '__rendered_html',
        );
        $this->subject->upsert($duplicate, $ctx, scanUid: 1);

        self::assertSame(1, $this->subject->retireOpenDuplicates(self::SITE, self::PAGE, [$duplicate->fingerprint($ctx)]));
        $resolved = $this->subject->resolveUnseen(self::PAGE, self::SITE, 0, [], 2);

        self::assertSame(0, $resolved, 'A retired duplicate is not a resolved issue.');
        // Raw query: the select() helpers apply the deleted restriction and would not see the retired row.
        $row = $this->getTestConnectionPool()->getConnectionForTable(Tables::ISSUE)
            ->executeQuery('SELECT status, deleted FROM tx_a11y_issue WHERE fingerprint = ?', [$duplicate->fingerprint($ctx)])
            ->fetchAssociative();
        self::assertSame(IssueStatus::Open->value, (int)$row['status']);
        self::assertSame(1, (int)$row['deleted'], 'The row stays as evidence.');
    }

    // -------------------------------------------------------------------------
    // upsert() — findings recorded before 1.9.9 keep their identity
    // -------------------------------------------------------------------------

    /**
     * Before 1.9.9 rich text was read as ISO-8859-1 ("Predvolen&#xC3;&#xBD;"). The first 1.9.9 scan writes the
     * repaired snippet, which changes the fingerprint: the stored finding is the same element and must not be reported
     * as resolved and again as new.
     */
    #[Test]
    public function aFindingStoredWithByteEntitiesKeepsItsIdentityAfterTheSnippetRepair(): void
    {
        [$legacy, $ctx] = $this->makeViolationAndContext('rte.form_control_missing_label', '<textarea name="msg">Predvolen&#xC3;&#xBD; text</textarea>');
        $this->subject->upsert($legacy, $ctx, scanUid: 1);
        [$current] = $this->makeViolationAndContext('rte.form_control_missing_label', '<textarea name="msg">Predvolen&#xFD; text</textarea>');

        $outcome = $this->subject->upsert($current, $ctx, scanUid: 2);
        $resolved = $this->subject->resolveUnseen(self::PAGE, self::SITE, 0, [$current->fingerprint($ctx)], 2);

        self::assertSame('updated', $outcome, 'Not a new finding.');
        self::assertSame(0, $resolved, 'Nothing was fixed.');
        $row = $this->findIssueByFingerprint($current->fingerprint($ctx));
        self::assertNotNull($row, 'The stored finding carries the new fingerprint.');
        self::assertSame(IssueStatus::Open->value, (int)$row['status']);
        self::assertSame(1, (int)$row['first_seen_scan_uid'], 'Its history is kept.');
        self::assertSame(2, (int)$row['last_seen_scan_uid']);
        self::assertNull($this->findIssueByFingerprint($legacy->fingerprint($ctx)));
    }

    #[Test]
    public function anIgnoredFindingStoredWithByteEntitiesStaysIgnored(): void
    {
        [$legacy, $ctx] = $this->makeViolationAndContext('rte.duplicate_id', '<p id="sekcia-1">Duplicitn&#xC3;&#xA9; ID</p>');
        $this->subject->upsert($legacy, $ctx, scanUid: 1);
        $this->setIssueStatus($legacy->fingerprint($ctx), IssueStatus::Ignored);
        [$current] = $this->makeViolationAndContext('rte.duplicate_id', '<p id="sekcia-1">Duplicitn&#xE9; ID</p>');

        $outcome = $this->subject->upsert($current, $ctx, scanUid: 2);

        self::assertSame('protected', $outcome, 'The ignore decision is kept, not replaced by a new open finding.');
        $rows = $this->getTestConnectionPool()->getConnectionForTable(Tables::ISSUE)
            ->executeQuery('SELECT status, fingerprint, last_seen_scan_uid FROM tx_a11y_issue WHERE site_identifier = ? AND deleted = 0', [self::SITE])
            ->fetchAllAssociative();
        self::assertCount(1, $rows);
        self::assertSame(IssueStatus::Ignored->value, (int)$rows[0]['status']);
        self::assertSame($current->fingerprint($ctx), $rows[0]['fingerprint']);
        self::assertSame(1, (int)$rows[0]['last_seen_scan_uid'], 'A protected finding is not touched otherwise.');
    }

    /**
     * Rendered findings of the page element stored the whole marked-up body; 1.9.9 stores the opening tag only.
     */
    #[Test]
    public function aRenderedPageFindingStoredWithRecordMarkersKeepsItsIdentity(): void
    {
        $ctx = new CheckContext(
            siteIdentifier: self::SITE,
            pageUid: self::PAGE,
            sourceLangUid: 0,
            sourceTable: Tables::PAGES,
            sourceUid: self::PAGE,
            sourceField: '__rendered_html',
            content: '',
            contextPath: 'Page:42 > rendered HTML',
            sourceType: 'rendered',
        );
        $rendered = static fn (string $snippet): RuleViolation => new RuleViolation(
            ruleId: 'rendered.main_landmark_missing',
            severity: Severity::Warning,
            message: 'Rendered page has no main landmark.',
            hint: 'Add a main element.',
            contextSnippet: $snippet,
            contextPath: 'body',
            sourceType: 'rendered',
            cssSelector: 'body:nth-of-type(1)',
        );
        $legacy = $rendered('<body data-aqg-debug="1"> <div class="aqg-marker" data-aqg-table="tt_content" data-aqg-uid="937"><h2></h2></div>');
        $current = $rendered('<body>');
        $this->subject->upsert($legacy, $ctx, scanUid: 1);

        self::assertSame('updated', $this->subject->upsert($current, $ctx, scanUid: 2));
        self::assertSame(0, $this->subject->resolveUnseen(self::PAGE, self::SITE, 0, [$current->fingerprint($ctx)], 2));
    }

    /**
     * Two elements of one field: a stored finding that already matched this scan is never taken for another element.
     */
    #[Test]
    public function aLegacyFindingIsMatchedToOneElementOnly(): void
    {
        [$legacy, $ctx] = $this->makeViolationAndContext('rte.empty_heading', '<h2 class="a">J&#xC3;&#xA1;n</h2>');
        $this->subject->upsert($legacy, $ctx, scanUid: 1);
        [$current] = $this->makeViolationAndContext('rte.empty_heading', '<h2 class="a">J&#xE1;n</h2>');
        [$other] = $this->makeViolationAndContext('rte.empty_heading', '<h2 class="a">Peter</h2>');

        self::assertSame('updated', $this->subject->upsert($current, $ctx, scanUid: 2));
        self::assertSame('inserted', $this->subject->upsert($other, $ctx, scanUid: 2));
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
