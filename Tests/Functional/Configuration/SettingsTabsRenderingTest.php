<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use ReflectionClass;
use ReflectionMethod;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\View\ViewFactoryData;
use TYPO3\CMS\Core\View\ViewFactoryInterface;

/**
 * TYPO3 14's core tab manager (@typo3/backend/tab.js) rewrites `aria-selected` and `tabindex` of every
 * `[role="tab"]` inside a `[role="tablist"]` from the `active` class when the backend document loads.
 * The selection state therefore has to be carried by `active` as well; otherwise TYPO3 14 announces
 * the current settings tab as unselected and removes every tab from the keyboard tab order.
 */
final class SettingsTabsRenderingTest extends AbstractFunctionalTestCase
{
    private const TABS = ['licence', 'fields', 'gate', 'rules', 'remote_access', 'ai', 'statement'];

    protected function setUp(): void
    {
        parent::setUp();
        $GLOBALS['LANG'] = $this->get(LanguageServiceFactory::class)->create('default');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function activeTabs(): iterable
    {
        foreach (self::TABS as $tab) {
            yield $tab => [$tab];
        }
    }

    #[Test]
    #[DataProvider('activeTabs')]
    public function onlyTheActiveTabCarriesTheClassTypo3DerivesSelectionFrom(string $activeTab): void
    {
        $tabs = $this->renderTabs($activeTab);

        self::assertCount(count(self::TABS), $tabs);
        self::assertSame(
            ['#' . $activeTab],
            array_keys(array_filter($tabs, static fn (array $tab): bool => $tab['active'])),
            'Exactly the active tab must carry the "active" class.'
        );
        foreach ($tabs as $href => $tab) {
            self::assertSame(
                $tab['active'] ? 'true' : 'false',
                $tab['ariaSelected'],
                $href . ': aria-selected must agree with the "active" class TYPO3 14 derives it from.'
            );
        }
    }

    /**
     * @return array<string, array{active: bool, ariaSelected: string}>
     */
    private function renderTabs(string $activeTab): array
    {
        $view = $this->get(ViewFactoryInterface::class)->create(new ViewFactoryData(
            partialRootPaths: [
                GeneralUtility::getFileAbsFileName('EXT:a11y_quality_gate/Resources/Private/Partials/'),
            ],
            templatePathAndFilename: __DIR__ . '/../../Fixtures/Templates/SettingsTabs.html',
        ));
        $view->assignMultiple([
            'activeTab' => $activeTab,
            'settingsTabUrls' => array_combine(
                self::TABS,
                array_map(static fn (string $tab): string => '#' . $tab, self::TABS)
            ),
            'settingsTabSelected' => $this->selectedStates($activeTab),
            'isAdmin' => true,
            'proStatus' => ['valid' => false],
            'statementGeneratorAvailable' => true,
        ]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $view->render());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $tabs = [];
        foreach ((new \DOMXPath($document))->query('//*[@role="tablist"]//*[@role="tab"]') ?: [] as $tab) {
            if (!$tab instanceof \DOMElement) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($tab->getAttribute('class'))) ?: [];
            $tabs[$tab->getAttribute('href')] = [
                'active' => in_array('active', $classes, true),
                'ariaSelected' => $tab->getAttribute('aria-selected'),
            ];
        }

        return $tabs;
    }

    /**
     * @return array<string, string>
     */
    private function selectedStates(string $activeTab): array
    {
        $controller = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SettingsController::class, 'buildSettingsTabSelectedStates'))
            ->invoke($controller, $activeTab);
    }
}
