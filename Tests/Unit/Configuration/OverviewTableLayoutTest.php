<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Overview and Settings layout as measured in the TYPO3 14 backend with the page tree open (module frame 899 px
 * at a 1440 px window, about 400 px at 768-1024 px).
 */
final class OverviewTableLayoutTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../Resources/Public/Css/backend.css';
    private const PARTIALS = __DIR__ . '/../../../Resources/Private/Partials/Overview/';

    #[Test]
    public function thePageIdLineKeepsItsTokenContrast(): void
    {
        // #5b6472 at 82 % opacity measured 3.92:1 in Light; the literal subtle token alone meets AA.
        preg_match_all('/([^{}]*\.aqg-page-cell__meta[^{}]*)\{([^}]*)\}/', $this->css(), $rules, PREG_SET_ORDER);
        self::assertNotSame([], $rules);
        foreach ($rules as [, $selector, $body]) {
            self::assertDoesNotMatchRegularExpression('/(^|;)\s*opacity:/', $body, 'Dimmed page ID: ' . trim($selector));
        }
    }

    #[Test]
    public function pageTablesFitTheModuleInsteadOfAFixedMinimumWidth(): void
    {
        $css = $this->css();
        self::assertStringContainsString('.a11y-overview .aqg-table-wrap .aqg-table{min-width:0}', $css);
        self::assertMatchesRegularExpression('/\.a11y-overview \.aqg-table-wrap \.aqg-page-cell__title a\{[^}]*-webkit-line-clamp:2/', $css);
    }

    #[Test]
    public function aScrollingTableKeepsItsActionsInViewAndCanBeScrolledWithTheKeyboard(): void
    {
        $css = $this->css();
        self::assertMatchesRegularExpression(
            '/\.aqg-table-wrap\[data-aqg-scrollable=true\] \.aqg-table :is\(th,td\)\.actions\{position:sticky;right:0/',
            $css
        );
        self::assertMatchesRegularExpression(
            '/\.aqg-table-wrap\[data-aqg-scrollable=true\]:focus-visible\{outline:2px solid var\(--aqi-focus\)/',
            $css
        );

        $markup = '';
        foreach (['LocalPanel.html', 'LocalTable.html', 'RemotePanel.html'] as $partial) {
            $markup .= (string)file_get_contents(self::PARTIALS . $partial);
        }
        preg_match_all('/class="aqg-table-wrap" data-aqg-scroll-labelledby="([^"]+)"/', $markup, $wrappers);
        self::assertCount(3, $wrappers[1], 'Content scan pages, frontend scan pages and failed pages.');
        foreach ($wrappers[1] as $titleId) {
            self::assertStringContainsString('id="' . $titleId . '"', $markup, 'A scrollable table is named by its section title.');
        }
    }

    #[Test]
    public function anEmptyScanProgressStackTakesNoRoomAboveTheSourceTabs(): void
    {
        self::assertMatchesRegularExpression(
            '/\.a11y-overview \.aqg-scan-progress-stack:not\(:has\(>:not\(\.d-none,\[hidden\]\)\)\)[^{]*\{display:none\}/',
            $this->css()
        );
    }

    #[Test]
    public function settingsTabsWrapIntoBalancedRows(): void
    {
        self::assertMatchesRegularExpression(
            '/\.a11y-settings\.aqg-module \.aqg-tabs\{display:block;[^}]*text-wrap:balance\}/',
            $this->css()
        );
    }

    private function css(): string
    {
        $css = (string)file_get_contents(self::CSS);
        self::assertNotSame('', $css, 'backend.css must be built before this test runs.');

        return $css;
    }
}
