<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\SourceStateRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Functional tests for SourceStateRepository.
 *
 * Tests the --changed-only scan optimization:
 *   - isUnchanged() returns false on first sight (no row yet)
 *   - isUnchanged() returns true after hash is stored unchanged
 *   - isUnchanged() returns false after content changes
 *   - upsertHash() inserts on first call, updates on subsequent calls
 *   - deleteForPage() removes all rows for a given page
 */
final class SourceStateRepositoryTest extends AbstractFunctionalTestCase
{
    private SourceStateRepository $subject;

    private const SITE  = 'test-site';
    private const TABLE = 'tt_content';
    private const FIELD = 'bodytext';

    protected function setUp(): void
    {
        parent::setUp();
        $this->subject = new SourceStateRepository(
            GeneralUtility::makeInstance(ConnectionPool::class)
        );
    }

    /**
     * @test
     */
    public function isUnchangedReturnsFalseWhenNoRowExists(): void
    {
        $result = $this->subject->isUnchanged(self::SITE, self::TABLE, 1, self::FIELD, 0, 'abc123');

        self::assertFalse($result, 'Unknown source must be treated as changed (safe default)');
    }

    /**
     * @test
     */
    public function isUnchangedReturnsTrueAfterSameHashStored(): void
    {
        $hash = sha1('<p>Hello world</p>');

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 1,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: $hash,
            scanUid: 1
        );

        self::assertTrue(
            $this->subject->isUnchanged(self::SITE, self::TABLE, 1, self::FIELD, 0, $hash)
        );
    }

    /**
     * @test
     */
    public function isUnchangedReturnsFalseAfterContentChanges(): void
    {
        $oldHash = sha1('<p>Old content</p>');
        $newHash = sha1('<p>New content with missing alt <img src="x.jpg"></p>');

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 2,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: $oldHash,
            scanUid: 1
        );

        self::assertFalse(
            $this->subject->isUnchanged(self::SITE, self::TABLE, 2, self::FIELD, 0, $newHash)
        );
    }

    /**
     * @test
     */
    public function upsertHashInsertsOnFirstCall(): void
    {
        $hash = sha1('content');

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 5,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: $hash,
            scanUid: 1
        );

        $rows = $this->fetchRows(5, 0);
        self::assertCount(1, $rows, 'Exactly one row must be inserted');
        self::assertSame($hash, $rows[0]['content_hash']);
        self::assertSame(1, (int)$rows[0]['last_scan_uid']);
    }

    /**
     * @test
     */
    public function upsertHashUpdatesOnSubsequentCall(): void
    {
        $hash1 = sha1('first');
        $hash2 = sha1('second');

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 6,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: $hash1,
            scanUid: 1
        );

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 6,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: $hash2,
            scanUid: 2
        );

        $rows = $this->fetchRows(6, 0);
        self::assertCount(1, $rows, 'No duplicate row must be created');
        self::assertSame($hash2, $rows[0]['content_hash'], 'Hash must be updated to latest value');
        self::assertSame(2, (int)$rows[0]['last_scan_uid']);
    }

    /**
     * @test
     */
    public function upsertHashIsolatedByLanguage(): void
    {
        $hash = sha1('content');

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 7,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: $hash,
            scanUid: 1
        );

        $this->subject->upsertHash(
            self::SITE,
            pageUid: 10,
            sourceTable: self::TABLE,
            sourceUid: 7,
            sourceField: self::FIELD,
            sourceLangUid: 1,
            hash: sha1('Slovak content'),
            scanUid: 1
        );

        self::assertCount(1, $this->fetchRows(7, 0));
        self::assertCount(1, $this->fetchRows(7, 1));
    }

    /**
     * @test
     */
    public function deleteForPageRemovesAllRowsForPage(): void
    {
        foreach ([10, 11, 12] as $uid) {
            $this->subject->upsertHash(
                self::SITE,
                pageUid: 20,
                sourceTable: self::TABLE,
                sourceUid: $uid,
                sourceField: self::FIELD,
                sourceLangUid: 0,
                hash: sha1((string)$uid),
                scanUid: 1
            );
        }

        // Row for different page — must survive
        $this->subject->upsertHash(
            self::SITE,
            pageUid: 99,
            sourceTable: self::TABLE,
            sourceUid: 99,
            sourceField: self::FIELD,
            sourceLangUid: 0,
            hash: sha1('other'),
            scanUid: 1
        );

        $deleted = $this->subject->deleteForPage(pageUid: 20, siteIdentifier: self::SITE);

        self::assertSame(3, $deleted);
        self::assertCount(1, $this->fetchRows(99, 0), 'Row for page 99 must survive');
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchRows(int $sourceUid, int $langUid): array
    {
        $connection = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
            ->getConnectionForTable(Tables::SOURCE_STATE);

        return $connection
            ->select(
                ['uid', 'content_hash', 'last_scan_uid'],
                Tables::SOURCE_STATE,
                [
                    'site_identifier' => self::SITE,
                    'source_table'    => self::TABLE,
                    'source_uid'      => $sourceUid,
                    'source_field'    => self::FIELD,
                    'source_lang_uid' => $langUid,
                ]
            )
            ->fetchAllAssociative();
    }
}
