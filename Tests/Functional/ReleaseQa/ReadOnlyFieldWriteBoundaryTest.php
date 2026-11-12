<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationAccessService;
use Priebera\A11yQualityGate\Remediation\Workflow\RemediationTargetKind;
use Priebera\A11yQualityGate\Service\BackendRecordAccessService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * QA-01: AQG writes image ALT text through DataHandler, which does not enforce `readOnly`. The write boundary must
 * follow the field configuration FormEngine shows: TCA, record type, TCEFORM page TSconfig, the inline parent
 * field and its overrideChildTca — for editors and administrators alike. A refusal leaves the stored row and its
 * version state unchanged; an editable field keeps working.
 */
final class ReadOnlyFieldWriteBoundaryTest extends AbstractFunctionalTestCase
{
    private const REFERENCE_UID = 30;

    /** @var array<string, mixed> */
    private array $tcaBackup = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->tcaBackup = [
            'sys_file_reference' => $GLOBALS['TCA']['sys_file_reference'],
            'tt_content' => $GLOBALS['TCA']['tt_content'],
        ];
        $pool = $this->getConnectionPool();
        foreach ([[1, 0, 'RO root', '/'], [2, 1, 'RO page', '/ro']] as [$uid, $pid, $title, $slug]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1,
                'is_siteroot' => $uid === 1 ? 1 : 0, 'perms_everybody' => 31, 'perms_userid' => 99, 'perms_user' => 31,
            ]);
        }
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10, 'pid' => 2, 'header' => 'RO content', 'CType' => 'textmedia',
        ]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'RO editors', 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => 'pages,tt_content,sys_file_reference',
            'non_exclude_fields' => 'tt_content:assets,sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative',
            'allowed_languages' => '0',
            'explicit_allowdeny' => 'tt_content:CType:textmedia',
            'TSconfig' => 'options.a11y_quality_gate.allowImageRemediation = 1',
        ]);
        foreach ([[1, 1, ''], [2, 0, '10']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'ro' . $uid, 'admin' => $admin, 'usergroup' => $group, 'workspace_perms' => 1,
            ]);
        }
        $this->get(SiteWriter::class)->write('ro', ['rootPageId' => 1, 'base' => 'https://ro.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
            ['languageId' => 1, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
        // A real image file: TYPO3 lays out a reference by its file type, and only image references show alternative text.
        $pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 7, 'pid' => 0, 'storage' => 1, 'type' => 2, 'name' => 'ro.png', 'identifier' => '/ro.png', 'mime_type' => 'image/png',
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => self::REFERENCE_UID, 'pid' => 2, 'uid_local' => 7, 'uid_foreign' => 10, 'tablenames' => 'tt_content',
            'fieldname' => 'assets', 'alternative' => 'RO original alt', 'tstamp' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tcaBackup as $table => $configuration) {
            $GLOBALS['TCA'][$table] = $configuration;
        }
        parent::tearDown();
    }

    #[Test]
    public function editableAltIsWrittenAndTcaReadOnlyAltIsRefusedWithoutAnyRowChange(): void
    {
        $finding = $this->finding();
        $this->user(2);
        $this->applyAlt($finding, 'RO permitted alt');
        self::assertSame('RO permitted alt', $this->storedAlternative());

        $this->reopen($finding);
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['readOnly'] = true;
        $this->assertRefusedWithoutChange($finding, 'RO permitted alt');
    }

    #[Test]
    public function pageTsConfigReadOnlyIsRefused(): void
    {
        $finding = $this->finding();
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEFORM.sys_file_reference.alternative.config.readOnly = 1'],
            ['uid' => 2],
        );
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'RO original alt');
    }

    #[Test]
    public function hiddenFieldIsRefused(): void
    {
        $finding = $this->finding();
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEFORM.sys_file_reference.alternative.disabled = 1'],
            ['uid' => 2],
        );
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'RO original alt');
    }

    #[Test]
    public function readOnlyInlineParentFieldIsRefused(): void
    {
        $finding = $this->finding();
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEFORM.tt_content.assets.config.readOnly = 1'],
            ['uid' => 2],
        );
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'RO original alt');
    }

    #[Test]
    public function overrideChildTcaReadOnlyIsRefused(): void
    {
        $finding = $this->finding();
        $GLOBALS['TCA']['tt_content']['columns']['assets']['config']['overrideChildTca']['columns']['alternative']['config']['readOnly'] = true;
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'RO original alt');
    }

    #[Test]
    public function recordTypeColumnsOverrideReadOnlyIsRefused(): void
    {
        $finding = $this->finding();
        foreach (array_keys($GLOBALS['TCA']['sys_file_reference']['types'] ?? []) as $type) {
            $GLOBALS['TCA']['sys_file_reference']['types'][$type]['columnsOverrides']['alternative']['config']['readOnly'] = true;
        }
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'RO original alt');
    }

    #[Test]
    public function administratorIsBoundByReadOnlyToo(): void
    {
        $finding = $this->finding();
        $this->user(1);
        $this->applyAlt($finding, 'RO admin alt');
        self::assertSame('RO admin alt', $this->storedAlternative());
        $this->reopen($finding);
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['readOnly'] = true;
        $this->assertRefusedWithoutChange($finding, 'RO admin alt');
    }

    #[Test]
    public function queuedApplyRefusesReadOnlyTargetAndKeepsEditableTarget(): void
    {
        $this->user(2);
        $item = [
            'target_kind' => RemediationTargetKind::IMAGE_REFERENCE_ALT,
            'target_uid' => self::REFERENCE_UID,
            'target_table' => 'sys_file_reference',
            'target_field' => 'alternative',
            'target_descriptor' => json_encode(['parentTable' => 'tt_content', 'parentUid' => 10, 'fieldName' => 'assets']),
        ];
        $access = $this->get(RemediationAccessService::class);
        $records = $this->get(BackendRecordAccessService::class);
        self::assertTrue($records->canEditRecordFields('sys_file_reference', self::REFERENCE_UID, ['alternative']));
        self::assertTrue($access->canEditTarget($item), 'Positive control: the editable target is accepted.');
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['config']['readOnly'] = true;
        self::assertFalse($records->canEditRecordFields('sys_file_reference', self::REFERENCE_UID, ['alternative']));
        self::assertFalse($access->canEditTarget($item));
    }

    #[Test]
    public function translationOfExcludedFieldIsRefused(): void
    {
        $this->user(2);
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['l10n_mode'] = 'exclude';
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 31, 'pid' => 2, 'uid_local' => 7, 'uid_foreign' => 10, 'tablenames' => 'tt_content',
            'fieldname' => 'assets', 'alternative' => 'RO translated', 'sys_language_uid' => 1, 'l10n_parent' => self::REFERENCE_UID,
        ]);
        $GLOBALS['BE_USER']->groupData['allowed_languages'] = '0,1';
        $records = $this->get(BackendRecordAccessService::class);
        self::assertTrue($records->canEditRecordFields('sys_file_reference', self::REFERENCE_UID, ['alternative']));
        self::assertFalse($records->canEditRecordFields('sys_file_reference', 31, ['alternative']));
    }

    #[Test]
    public function foreignWorkspaceVersionIsRefused(): void
    {
        $this->user(2);
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => 32, 'pid' => 2, 'uid_local' => 7, 'uid_foreign' => 10, 'tablenames' => 'tt_content',
            'fieldname' => 'assets', 'alternative' => 'RO ws', 't3ver_wsid' => 5, 't3ver_oid' => self::REFERENCE_UID,
        ]);
        self::assertFalse($this->get(BackendRecordAccessService::class)->canEditRecordFields('sys_file_reference', 32, ['alternative']));
    }

    private function finding(): int
    {
        return $this->insertIssue([
            'site_identifier' => 'ro', 'page_uid' => 2, 'source_table' => 'tt_content', 'source_uid' => 10,
            'source_field' => 'assets', 'source_type' => 'structured', 'rule_id' => 'structured.file_reference_alt',
            'context_path' => 'Page > tt_content:10 > assets > ref:' . self::REFERENCE_UID,
        ]);
    }

    private function user(int $uid): void
    {
        $this->setUpBackendUser($uid);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->createFromUserPreferences($GLOBALS['BE_USER']);
    }

    private function applyAlt(int $finding, string $alt): void
    {
        $service = $this->get(ImageRemediationService::class);
        $context = $service->resolve($finding);
        $service->applyAlt($finding, $alt, $this->get(ImageFindingVersionTokenService::class)->create($context));
    }

    private function reopen(int $finding): void
    {
        $this->getConnectionPool()->getConnectionForTable('tx_a11y_issue')->update('tx_a11y_issue', ['status' => 0], ['uid' => $finding]);
    }

    private function assertRefusedWithoutChange(int $finding, string $expectedAlt): void
    {
        $before = $this->referenceRows();
        $refused = false;
        try {
            $this->applyAlt($finding, 'RO forbidden alt');
        } catch (ImageRemediationPermissionException) {
            $refused = true;
        }
        self::assertTrue($refused, 'The write must be refused with a permission exception.');
        self::assertSame($expectedAlt, $this->storedAlternative());
        self::assertSame($before, $this->referenceRows(), 'No row (live or version) may change.');
    }

    private function storedAlternative(): string
    {
        return (string)$this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = ' . self::REFERENCE_UID);
    }

    /** @return list<array<string, mixed>> */
    private function referenceRows(): array
    {
        return $this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->fetchAllAssociative('SELECT * FROM sys_file_reference ORDER BY uid');
    }

    /** Review follow-up: FormEngine's own save must not blank a read-only alternative text through AQG's pairing hook. */
    #[Test]
    public function decorativeHookNeverBlanksAReadOnlyAlternativeText(): void
    {
        $this->user(2);
        $this->getConnectionPool()->getConnectionForTable('pages')->update(
            'pages',
            ['TSconfig' => 'TCEFORM.sys_file_reference.alternative.config.readOnly = 1'],
            ['uid' => 2],
        );
        $dataHandler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
        $dataHandler->start(['sys_file_reference' => [self::REFERENCE_UID => ['tx_a11y_is_decorative' => 1]]], []);
        $dataHandler->process_datamap();
        self::assertSame('RO original alt', $this->storedAlternative());

        $this->getConnectionPool()->getConnectionForTable('pages')->update('pages', ['TSconfig' => ''], ['uid' => 2]);
        // A new request in real life: drop the page TSconfig TYPO3 cached for this process.
        \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Cache\CacheManager::class)->getCache('runtime')->flush();
        $this->user(2);
        $dataHandler = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\DataHandling\DataHandler::class);
        $dataHandler->start(['sys_file_reference' => [self::REFERENCE_UID => ['tx_a11y_is_decorative' => 1]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog, 'positive control: the editor may save the reference');
        self::assertSame('', $this->storedAlternative(), 'positive control: an editable alternative is cleared for a decorative image');
    }

    /** Review follow-up: a field the parent's overrideChildTca layout drops from the image form is not written. */
    #[Test]
    public function fieldHiddenByTheImageFormLayoutIsRefused(): void
    {
        $finding = $this->finding();
        foreach (array_keys($GLOBALS['TCA']['sys_file_reference']['types'] ?? []) as $type) {
            $GLOBALS['TCA']['tt_content']['columns']['assets']['config']['overrideChildTca']['types'][$type]['showitem'] = '--palette--;;filePalette';
        }
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'RO original alt');
    }
}
