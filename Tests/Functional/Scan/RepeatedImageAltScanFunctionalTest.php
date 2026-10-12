<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Scan;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A missing image alternative text stays open across unchanged content scans, resolves when the alt text is added
 * and opens again when it is removed — with real tt_content, sys_file, sys_file_reference and sys_file_metadata rows.
 *
 * Regression guard: the file-metadata fallback took whichever metadata row of the file came last, in any language.
 * A German metadata alternative text "resolved" the English image's missing alt text on the next unchanged scan.
 */
final class RepeatedImageAltScanFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const PAGE = 1;
    private const CONTENT = 10;
    private const REFERENCE = 20;
    private const FILE = 30;
    private const RULE = 'structured.file_reference_alt';

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => 0, 'title' => 'Fixture', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => self::CONTENT, 'pid' => self::PAGE, 'CType' => 'image', 'header' => 'Photo', 'image' => 1,
            'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => self::FILE, 'storage' => 1, 'type' => 2, 'identifier' => '/user_upload/photo.png', 'name' => 'photo.png',
            'mime_type' => 'image/png', 'extension' => 'png', 'missing' => 0,
        ]);
        $pool->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid' => 40, 'file' => self::FILE, 'sys_language_uid' => 0, 'l10n_parent' => 0, 'alternative' => null, 'title' => null,
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => self::REFERENCE, 'pid' => self::PAGE, 'uid_local' => self::FILE, 'uid_foreign' => self::CONTENT,
            'tablenames' => 'tt_content', 'fieldname' => 'image', 'alternative' => null, 'tx_a11y_is_decorative' => 0,
            'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'image', 'field_type' => 'file', 'field_label' => 'Images',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, self::PAGE, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aMissingAltTextStaysOpenUntilItIsAddedAndOpensAgainWhenRemoved(): void
    {
        $first = $this->scan();
        self::assertSame(['open'], $this->altFindingStates(), 'scan 1: missing alt is found');
        self::assertSame(1, $first->issuesNew);

        foreach ([2, 3] as $unchangedScan) {
            $result = $this->scan();
            self::assertSame(['open'], $this->altFindingStates(), "scan $unchangedScan (unchanged): still open");
            self::assertSame(0, $result->issuesResolved, "scan $unchangedScan resolves nothing");
        }

        $this->updateReference(['alternative' => 'A red bicycle against a wall']);
        $fixed = $this->scan();
        self::assertSame(['resolved'], $this->altFindingStates(), 'alt text added: resolved');
        self::assertSame(1, $fixed->issuesResolved);

        // A later scan runs in another second: make that visible, or an unchanged update would count no row.
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->update('tx_a11y_issue', ['resolved_at' => 1000, 'tstamp' => 1000], ['rule_id' => self::RULE, 'source_uid' => self::CONTENT]);
        $afterFix = $this->scan();
        self::assertSame(['resolved'], $this->altFindingStates(), 'unchanged scan after the fix: still resolved');
        self::assertSame(0, $afterFix->issuesResolved, 'an unchanged scan does not count the fixed alt text as resolved again');
        self::assertSame(1000, $this->resolvedAt(), 'and keeps the time the alt text was fixed');

        $this->updateReference(['alternative' => null]);
        $this->scan();
        self::assertSame(['open'], $this->altFindingStates(), 'alt text removed again: open');
    }

    #[Test]
    public function aTranslatedMetadataAltTextDoesNotResolveTheDefaultLanguageImage(): void
    {
        $this->scan();
        self::assertSame(['open'], $this->altFindingStates());

        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_metadata')->insert('sys_file_metadata', [
            'uid' => 41, 'file' => self::FILE, 'sys_language_uid' => 1, 'l10n_parent' => 40, 'alternative' => 'Ein rotes Fahrrad', 'title' => null,
        ]);
        $result = $this->scan();

        self::assertSame(['open'], $this->altFindingStates(), 'The English image still has no alternative text.');
        self::assertSame(0, $result->issuesResolved);
    }

    #[Test]
    public function aDefaultMetadataAltTextIsStillUsedAsTheFallback(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_metadata')
            ->update('sys_file_metadata', ['alternative' => 'A red bicycle'], ['uid' => 40]);

        $this->scan();

        self::assertSame([], $this->altFindingStates());
    }

    private function scan(): \Priebera\A11yQualityGate\Scan\ScanResult
    {
        return $this->get(ScanOrchestrator::class)->scanPage(self::SITE, self::PAGE, 0);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function updateReference(array $values): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_reference')
            ->update('sys_file_reference', $values, ['uid' => self::REFERENCE]);
    }

    private function resolvedAt(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['resolved_at'], 'tx_a11y_issue', ['rule_id' => self::RULE, 'source_uid' => self::CONTENT, 'deleted' => 0])
            ->fetchOne();
    }

    /**
     * @return list<string>
     */
    private function altFindingStates(): array
    {
        $rows = GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_issue')
            ->select(['status'], 'tx_a11y_issue', ['rule_id' => self::RULE, 'source_uid' => self::CONTENT, 'deleted' => 0], [], ['uid' => 'ASC'])
            ->fetchAllAssociative();

        return array_map(static fn (array $row): string => match ((int)$row['status']) {
            0 => 'open',
            1 => 'resolved',
            2 => 'ignored',
            default => 'status-' . $row['status'],
        }, $rows);
    }
}
