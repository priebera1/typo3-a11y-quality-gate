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
use TYPO3\CMS\Core\Schema\TcaSchemaFactory;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * QA-01 follow-up (owner decision 2026-11-05): AQG writes only fields TYPO3's editing form shows, including fields a
 * `displayCond` hides. The decision comes from TYPO3's own FormEngine pipeline (FormDataCompiler, TcaDatabaseRecord)
 * against the current record values: for a rich-text field, for an image reference compiled as the opened inline
 * child of its parent file field, for the parent field itself, for a condition set through overrideChildTca, for
 * HIDE_FOR_NON_ADMINS and for an invalid condition (fails closed). Every refusal leaves the stored rows unchanged and
 * has a positive control.
 */
final class DisplayConditionWriteBoundaryTest extends AbstractFunctionalTestCase
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
        foreach ([[1, 0, 'DC root', '/'], [2, 1, 'DC page', '/dc']] as [$uid, $pid, $title, $slug]) {
            $pool->getConnectionForTable('pages')->insert('pages', [
                'uid' => $uid, 'pid' => $pid, 'title' => $title, 'slug' => $slug, 'doktype' => 1,
                'is_siteroot' => $uid === 1 ? 1 : 0, 'perms_everybody' => 31, 'perms_userid' => 99, 'perms_user' => 31,
            ]);
        }
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10, 'pid' => 2, 'header' => 'Visible media', 'CType' => 'textmedia', 'bodytext' => '<p>Text</p>', 'assets' => 1,
        ]);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 10, 'title' => 'DC editors', 'groupMods' => 'web,web_a11y', 'db_mountpoints' => '1',
            'tables_select' => 'pages,tt_content,sys_file_reference',
            'tables_modify' => 'pages,tt_content,sys_file_reference',
            'non_exclude_fields' => 'tt_content:assets,tt_content:bodytext,sys_file_reference:alternative,sys_file_reference:tx_a11y_is_decorative,sys_file_reference:title',
            'allowed_languages' => '0',
            'explicit_allowdeny' => 'tt_content:CType:textmedia',
            'TSconfig' => 'options.a11y_quality_gate.allowImageRemediation = 1',
        ]);
        foreach ([[1, 1, ''], [2, 0, '10']] as [$uid, $admin, $group]) {
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => 'dc' . $uid, 'admin' => $admin, 'usergroup' => $group, 'workspace_perms' => 1,
            ]);
        }
        $this->get(SiteWriter::class)->write('dc', ['rootPageId' => 1, 'base' => 'https://dc.example.invalid/', 'languages' => [
            ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/'],
        ]]);
        $this->get(SiteFinder::class)->getAllSites(false);
        $pool->getConnectionForTable('sys_file')->insert('sys_file', [
            'uid' => 7, 'pid' => 0, 'storage' => 1, 'type' => 2, 'name' => 'dc.png', 'identifier' => '/dc.png', 'mime_type' => 'image/png',
        ]);
        $pool->getConnectionForTable('sys_file_reference')->insert('sys_file_reference', [
            'uid' => self::REFERENCE_UID, 'pid' => 2, 'uid_local' => 7, 'uid_foreign' => 10, 'tablenames' => 'tt_content',
            'fieldname' => 'assets', 'alternative' => 'DC original alt', 'title' => 'Shown', 'tstamp' => 100,
        ]);
    }

    protected function tearDown(): void
    {
        foreach ($this->tcaBackup as $table => $configuration) {
            $GLOBALS['TCA'][$table] = $configuration;
        }
        $this->rebuildSchema();
        parent::tearDown();
    }

    #[Test]
    public function richTextFieldHiddenByAConditionOnTheRecordIsRefusedAndShownOtherwise(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['bodytext']['displayCond'] = 'FIELD:header:!=:Hide text';
        $this->rebuildSchema();
        $this->user(2);
        $records = $this->get(BackendRecordAccessService::class);
        $access = $this->get(RemediationAccessService::class);
        $item = ['target_kind' => RemediationTargetKind::RICH_TEXT, 'target_table' => 'tt_content', 'target_uid' => 10, 'target_field' => 'bodytext'];

        self::assertTrue($records->canEditRecordFields('tt_content', 10, ['bodytext']), 'positive control: condition met');
        self::assertTrue($access->canEditTarget($item));

        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'Hide text'], ['uid' => 10]);
        self::assertFalse($records->canEditRecordFields('tt_content', 10, ['bodytext']), 'the current record value hides the field');
        self::assertFalse($access->canEditTarget($item), 'queued apply refused too');
    }

    #[Test]
    public function imageAltHiddenByItsOwnConditionIsRefusedWithoutAnyRowChange(): void
    {
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['displayCond'] = 'FIELD:title:!=:No alt';
        $this->rebuildSchema();
        $finding = $this->finding();
        $this->user(2);
        $this->applyAlt($finding, 'DC permitted alt');
        self::assertSame('DC permitted alt', $this->storedAlternative(), 'positive control: condition met, editable reference');

        $this->reopen($finding);
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['title' => 'No alt'], ['uid' => self::REFERENCE_UID]);
        $this->assertRefusedWithoutChange($finding, 'DC permitted alt');
    }

    #[Test]
    public function imageFieldOfTheParentHiddenByAConditionRefusesItsReferences(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['assets']['displayCond'] = 'FIELD:header:!=:No media';
        $this->rebuildSchema();
        $finding = $this->finding();
        $this->user(2);
        self::assertTrue($this->get(BackendRecordAccessService::class)->canEditRecordFields('sys_file_reference', self::REFERENCE_UID, ['alternative']));

        $this->getConnectionPool()->getConnectionForTable('tt_content')->update('tt_content', ['header' => 'No media'], ['uid' => 10]);
        $this->assertRefusedWithoutChange($finding, 'DC original alt');
    }

    #[Test]
    public function conditionSetThroughTheParentsOverrideChildTcaApplies(): void
    {
        $GLOBALS['TCA']['tt_content']['columns']['assets']['config']['overrideChildTca']['columns']['alternative']['displayCond'] = 'FIELD:title:=:Shown';
        $this->rebuildSchema();
        $finding = $this->finding();
        $this->user(2);
        self::assertTrue($this->get(BackendRecordAccessService::class)->canEditRecordFields('sys_file_reference', self::REFERENCE_UID, ['alternative']), 'positive control');

        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['title' => 'Other'], ['uid' => self::REFERENCE_UID]);
        $this->assertRefusedWithoutChange($finding, 'DC original alt');
    }

    #[Test]
    public function hideForNonAdminsRefusesEditorsAndKeepsAdministrators(): void
    {
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['displayCond'] = 'HIDE_FOR_NON_ADMINS';
        $this->rebuildSchema();
        $finding = $this->finding();
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'DC original alt');

        $this->user(1);
        $this->applyAlt($finding, 'DC admin alt');
        self::assertSame('DC admin alt', $this->storedAlternative(), 'administrators see the field and may write it');
    }

    #[Test]
    public function administratorsAreBoundByFieldConditionsToo(): void
    {
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['displayCond'] = 'FIELD:title:!=:No alt';
        $this->rebuildSchema();
        $this->getConnectionPool()->getConnectionForTable('sys_file_reference')->update('sys_file_reference', ['title' => 'No alt'], ['uid' => self::REFERENCE_UID]);
        $finding = $this->finding();
        $this->user(1);
        $this->assertRefusedWithoutChange($finding, 'DC original alt');
    }

    #[Test]
    public function aConditionTypo3CannotEvaluateFailsClosed(): void
    {
        $GLOBALS['TCA']['sys_file_reference']['columns']['alternative']['displayCond'] = 'NOT_A_CONDITION:x';
        $this->rebuildSchema();
        $finding = $this->finding();
        $this->user(2);
        $this->assertRefusedWithoutChange($finding, 'DC original alt');
        $this->user(1);
        $this->assertRefusedWithoutChange($finding, 'DC original alt');
    }

    #[Test]
    public function unconditionalEditableReferenceStaysWritableForEditorsAndAdministrators(): void
    {
        $finding = $this->finding();
        $this->user(2);
        $this->applyAlt($finding, 'DC editor alt');
        self::assertSame('DC editor alt', $this->storedAlternative());
        $this->reopen($finding);
        $this->user(1);
        $this->applyAlt($finding, 'DC admin alt');
        self::assertSame('DC admin alt', $this->storedAlternative());
    }

    private function rebuildSchema(): void
    {
        // TYPO3 14 (and 13.4) read the TCA through TcaSchemaFactory: runtime TCA changes need a rebuild there too.
        if (class_exists(TcaSchemaFactory::class) && method_exists(TcaSchemaFactory::class, 'rebuild')) {
            $this->get(TcaSchemaFactory::class)->rebuild($GLOBALS['TCA']);
        }
    }

    private function finding(): int
    {
        return $this->insertIssue([
            'site_identifier' => 'dc', 'page_uid' => 2, 'source_table' => 'tt_content', 'source_uid' => 10,
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
        $before = $this->rows();
        $refused = false;
        try {
            $this->applyAlt($finding, 'DC forbidden alt');
        } catch (ImageRemediationPermissionException) {
            $refused = true;
        }
        self::assertTrue($refused, 'The write must be refused with a permission exception.');
        self::assertSame($expectedAlt, $this->storedAlternative());
        self::assertSame($before, $this->rows(), 'No row (live or version) may change.');
    }

    private function storedAlternative(): string
    {
        return (string)$this->getConnectionPool()->getConnectionForTable('sys_file_reference')
            ->fetchOne('SELECT alternative FROM sys_file_reference WHERE uid = ' . self::REFERENCE_UID);
    }

    /** @return array<string, list<array<string, mixed>>> */
    private function rows(): array
    {
        $connection = $this->getConnectionPool()->getConnectionForTable('sys_file_reference');

        return [
            'sys_file_reference' => $connection->fetchAllAssociative('SELECT * FROM sys_file_reference ORDER BY uid'),
            'tt_content' => $this->getConnectionPool()->getConnectionForTable('tt_content')->fetchAllAssociative('SELECT * FROM tt_content ORDER BY uid'),
        ];
    }
}
