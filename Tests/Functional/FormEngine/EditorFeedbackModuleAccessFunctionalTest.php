<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\FormEngine;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Backend\Form\NodeFactory;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\RteCKEditor\Form\Element\Event\BeforePrepareConfigurationForEditorEvent;

/**
 * Regression (3.0 editor-first audit): the CKEditor plugin and the plain HTML wizard were loaded for every editor of a
 * tt_content text field, while their routes inherit access from the Accessibility module. TYPO3 answered an editor
 * without that module with 403, and every text field showed an AQG error and asked again after each pause. The
 * editor feedback is now loaded only for users who can open the module (the server-side route checks are unchanged).
 */
final class EditorFeedbackModuleAccessFunctionalTest extends AbstractFunctionalTestCase
{
    private const PLUGIN = '@priebera/a11y-quality-gate/ckeditor/a11y-plugin.js';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        foreach ([[1, 'with-module', 'web,web_a11y'], [2, 'without-module', 'web,web_layout']] as [$uid, $title, $modules]) {
            $pool->getConnectionForTable('be_groups')->insert('be_groups', [
                'uid' => $uid, 'pid' => 0, 'title' => $title, 'groupMods' => $modules,
                'tables_select' => 'pages,tt_content', 'tables_modify' => 'tt_content', 'db_mountpoints' => '1', 'deleted' => 0, 'hidden' => 0,
            ]);
            $pool->getConnectionForTable('be_users')->insert('be_users', [
                'uid' => $uid, 'username' => $title, 'admin' => 0, 'usergroup' => (string)$uid, 'disable' => 0, 'deleted' => 0,
            ]);
        }
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Fixture', 'doktype' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'html', 'bodytext' => '<p>x</p>', 'deleted' => 0, 'hidden' => 0]);
    }

    #[Test]
    public function aModuleUserGetsTheCkeditorPlugin(): void
    {
        $this->setUpBackendUser(1);

        self::assertContains(self::PLUGIN, $this->ckeditorModules());
    }

    #[Test]
    public function anEditorWithoutTheModuleGetsAPlainCkeditor(): void
    {
        $this->setUpBackendUser(2);

        self::assertNotContains(self::PLUGIN, $this->ckeditorModules());
    }

    #[Test]
    public function thePlainHtmlFeedbackFollowsTheSameRule(): void
    {
        $this->setUpBackendUser(1);
        self::assertStringContainsString('js-aqg-plain-html-a11y', $this->plainHtmlWizard());

        $this->setUpBackendUser(2);
        self::assertSame('', $this->plainHtmlWizard());
    }

    /** @return list<string> */
    private function ckeditorModules(): array
    {
        $event = new BeforePrepareConfigurationForEditorEvent([], [
            'tableName' => 'tt_content',
            'fieldName' => 'bodytext',
            'vanillaUid' => 10,
            'effectivePid' => 1,
            'databaseRow' => ['uid' => 10, 'pid' => 1, 'sys_language_uid' => [0], 'l18n_parent' => 0],
        ]);
        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return array_map(static fn (array $module): string => (string)($module['module'] ?? ''), $event->getConfiguration()['importModules'] ?? []);
    }

    private function plainHtmlWizard(): string
    {
        return (string)($this->get(NodeFactory::class)->create([
            'renderType' => 'a11yPlainHtmlWizard',
            'tableName' => 'tt_content',
            'fieldName' => 'bodytext',
            'effectivePid' => 1,
            'databaseRow' => ['uid' => 10, 'pid' => 1, 'CType' => ['html'], 'sys_language_uid' => [0], 'l18n_parent' => 0],
            'parameterArray' => ['itemFormElName' => 'data[tt_content][10][bodytext]', 'fieldConf' => ['config' => []]],
        ])->render()['html'] ?? '');
    }
}
