<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Export;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ExportController;
use Priebera\A11yQualityGate\Export\IssueExporter;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A Content Scan export covers the language the view shows: the findings of that content language only. Every
 * language is exported only when asked for (-1) or by a link that names no language.
 */
final class LanguageScopedContentExportFunctionalTest extends AbstractFunctionalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('pages')->insert('pages', ['uid' => 1, 'pid' => 0, 'title' => 'Home', 'slug' => '/', 'doktype' => 1, 'is_siteroot' => 1]);
        $this->insertIssue(['source_lang_uid' => 0, 'context_snippet' => '<a href="/en">click here</a>']);
        $this->insertIssue(['source_lang_uid' => 1, 'context_snippet' => '<a href="/de">hier klicken</a>']);
    }

    #[Test]
    public function theCsvHoldsTheFindingsOfTheSelectedLanguageOnly(): void
    {
        $exporter = $this->get(IssueExporter::class);

        self::assertSame(['0'], $this->languages($exporter->toCsv('test', null, 'open', 'all', 0)));
        self::assertSame(['1'], $this->languages($exporter->toCsv('test', null, 'open', 'all', 1)));
        self::assertSame(['0', '1'], $this->languages($exporter->toCsv('test', null, 'open', 'all', -1)));
        self::assertSame(['1'], array_values(array_unique(array_map(
            static fn (array $issue): string => (string)$issue['source_lang_uid'],
            $exporter->getFilteredIssues('test', 1, 'open', 'all', 1),
        ))), 'The PDF reads the same filtered findings.');
    }

    #[Test]
    public function theRequestNamesTheLanguageAndOnlyMinusOneOrNoLanguageExportsEveryLanguage(): void
    {
        $context = new \ReflectionMethod(ExportController::class, 'parseExportContext');
        $controller = $this->get(ExportController::class);
        $request = static fn (array $query): ServerRequest => (new ServerRequest('https://example.org/typo3/'))->withQueryParams($query);

        self::assertSame(1, $context->invoke($controller, $request(['site' => 'test', 'language' => '1']))['localLanguageUid']);
        self::assertSame(0, $context->invoke($controller, $request(['site' => 'test', 'language' => '0']))['localLanguageUid']);
        self::assertSame(-1, $context->invoke($controller, $request(['site' => 'test', 'language' => '-1']))['localLanguageUid']);
        self::assertSame(-1, $context->invoke($controller, $request(['site' => 'test']))['localLanguageUid']);
    }

    /** @return list<string> the distinct Language column values of a CSV export */
    private function languages(string $csv): array
    {
        $rows = array_map(static fn (string $line): array => str_getcsv($line, ';', '"', ''), array_filter(explode("\n", trim($csv))));
        array_shift($rows);
        $languages = array_values(array_unique(array_map(static fn (array $row): string => (string)$row[10], $rows)));
        sort($languages);

        return $languages;
    }
}
