<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\SettingsController;
use ReflectionClass;
use ReflectionMethod;

/**
 * The settings sections are separate pages, so their navigation is a list of links where the current one carries
 * aria-current="page" (audit A2/S2): an ARIA tablist promised tab panels and arrow-key behaviour that page links do not
 * have, and TYPO3 14's core tab manager rewrote aria-selected of every [role="tab"].
 *
 * The state is precomputed: an inline Fluid ternary in an ARIA attribute resolved wrongly on TYPO3 14.
 */
final class SettingsTabSelectionTest extends TestCase
{
    private const TABS = ['licence', 'fields', 'gate', 'rules', 'remote_access', 'ai', 'statement'];

    #[Test]
    public function exactlyOneSectionIsCurrentForEveryActiveTab(): void
    {
        foreach (self::TABS as $activeTab) {
            $states = $this->currentStates($activeTab);

            self::assertSame('page', $states[$activeTab] ?? null, $activeTab . ' must be current.');
            self::assertCount(1, array_filter($states, static fn (string $value): bool => $value === 'page'));
        }
    }

    #[Test]
    public function unknownActiveTabLeavesNoSectionCurrent(): void
    {
        self::assertSame([], array_filter($this->currentStates('does-not-exist'), static fn (string $value): bool => $value === 'page'));
    }

    #[Test]
    public function theTemplateIsNavigationWithPrecomputedCurrentState(): void
    {
        $template = (string)file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Settings/Tabs.html');

        self::assertSame(count(self::TABS), substr_count($template, 'aria-current="{settingsTabCurrent.'));
        self::assertStringNotContainsString('role="tab', $template);
        self::assertStringNotContainsString('aria-selected', $template);
        self::assertStringContainsString('<nav class="aqg-tabs"', $template);
    }

    /**
     * @return array<string, string>
     */
    private function currentStates(string $activeTab): array
    {
        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();

        /** @var array<string, string> $states */
        $states = (new ReflectionMethod(SettingsController::class, 'buildSettingsTabCurrentStates'))->invoke($subject, $activeTab);

        return $states;
    }
}
