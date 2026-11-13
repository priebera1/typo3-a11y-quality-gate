<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AI media styles (Filelist, FormEngine wand) are built from Scss/media-ai.scss with AQG's tokens: literal muted text,
 * the shared focus ring, no private palette (UX Atlas D11) and the project's dark-mode pattern.
 */
final class MediaAiStylesTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    #[Test]
    public function theCommittedStylesheetIsTheCompressedBuildOfItsScssSource(): void
    {
        $package = json_decode((string)file_get_contents(self::ROOT . '/package.json'), true);
        $script = (string)($package['scripts']['build:media-ai-css'] ?? '');

        self::assertStringContainsString('Resources/Private/Scss/media-ai.scss Resources/Public/Css/media-ai.css', $script);
        self::assertStringContainsString('--style=compressed', $script);
        self::assertStringContainsString('npm run build:media-ai-css', (string)($package['scripts']['build'] ?? ''));
        self::assertFileExists(self::ROOT . '/Resources/Private/Scss/media-ai.scss');
        self::assertLessThanOrEqual(2, substr_count(trim($this->css()), "\n"), 'media-ai.css must be the compressed build output.');
    }

    #[Test]
    public function mutedTextIsALiteralAndFocusUsesTheAqgRing(): void
    {
        $css = $this->css();

        self::assertStringNotContainsString('--typo3-text-color-variant', $css);
        self::assertMatchesRegularExpression('/--aqi-fg-muted:\s*#[0-9a-f]{6}/i', $css);
        self::assertMatchesRegularExpression('/\.t3js-aqg-media-wand:focus-visible[^{]*\{[^}]*box-shadow:\s*var\(--aqi-focus-ring\)/', $css);
        self::assertDoesNotMatchRegularExpression('/color-mix\([^)]*transparent\)/', $css);
    }

    #[Test]
    public function thereIsNoPrivatePaletteAndAiHasNoColourOfItsOwn(): void
    {
        $css = strtolower($this->css());

        foreach (['#6b4fbb', '#0f766e', '#b9a6f2', '#5eead4', '--aqg-media-global', '--aqg-media-local', '--aqg-media-muted'] as $private) {
            self::assertStringNotContainsString($private, $css);
        }
        self::assertMatchesRegularExpression('/\.aqg-media-chip--global\{[^}]*color:var\(--aqi-review\)/', $css, '"File default · all uses" uses AQG\'s review tone.');
    }

    #[Test]
    public function darkRulesFollowTheProjectPattern(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/@media\s*\(\s*prefers-color-scheme:\s*dark\)\s*\{html:not\(\[data-color-scheme=["\']?light["\']?\]\)\s+\.aqg-media-popover/', $css);
        self::assertStringContainsString('html[data-color-scheme=dark] .aqg-media-popover', str_replace('"', '', $css));
        self::assertDoesNotMatchRegularExpression('/:where\(html:not/', $css);
    }

    #[Test]
    public function chipsAndLadderUseFixedLabelSizesOfAtLeast11px(): void
    {
        $css = $this->css();

        // Atlas audit F-19: 0.8em inside a 0.85em parent rendered 8.2px labels.
        foreach (['.aqg-media-chip', '.aqg-media-altchip', '.aqg-media-badge', '.aqg-ladder'] as $selector) {
            self::assertMatchesRegularExpression('/(?:^|\})' . preg_quote($selector, '/') . '\{[^}]*font-size:(\d+(?:\.\d+)?)px/', $css, $selector);
            preg_match('/(?:^|\})' . preg_quote($selector, '/') . '\{[^}]*font-size:(\d+(?:\.\d+)?)px/', $css, $match);
            self::assertGreaterThanOrEqual(11.0, (float)$match[1], $selector);
        }
        self::assertDoesNotMatchRegularExpression('/\.aqg-media-(?:chip|altchip|badge|provenance|popover__scope|popover__hint|field__count)\{[^}]*font-size:0?\.\d+em/', $css);
    }

    #[Test]
    public function theOutcomeLadderIsTheSharedComponentWithoutGlyphs(): void
    {
        $css = $this->css();

        // Atlas audit F-22: one .aqg-ladder (components/_ladder.scss), no private pill ladder with a unicode prefix.
        self::assertStringContainsString('@use "components/ladder"', (string)file_get_contents(self::ROOT . '/Resources/Private/Scss/media-ai.scss'));
        self::assertStringContainsString('.aqg-ladder{', $css);
        self::assertStringNotContainsString('aqg-media-ladder', $css);
        self::assertDoesNotMatchRegularExpression('/content:\s*"\\\\27(?:13|14|17|18)/i', $css);
        self::assertStringNotContainsString('✓', $css);
        $script = (string)file_get_contents(self::ROOT . '/Resources/Public/JavaScript/media-ai/media-ai-common.js');
        self::assertStringContainsString('class="aqg-ladder"', $script);
        self::assertStringNotContainsString('aqg-media-ladder', $script);
    }

    #[Test]
    public function thePrimaryActionIsTheAtlasInkPrimaryOnMediaSurfacesOnly(): void
    {
        $css = $this->css();

        // Atlas audit F-20: AQG's media roots re-tokenise .btn-primary (ink), never TYPO3's buttons globally.
        self::assertMatchesRegularExpression('/:is\(\.aqg-media-popover,\.aqg-media-outcome,\.aqg-media-fieldbar,\.aqg-media-bulkbar\) \.btn-primary\{[^}]*background:var\(--aqi-fg\)/', $css);
        self::assertMatchesRegularExpression('/\.btn-primary:hover[^{]*\{[^}]*color-mix\(in oklch,\s*var\(--aqi-fg\) 88%,\s*var\(--aqi-surface\)\)/', $css);
        self::assertMatchesRegularExpression('/\.btn-primary:focus-visible\{[^}]*box-shadow:var\(--aqi-focus-ring\)/', $css);
        self::assertMatchesRegularExpression('/\.btn-primary:disabled[^{]*\{[^}]*opacity:\.55/', $css);
        self::assertDoesNotMatchRegularExpression('/(?:^|\})\.btn-primary[{:,]/', $css, 'No unscoped .btn-primary rule.');
    }

    #[Test]
    public function thePopoverFloatsBelowTheFieldAndHasTheAtlasTitleStyle(): void
    {
        $css = $this->css();

        self::assertMatchesRegularExpression('/\.aqg-media-pophost\{position:relative\}/', $css);
        self::assertMatchesRegularExpression('/(?:^|\})\.aqg-media-popover\{[^}]*position:absolute/', $css);
        self::assertMatchesRegularExpression('/\.aqg-media-popover__title\{[^}]*font-size:14px;font-weight:600/', $css);
    }

    private function css(): string
    {
        $css = (string)file_get_contents(self::ROOT . '/Resources/Public/Css/media-ai.css');
        self::assertNotSame('', $css, 'media-ai.css must be built before this test runs.');

        return $css;
    }
}
