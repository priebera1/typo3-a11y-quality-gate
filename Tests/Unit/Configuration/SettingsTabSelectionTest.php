<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\SettingsController;
use ReflectionClass;
use ReflectionMethod;

/**
 * WCAG 4.1.2: exactly one tab in the settings tablist must report aria-selected="true".
 *
 * Regression guard: the attribute was produced by an inline
 * `f:if(..., then: 'true', else: 'false')`, which TYPO3 14's Fluid resolved to "false" even on the
 * active tab. Reproduced on typo3test14: the active tab carried `is-active` and
 * `aria-current="page"` while every tab reported aria-selected="false".
 */
final class SettingsTabSelectionTest extends TestCase
{
    private const TABS = ['licence', 'fields', 'gate', 'rules', 'remote_access', 'ai', 'statement'];

    #[Test]
    public function exactlyOneTabIsSelectedForEveryActiveTab(): void
    {
        foreach (self::TABS as $activeTab) {
            $states = $this->selectedStates($activeTab);

            self::assertSame('true', $states[$activeTab] ?? null, $activeTab . ' must be selected.');
            self::assertCount(
                1,
                array_filter($states, static fn (string $value): bool => $value === 'true'),
                'Exactly one tab may report aria-selected="true" for active tab ' . $activeTab . '.'
            );
        }
    }

    #[Test]
    public function everyTabHasAnExplicitStringState(): void
    {
        $states = $this->selectedStates('gate');

        self::assertSame(self::TABS, array_keys($states));
        foreach ($states as $tab => $value) {
            self::assertContains($value, ['true', 'false'], $tab . ' must be a literal true/false string.');
        }
    }

    #[Test]
    public function unknownActiveTabLeavesNoTabSelected(): void
    {
        $states = $this->selectedStates('does-not-exist');

        self::assertSame([], array_filter($states, static fn (string $value): bool => $value === 'true'));
    }

    #[Test]
    public function templateUsesThePrecomputedStateNotAnInlineTernary(): void
    {
        $template = (string)file_get_contents(
            __DIR__ . '/../../../Resources/Private/Partials/Settings/Tabs.html'
        );

        self::assertSame(
            count(self::TABS),
            substr_count($template, 'aria-selected="{settingsTabSelected.'),
            'Every settings tab must render the precomputed aria-selected value.'
        );
        self::assertStringNotContainsString(
            "aria-selected=\"{f:if(",
            $template,
            'The inline ternary resolves to "false" on TYPO3 14 and must not come back.'
        );
    }

    /**
     * @return array<string, string>
     */
    private function selectedStates(string $activeTab): array
    {
        $subject = (new ReflectionClass(SettingsController::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(SettingsController::class, $subject);

        $method = new ReflectionMethod(SettingsController::class, 'buildSettingsTabSelectedStates');

        /** @var array<string, string> $states */
        $states = $method->invoke($subject, $activeTab);

        return $states;
    }
}
