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
 * The settings sections are a navigation list (audit A2/S2): exactly the current section carries aria-current="page"
 * and the "active" class used for styling. No element claims the tab role, so TYPO3 14's core tab manager, which
 * rewrites aria-selected of every [role="tab"], leaves the navigation alone.
 */
final class SettingsTabsRenderingTest extends AbstractFunctionalTestCase
{
    private const TABS = ['licence', 'fields', 'gate', 'rules', 'remote_access', 'ai', 'history', 'statement'];

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
    public function onlyTheCurrentSectionIsMarkedCurrent(string $activeTab): void
    {
        [$links, $roles] = $this->renderTabs($activeTab);

        self::assertCount(count(self::TABS), $links);
        self::assertSame(0, $roles, 'Page links must not claim the tab role.');
        self::assertSame(
            ['#' . $activeTab],
            array_keys(array_filter($links, static fn (array $link): bool => $link['current'] === 'page')),
            'Exactly the current section carries aria-current="page".'
        );
        foreach ($links as $href => $link) {
            self::assertSame($link['current'] === 'page', $link['active'], $href . ': the "active" class follows aria-current.');
        }
    }

    /**
     * @return array{0: array<string, array{active: bool, current: string}>, 1: int}
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
            'settingsTabCurrent' => $this->currentStates($activeTab),
            'isAdmin' => true,
            'proStatus' => ['valid' => false],
            'statementGeneratorAvailable' => true,
        ]);

        $document = new \DOMDocument();
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<?xml encoding="UTF-8">' . $view->render());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $xpath = new \DOMXPath($document);
        $links = [];
        foreach ($xpath->query('//nav[@data-aqg-settings-nav="true"]//a') ?: [] as $link) {
            if (!$link instanceof \DOMElement) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($link->getAttribute('class'))) ?: [];
            $links[$link->getAttribute('href')] = [
                'active' => in_array('active', $classes, true),
                'current' => $link->getAttribute('aria-current'),
            ];
        }

        return [$links, $xpath->query('//*[@role="tab" or @role="tablist"]')?->length ?? 0];
    }

    /**
     * @return array<string, string>
     */
    private function currentStates(string $activeTab): array
    {
        $controller = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();

        return (new ReflectionMethod(SettingsController::class, 'buildSettingsTabCurrentStates'))
            ->invoke($controller, $activeTab);
    }
}
