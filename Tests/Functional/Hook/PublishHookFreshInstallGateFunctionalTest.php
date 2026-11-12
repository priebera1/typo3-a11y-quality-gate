<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Hook;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\RulesetRepository;
use Priebera\A11yQualityGate\QualityGate\QualityGateChecker;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Messaging\FlashMessage;
use TYPO3\CMS\Core\Messaging\FlashMessageService;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Product acceptance D-01: on a fresh installation no ruleset row exists until an administrator opens Settings
 * (RulesetRepository::findOrCreateDefault() runs on that GET). The gate used to treat the missing row as "disabled",
 * so the FREE "Warning gate on publish" was silently off until someone merely looked at Settings — after that visit,
 * without saving anything, the very same unhide warned. The gate now applies the shipped default ruleset whether or
 * not the row has been written yet, and it never writes the row itself.
 */
final class PublishHookFreshInstallGateFunctionalTest extends AbstractFunctionalTestCase
{
    private const SITE = 'fixture';
    private const ROOT = 1;
    private const PAGE = 2;
    private const QUEUE = 'core.template.flashMessages';

    protected function setUp(): void
    {
        parent::setUp();

        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::ROOT, 'pid' => 0, 'title' => 'Root', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => self::PAGE, 'pid' => self::ROOT, 'title' => 'Offers', 'slug' => '/offers', 'doktype' => 1, 'deleted' => 0, 'hidden' => 1,
        ]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 10, 'pid' => self::PAGE, 'CType' => 'text', 'header' => 'Offer', 'bodytext' => '<p><img src="photo.png"></p>',
            'sorting' => 256, 'sys_language_uid' => 0, 'deleted' => 0, 'hidden' => 0,
        ]);
        $pool->getConnectionForTable('tx_a11y_field_config')->insert('tx_a11y_field_config', [
            'table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text',
            'is_enabled' => 1, 'is_auto_detected' => 1,
        ]);

        $this->get(SiteWriter::class)->createNewBasicSite(self::SITE, self::ROOT, 'https://fixture.invalid/');
        $this->get(SiteFinder::class)->getAllSites(false);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function unhidingAPageWithACriticalIssueWarnsBeforeSettingsWereEverOpened(): void
    {
        self::assertSame(0, $this->rulesetRows(), 'fresh installation: no ruleset row yet');

        $this->unhide();

        $warnings = $this->gateMessages();
        self::assertCount(1, $warnings, 'the FREE warning gate is active on a fresh installation');
        self::assertStringContainsString('The page is now visible. It does not meet the Quality Gate', $warnings[0]);
        self::assertSame(0, $this->hidden(), 'warn mode never re-hides the page');
        self::assertSame(0, $this->rulesetRows(), 'the gate does not write configuration');
    }

    #[Test]
    public function theCheckerUsesTheSameDefaultsAsTheRowSettingsCreates(): void
    {
        $checker = $this->get(QualityGateChecker::class);
        $before = $checker->verdictForCounts(self::SITE, ['critical' => 1, 'warning' => 9, 'info' => 0, 'needs_review' => 0]);
        self::assertTrue($checker->isEnabledForSite(self::SITE));

        $this->get(RulesetRepository::class)->findOrCreateDefault();
        self::assertSame(1, $this->rulesetRows());
        $after = $checker->verdictForCounts(self::SITE, ['critical' => 1, 'warning' => 9, 'info' => 0, 'needs_review' => 0]);

        self::assertTrue($before->isFailed());
        self::assertSame(1, $before->mode, 'warn');
        self::assertSame($after->mode, $before->mode);
        self::assertSame($after->reasons, $before->reasons, 'critical threshold 0 and warning threshold off, as in the created row');
        self::assertTrue($checker->verdictForCounts(self::SITE, ['critical' => 0, 'warning' => 9, 'info' => 0, 'needs_review' => 0])->isPassed());
    }

    #[Test]
    public function anExplicitlyDisabledGateStaysSilent(): void
    {
        $this->get(RulesetRepository::class)->findOrCreateDefault();
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_ruleset')
            ->update('tx_a11y_ruleset', ['publish_mode' => 0], ['is_default' => 1]);

        $this->unhide();

        self::assertSame([], $this->gateMessages());
    }

    #[Test]
    public function anEditorWithoutTheModuleIsNotSentToIt(): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 7, 'pid' => 0, 'title' => 'Editors without AQG', 'tables_modify' => 'pages,tt_content', 'tables_select' => 'pages,tt_content',
            'groupMods' => 'web_layout,web_list', 'db_mountpoints' => (string)self::ROOT, 'non_exclude_fields' => 'pages:hidden', 'pagetypes_select' => '1',
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 2, 'username' => 'editor', 'admin' => 0, 'usergroup' => '7', 'disable' => 0, 'deleted' => 0, 'options' => 3]);
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 31], ['uid' => self::PAGE]);
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 31], ['uid' => self::ROOT]);
        $this->setUpBackendUser(2);

        $this->unhide();

        $warnings = $this->gateMessages();
        self::assertCount(1, $warnings, 'the gate applies to every user who unhides a page');
        self::assertStringContainsString('Ask a colleague with access to the Accessibility module', $warnings[0]);
        self::assertStringNotContainsString('Open Accessibility', $warnings[0]);
    }

    #[Test]
    public function aRefusedUnhideIsNotReportedAsAQualityGateDecision(): void
    {
        // Product acceptance G-01: an editor without edit permission on the page submits hidden=0. TYPO3 refuses the
        // change ("Attempt to modify record … without permission"), but AQG still scanned and reported a gate verdict
        // (in block mode "blocked") for an unhide that never happened.
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_groups')->insert('be_groups', [
            'uid' => 8, 'pid' => 0, 'title' => 'Read-only editors', 'tables_modify' => 'pages', 'tables_select' => 'pages',
            'groupMods' => 'web_layout,web_a11y', 'db_mountpoints' => (string)self::ROOT, 'non_exclude_fields' => 'pages:hidden', 'pagetypes_select' => '1',
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 3, 'username' => 'reader', 'admin' => 0, 'usergroup' => '8', 'disable' => 0, 'deleted' => 0, 'options' => 3]);
        $pool->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1, 'perms_groupid' => 0, 'perms_group' => 0], ['uid' => self::PAGE]);
        $this->setUpBackendUser(3);

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [self::PAGE => ['hidden' => 0]]], []);
        $dataHandler->process_datamap();

        self::assertNotSame([], $dataHandler->errorLog, 'TYPO3 refused the change');
        self::assertSame(1, $this->hidden());
        self::assertSame([], $this->gateMessages(), 'no gate verdict for an unhide that did not happen');
    }

    private function unhide(): void
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start(['pages' => [self::PAGE => ['hidden' => 0]]], []);
        $dataHandler->process_datamap();
        self::assertSame([], $dataHandler->errorLog);
    }

    /** @return list<string> */
    private function gateMessages(): array
    {
        $queue = GeneralUtility::makeInstance(FlashMessageService::class)->getMessageQueueByIdentifier(self::QUEUE);
        $messages = array_map(static fn (FlashMessage $message): string => $message->getMessage(), $queue->toArray());

        return array_values(array_filter($messages, static fn (string $message): bool => str_contains($message, 'Quality Gate')));
    }

    private function rulesetRows(): int
    {
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('tx_a11y_ruleset')->count('*', 'tx_a11y_ruleset', []);
    }

    private function hidden(): int
    {
        // Raw read: the default query restrictions hide hidden pages and would always answer 0.
        return (int)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')
            ->fetchOne('SELECT hidden FROM pages WHERE uid = ?', [self::PAGE]);
    }
}
