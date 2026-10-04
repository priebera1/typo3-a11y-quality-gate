<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\FormEngine\EditorFeedbackLabels;

/**
 * The in-editor feedback (CKEditor panel and summary, plain HTML wizard) as measured in the TYPO3 14 editor:
 * readable text in Light and Dark, a visible keyboard focus, and translated wizard labels.
 */
final class EditorFeedbackStylesTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../Resources/Public/Css/ckeditor.css';
    private const PLAIN_HTML_JS = __DIR__ . '/../../../Resources/Public/JavaScript/plain-html-a11y.js';
    private const TEXT_TOKENS = ['--cka-fg-subtle', '--cka-crit', '--cka-warn', '--cka-info'];

    #[Test]
    public function panelTextTokensMeetAaOnTheLightAndDarkPanelSurfaces(): void
    {
        $css = (string)file_get_contents(self::CSS);
        $light = $this->tokens($css, '/:root,\s*\.ck-a11y-summary,[^{]*\{([^}]*)\}/');
        $dark = $this->tokens($css, '/html\[data-color-scheme="dark"\] :is\(\.ck-a11y-summary[^{]*\{([^}]*)\}/');

        foreach (self::TEXT_TOKENS as $token) {
            foreach (['#ffffff', '#f6f7f9'] as $surface) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($light[$token] ?? '', $surface), $token . ' in Light on ' . $surface);
            }
            foreach (['#1c2128', '#232931'] as $surface) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($dark[$token] ?? '', $surface), $token . ' in Dark on ' . $surface);
            }
        }

        // Severity chips and counts: each text colour on its own tinted background, in both schemes.
        foreach (['--cka-crit', '--cka-warn', '--cka-info'] as $token) {
            self::assertArrayHasKey($token . '-bg', $dark, 'An explicit Dark choice must not keep the light chip background.');
            self::assertGreaterThanOrEqual(4.5, $this->contrast($dark[$token], $dark[$token . '-bg']), $token . ' on its dark chip');
        }
    }

    #[Test]
    public function autoWithADarkPreferenceUsesTheExplicitDarkTokens(): void
    {
        $css = (string)file_get_contents(self::CSS);
        $dark = $this->tokens($css, '/html\[data-color-scheme="dark"\] :is\(\.ck-a11y-summary[^{]*\{([^}]*)\}/');
        $auto = $this->tokens($css, '/html:not\(\[data-color-scheme="light"\]\) :is\(\.ck-a11y-summary[^{]*\{([^}]*)\}/');

        self::assertNotSame([], $dark);
        self::assertSame($dark, $auto);
    }

    #[Test]
    public function panelControlsShowASolidFocusRing(): void
    {
        self::assertMatchesRegularExpression(
            '/:is\(\.ck-a11y-panel,[^)]*\) :is\(button, a\[href\], summary, \[tabindex\]\):focus-visible \{\s*outline: 2px solid var\(--cka-focus-ring\) !important;/',
            (string)file_get_contents(self::CSS),
            'Panel buttons only changed their background on focus (about 1.07:1).'
        );
    }

    #[Test]
    public function thePlainHtmlIssueListSummaryIsTranslated(): void
    {
        self::assertArrayHasKey('plainShowIssueList', EditorFeedbackLabels::LABELS);
        $script = (string)file_get_contents(self::PLAIN_HTML_JS);
        self::assertStringContainsString("editorLabelHtml('plainShowIssueList'", $script);
        self::assertStringNotContainsString('<summary>Show issue list', $script);
    }

    /**
     * @return array<string, string>
     */
    private function tokens(string $css, string $pattern): array
    {
        self::assertMatchesRegularExpression($pattern, $css);
        preg_match($pattern, $css, $match);
        preg_match_all('/(--cka-[\w-]+):\s*(#[0-9a-f]{6})/i', $match[1], $pairs, PREG_SET_ORDER);

        return array_column($pairs, 2, 1);
    }

    private function contrast(string $foreground, string $background): float
    {
        self::assertMatchesRegularExpression('/^#[0-9a-f]{6}$/i', $foreground);
        $luminance = static function (string $hex): float {
            $channel = static function (string $part): float {
                $value = hexdec($part) / 255;
                return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
            };
            return 0.2126 * $channel(substr($hex, 1, 2)) + 0.7152 * $channel(substr($hex, 3, 2)) + 0.0722 * $channel(substr($hex, 5, 2));
        };
        [$lighter, $darker] = [max($luminance($foreground), $luminance($background)), min($luminance($foreground), $luminance($background))];

        return ($lighter + 0.05) / ($darker + 0.05);
    }
}
