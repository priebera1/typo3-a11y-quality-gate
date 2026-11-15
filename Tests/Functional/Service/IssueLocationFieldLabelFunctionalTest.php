<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\IssueLocationService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * rc2 owner QA: Ask AQG sent the editor to "the Text field" of a Plain HTML element (#1749), whose form names the
 * same bodytext column "HTML Code". The location names a field as the record's own type labels it.
 */
final class IssueLocationFieldLabelFunctionalTest extends AbstractFunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['backend', 'frontend', 'scheduler', 'rte_ckeditor'];

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'is_siteroot' => 1, 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 1749, 'pid' => 1, 'CType' => 'html', 'header' => '', 'bodytext' => '<button type="button"></button>', 'deleted' => 0, 'hidden' => 0]);
        $pool->getConnectionForTable('tt_content')->insert('tt_content', ['uid' => 1750, 'pid' => 1, 'CType' => 'text', 'header' => 'Text', 'bodytext' => '<p>Text</p>', 'deleted' => 0, 'hidden' => 0]);
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    #[Test]
    public function aPlainHtmlElementsBodytextIsTheHtmlCodeField(): void
    {
        $service = $this->get(IssueLocationService::class);

        $html = $service->describe('tt_content', 1749, 'bodytext');
        self::assertSame('HTML Code', $html['fieldLabel']);
        self::assertSame('Content element #1749', $html['title']);
        self::assertSame('Plain HTML', $service->contentElementLabels('tt_content', 1749)['contentTypeLabel']);

        self::assertSame('Text', $service->describe('tt_content', 1750, 'bodytext')['fieldLabel']);
    }

    #[Test]
    public function withoutTheRecordTheColumnLabelStays(): void
    {
        $service = $this->get(IssueLocationService::class);

        self::assertSame('Text', $service->describe('tt_content', 999999, 'bodytext')['fieldLabel']);
        self::assertSame('Rendered page HTML', $service->describe('tt_content', 1749, '__rendered_html')['fieldLabel']);
    }
}
