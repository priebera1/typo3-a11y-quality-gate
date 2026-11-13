<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Scheduler\A11yScanTaskTcaItems;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * U-06 (2026-11-05 company acceptance): a new "Site Scan" task offered only "All languages" and "Default language"
 * until the form was saved and reloaded ("Refresh required"). Every site's translations are listed from the start,
 * labelled with their site, and the page fields no longer reload the form.
 */
final class A11yScanTaskTcaItemsTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function aNewTaskListsTheTranslationsOfEverySite(): void
    {
        $this->sites();
        $parameters = ['items' => [['label' => 'All languages', 'value' => -1], ['label' => 'Default language', 'value' => 0]], 'row' => []];
        (new A11yScanTaskTcaItems())->addLanguageItems($parameters);

        $values = array_map(static fn (array|SelectItem $item): int => (int)($item instanceof SelectItem ? $item->getValue() : $item['value']), $parameters['items']);
        self::assertSame([-1, 0, 1, 2], $values);
        $labels = array_map(static fn (array|SelectItem $item): string => $item instanceof SelectItem ? $item->getLabel() : $item['label'], $parameters['items']);
        self::assertSame('Deutsch (1) · careers / Deutsch (1) · northwind', $labels[2]);
        self::assertSame('Slovenčina (2) · northwind', $labels[3]);
    }

    #[Test]
    public function thePageFieldsNoLongerReloadTheForm(): void
    {
        // The override file ends the request outside TYPO3 (`defined('TYPO3') or die()`), so it is read, not required.
        $tca = (string)file_get_contents(__DIR__ . '/../../../Configuration/TCA/Overrides/scheduler_a11y_scan_task.php');

        self::assertStringNotContainsString("'onChange' => 'reload'", $tca);
    }

    private function sites(): void
    {
        $language = static fn (int $id, string $title): array => ['languageId' => $id, 'title' => $title, 'base' => '/' . $id . '/', 'locale' => 'en_US.UTF-8'];
        $northwind = new Site('northwind', 1, ['base' => 'https://northwind.invalid/', 'languages' => [$language(0, 'English'), $language(1, 'Deutsch'), $language(2, 'Slovenčina')]]);
        $careers = new Site('careers', 20, ['base' => 'https://careers.invalid/', 'languages' => [$language(0, 'English'), $language(1, 'Deutsch')]]);
        $finder = $this->createMock(SiteFinder::class);
        $finder->method('getAllSites')->willReturn(['careers' => $careers, 'northwind' => $northwind]);
        $finder->method('getSiteByPageId')->willReturnCallback(static fn (int $pid): Site => $pid >= 20 ? $careers : $northwind);
        GeneralUtility::addInstance(SiteFinder::class, $finder);
    }
}
