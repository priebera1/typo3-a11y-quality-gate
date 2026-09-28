<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scheduler;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scheduler\MonitoringTask;
use Priebera\A11yQualityGate\Scheduler\MonitoringTaskTcaItems;
use Priebera\A11yQualityGate\Tests\Unit\Monitoring\MonitoringFixtures;
use TYPO3\CMS\Core\Schema\Struct\SelectItem;

/**
 * TYPO3 14 FormEngine items of the monitoring task: the language list follows the site in the form row.
 */
final class MonitoringTaskTcaItemsTest extends TestCase
{
    use MonitoringFixtures;

    #[Test]
    public function theSiteItemsAreTheConfiguredSitesAfterThePlaceholder(): void
    {
        $parameters = ['items' => [['label' => 'Choose a site', 'value' => '']]];
        (new MonitoringTaskTcaItems($this->resolverFor()))->addSiteItems($parameters);

        self::assertSame(['', 'main', 'shop'], array_map(self::value(...), $parameters['items']));
        self::assertSame('Example (main), root page 1', $parameters['items'][1]->getLabel());
    }

    #[Test]
    public function theLanguageItemsAreThoseOfTheSiteInTheForm(): void
    {
        $items = new MonitoringTaskTcaItems($this->resolverFor());

        $main = ['items' => [], 'row' => [MonitoringTask::PARAM_SITE => ['main']]];
        $items->addLanguageItems($main);
        self::assertSame([0, 2], array_map(self::value(...), $main['items']));
        self::assertStringEndsWith('– default language', $main['items'][0]->getLabel());

        $shop = ['items' => [], 'row' => [MonitoringTask::PARAM_SITE => 'shop']];
        $items->addLanguageItems($shop);
        self::assertSame([0], array_map(self::value(...), $shop['items']));

        $none = ['items' => [], 'row' => [MonitoringTask::PARAM_SITE => '']];
        $items->addLanguageItems($none);
        self::assertSame([], $none['items'], 'No language until a site is chosen.');
    }

    private static function value(SelectItem|array $item): mixed
    {
        return $item instanceof SelectItem ? $item->getValue() : $item['value'];
    }
}
