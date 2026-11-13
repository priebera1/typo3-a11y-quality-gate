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
 * Regression (3.0 inline editor advice QA, reproduced on TYPO3 13.4): a field made read-only by TCA or TCEFORM still
 * got AQG's AI and rule-based actions. TYPO3 submits a read-only RTE / code editor field and DataHandler does not
 * enforce readOnly, so "Apply" followed by TYPO3 Save changed the stored text. Such a field keeps the live feedback but
 * gets no action that changes it.
 */
final class EditorFeedbackReadOnlyFieldFunctionalTest extends AbstractFunctionalTestCase
{
    private const PLUGIN = '@priebera/a11y-quality-gate/ckeditor/a11y-plugin.js';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1, 'disable' => 0, 'deleted' => 0]);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Fixture', 'doktype' => 1, 'perms_everybody' => 31, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 10, 'pid' => 1, 'CType' => 'html', 'bodytext' => '<button></button>', 'deleted' => 0, 'hidden' => 0]);
        $this->setUpBackendUser(1);
    }

    #[Test]
    public function aReadOnlyRichTextFieldKeepsTheFeedbackWithoutActions(): void
    {
        $configuration = $this->ckeditorConfiguration(readOnly: true);

        self::assertContains(self::PLUGIN, $this->modules($configuration));
        self::assertSame(['state' => 'read_only', 'isAdmin' => false, 'rules' => []], $configuration['a11yQualityGate']['ai']);
    }

    #[Test]
    public function anEditableRichTextFieldIsUnchanged(): void
    {
        $configuration = $this->ckeditorConfiguration(readOnly: false);

        self::assertContains(self::PLUGIN, $this->modules($configuration));
        self::assertNotSame('read_only', $configuration['a11yQualityGate']['ai']['state'] ?? null);
    }

    #[Test]
    public function aReadOnlyPlainHtmlFieldKeepsTheFeedbackWithoutActions(): void
    {
        $html = $this->plainHtmlWizard(readOnly: true);

        self::assertStringContainsString('js-aqg-plain-html-a11y', $html);
        self::assertStringContainsString('data-read-only="1"', $html);
        self::assertStringContainsString('&quot;state&quot;:&quot;read_only&quot;', $html);

        $editable = $this->plainHtmlWizard(readOnly: false);
        self::assertStringNotContainsString('data-read-only', $editable);
        self::assertStringNotContainsString('read_only', $editable);
    }

    /** @return array<string, mixed> */
    private function ckeditorConfiguration(bool $readOnly): array
    {
        $event = new BeforePrepareConfigurationForEditorEvent([], [
            'tableName' => 'tt_content',
            'fieldName' => 'bodytext',
            'vanillaUid' => 10,
            'effectivePid' => 1,
            'databaseRow' => ['uid' => 10, 'pid' => 1, 'sys_language_uid' => [0], 'l18n_parent' => 0],
            'parameterArray' => ['fieldConf' => ['config' => $readOnly ? ['readOnly' => true] : []]],
        ]);
        $this->get(EventDispatcherInterface::class)->dispatch($event);

        return $event->getConfiguration();
    }

    /** @param array<string, mixed> $configuration @return list<string> */
    private function modules(array $configuration): array
    {
        return array_map(static fn (array $module): string => (string)($module['module'] ?? ''), $configuration['importModules'] ?? []);
    }

    private function plainHtmlWizard(bool $readOnly): string
    {
        return (string)($this->get(NodeFactory::class)->create([
            'renderType' => 'a11yPlainHtmlWizard',
            'tableName' => 'tt_content',
            'fieldName' => 'bodytext',
            'effectivePid' => 1,
            'databaseRow' => ['uid' => 10, 'pid' => 1, 'CType' => ['html'], 'sys_language_uid' => [0], 'l18n_parent' => 0],
            'parameterArray' => [
                'itemFormElName' => 'data[tt_content][10][bodytext]',
                'fieldConf' => ['config' => $readOnly ? ['readOnly' => true] : []],
            ],
        ])->render()['html'] ?? '');
    }
}
