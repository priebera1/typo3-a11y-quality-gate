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
    private const TEXT_TOKENS = ['--cka-fg-muted', '--cka-fg-subtle', '--cka-crit', '--cka-warn', '--cka-info', '--cka-ok'];
    private const LIGHT_LAYER = '/:root,\s*\.ck-editor,\s*\.ck-a11y-summary,[^{]*\{([^}]*)\}/';
    private const DARK_LAYER = '/html\[data-color-scheme="dark"\] :is\(\.ck-editor, \.ck-a11y-summary[^{]*\{([^}]*)\}/';
    private const AUTO_LAYER = '/html:not\(\[data-color-scheme="light"\]\) :is\(\.ck-editor, \.ck-a11y-summary[^{]*\{([^}]*)\}/';
    /** Subtle text literals that failed WCAG AA 1.4.3 (Atlas audit F-02: 3.86:1 and 3.72:1 at 11-12px). */
    private const FAILING_SUBTLE = ['#7c828d', '#89929f', '#8e99a8'];
    /**
     * Status text colours TYPO3 13.4 resolves for --typo3-text-color-* in Light and Dark, and its base surfaces: the
     * final layer binds to these, so they must pass as well (measured in the T13 backend, Atlas fix pass).
     */
    private const TYPO3_13_LIGHT = ['#1b657e', '#247554', '#664d00', '#a72e25', '#1a1a1a'];
    private const TYPO3_13_DARK = ['#57b9db', '#3cc38c', '#ffcc33', '#da6158', '#d9d9d9'];

    #[Test]
    public function panelTextTokensMeetAaOnTheLightAndDarkPanelSurfaces(): void
    {
        $css = (string)file_get_contents(self::CSS);
        $light = $this->tokens($css, self::LIGHT_LAYER);
        $dark = $this->tokens($css, self::DARK_LAYER);

        foreach (self::TEXT_TOKENS as $token) {
            foreach (['#ffffff', '#f6f7f9', $light['--cka-surface-2'] ?? ''] as $surface) {
                self::assertGreaterThanOrEqual(4.5, $this->contrast($light[$token] ?? '', $surface), $token . ' in Light on ' . $surface);
            }
            foreach (['#1c2128', '#232931', '#121212'] as $surface) {
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
        $dark = $this->tokens($css, self::DARK_LAYER);
        $auto = $this->tokens($css, self::AUTO_LAYER);

        self::assertNotSame([], $dark);
        self::assertSame($dark, $auto);
    }

    #[Test]
    public function theFinalLayerBindsTheModulePaletteWithAtlasFallbacks(): void
    {
        $css = (string)file_get_contents(self::CSS);
        preg_match(self::LIGHT_LAYER, $css, $light);
        foreach ([
            '--cka-info' => 'var(--typo3-text-color-info, #1b6fa8)',
            '--cka-ok' => 'var(--typo3-text-color-success, #12723c)',
            '--cka-warn' => 'var(--typo3-text-color-warning, #8a5c00)',
            '--cka-crit' => 'var(--typo3-text-color-danger, #c7372f)',
            '--cka-fg' => 'var(--typo3-text-color-base, #1f2937)',
            '--cka-fg-subtle' => '#5b6472',
        ] as $token => $value) {
            self::assertStringContainsString($token . ': ' . $value . ' !important;', $light[1] ?? '', $token . ' must follow _tokens.scss (aqg-light-tokens).');
        }
        // Muted and subtle text are literal, never the host's decorative text variant.
        self::assertStringNotContainsString('--typo3-text-color-variant', $css);
        // The final layer is the last word on the text tokens: no later block redeclares them.
        $tail = substr($css, (int)strpos($css, $light[0]) + strlen($light[0]));
        self::assertDoesNotMatchRegularExpression('/--cka-(fg|fg-muted|fg-subtle|crit|warn|info|ok):/', (string)preg_replace('/^.*?@media \(prefers-color-scheme: dark\) \{\s*html:not[^{]*\{[^}]*\}\s*\}/s', '', (string)preg_replace('/^\s*html\[data-color-scheme="dark"\][^{]*\{[^}]*\}/s', '', $tail)));
    }

    #[Test]
    public function theStatusColoursTypo3ResolvesStayReadableOnItsSurfaces(): void
    {
        foreach (self::TYPO3_13_LIGHT as $colour) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($colour, '#ffffff'), $colour . ' on the TYPO3 13 light surface');
        }
        foreach (self::TYPO3_13_DARK as $colour) {
            self::assertGreaterThanOrEqual(4.5, $this->contrast($colour, '#121212'), $colour . ' on the TYPO3 13 dark surface');
        }
    }

    #[Test]
    public function noTextColourUsesASubtleLiteralBelowAa(): void
    {
        $css = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents(self::CSS));
        foreach (self::FAILING_SUBTLE as $literal) {
            self::assertDoesNotMatchRegularExpression('/(^|[;{\s])color:\s*' . preg_quote($literal, '/') . '/i', $css, $literal . ' as a text colour (F-02)');
            self::assertDoesNotMatchRegularExpression('/--cka-fg-subtle:\s*' . preg_quote($literal, '/') . '/i', $css, $literal . ' as the subtle token');
        }
        // White label text on a grey badge (line badge, element labels) needs the AA grey as well.
        self::assertDoesNotMatchRegularExpression('/color:\s*#fff(fff)?\s*(!important)?;\s*background:\s*#7c828d/i', $css);

        $script = (string)file_get_contents(self::PLAIN_HTML_JS);
        foreach (self::FAILING_SUBTLE as $literal) {
            self::assertStringNotContainsString($literal, $script, 'The CodeMirror shadow styles of the plain HTML badge');
        }

        // Every subtle literal that remains passes on its scheme's surfaces.
        preg_match_all('/--cka-fg-subtle:\s*(#[0-9a-f]{6})/i', $css, $subtle);
        foreach (array_unique($subtle[1]) as $value) {
            $light = $this->contrast($value, '#ffffff');
            self::assertTrue($light >= 4.5 || $this->contrast($value, '#1c2128') >= 4.5, $value . ' fails AA on both schemes');
        }
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
    public function onlyTheLegacyBalloonPanelCollapsesIssueDetails(): void
    {
        $css = (string)preg_replace('#/\*.*?\*/#s', '', (string)file_get_contents(self::CSS));
        preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);

        $unscoped = [];
        foreach ($rules as [, $selectors, $declarations]) {
            if (!preg_match('/(^|;)\s*display:\s*none/i', $declarations)) {
                continue;
            }
            foreach (explode(',', $selectors) as $selector) {
                $selector = trim($selector);
                if (str_contains($selector, '.ck-a11y-panel__details') && !preg_match('/\.ck-a11y-panel[\s:.]/', $selector)) {
                    $unscoped[] = $selector;
                }
            }
        }

        self::assertSame(
            [],
            $unscoped,
            'The CKEditor issue popover (<details> "Show details") and the plain HTML panel render .ck-a11y-panel__details '
            . 'outside the legacy balloon panel; an unscoped display:none opened an empty disclosure.'
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
        // A literal, or the literal fallback of var(--typo3-…, #fallback) (one or two nested var() levels).
        preg_match_all('/(--cka-[\w-]+):\s*(?:var\(--[\w-]+,\s*(?:var\(--[\w-]+,\s*)?)?(#[0-9a-f]{6})/i', $match[1], $pairs, PREG_SET_ORDER);

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
