<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * TYPO3 13 and 14 render `data-color-scheme` on <html> only for an explicit Light or Dark choice. The default
 * "auto" scheme renders no attribute at all and lets `prefers-color-scheme` pick the host's light-dark() tokens.
 *
 * Regression guard for two failures of AQG's own theme layer:
 * - auto + dark preference kept AQG's literal light muted/focus tokens, so AQG text landed at ~2.5:1 on the
 *   dark host surfaces;
 * - a bare `@media (prefers-color-scheme: dark) .aqg-module` rule painted hard-coded dark cards and chips into a
 *   module the editor had explicitly switched to Light.
 */
final class ModuleColorSchemeTest extends TestCase
{
    private const CSS = __DIR__ . '/../../../Resources/Public/Css/backend.css';

    private const AUTO_DARK = 'html:not([data-color-scheme=light])';

    /**
     * @return iterable<string, array{string}>
     */
    public static function moduleRootProvider(): iterable
    {
        yield 'page detail' => ['.a11y-page-detail.aqg-module'];
        yield 'overview' => ['.a11y-overview.aqg-module'];
        yield 'settings' => ['.a11y-settings.aqg-module'];
    }

    #[Test]
    #[DataProvider('moduleRootProvider')]
    public function autoSchemeWithDarkPreferenceResolvesTheExplicitDarkTokens(string $root): void
    {
        $rules = $this->rules();
        $explicitDark = $this->declarations($rules, false, 'html[data-color-scheme=dark] ' . $root);
        $autoDark = $this->declarations($rules, true, self::AUTO_DARK . ' ' . $root);

        $tokens = array_filter(
            $explicitDark,
            static fn (string $property): bool => str_starts_with($property, '--aqi-'),
            ARRAY_FILTER_USE_KEY
        );
        self::assertArrayHasKey('--aqi-fg-muted', $tokens, 'No explicit dark token block found for ' . $root . '.');
        self::assertSame('dark', $explicitDark['color-scheme'] ?? null);

        self::assertSame(
            'dark',
            $autoDark['color-scheme'] ?? null,
            $root . ': auto + dark preference must switch the module to the dark palette.'
        );
        foreach ($tokens as $property => $value) {
            self::assertSame(
                $value,
                $autoDark[$property] ?? null,
                sprintf('%s: auto + dark preference must resolve %s exactly like an explicit Dark choice.', $root, $property)
            );
        }
    }

    #[Test]
    public function darkPreferenceRulesNeverOverrideAnExplicitLightChoice(): void
    {
        $offenders = [];
        foreach ($this->rules() as $rule) {
            if (!$rule['darkPreference']) {
                continue;
            }
            foreach ($rule['selectors'] as $selector) {
                // A selector without a colour-scheme condition on the root also matches html[data-color-scheme=light].
                if (preg_match('/\[data-(color-scheme|theme|bs-theme)\b/', $selector) !== 1) {
                    $offenders[] = $selector;
                }
            }
        }

        self::assertSame(
            [],
            array_values(array_unique($offenders)),
            'These dark-preference selectors also match a module the editor explicitly switched to Light. Guard them '
            . 'with ' . self::AUTO_DARK . '.'
        );
    }

    /**
     * Declarations the cascade applies for one exact selector, in source order (later wins, as for equal
     * specificity).
     *
     * @param list<array{darkPreference: bool, selectors: list<string>, body: string}> $rules
     * @return array<string, string>
     */
    private function declarations(array $rules, bool $darkPreference, string $selector): array
    {
        $declarations = [];
        foreach ($rules as $rule) {
            if ($rule['darkPreference'] !== $darkPreference || !in_array($selector, $rule['selectors'], true)) {
                continue;
            }
            foreach (explode(';', $rule['body']) as $declaration) {
                $parts = explode(':', $declaration, 2);
                if (count($parts) === 2) {
                    $declarations[trim($parts[0])] = trim($parts[1]);
                }
            }
        }

        return $declarations;
    }

    /**
     * @return list<array{darkPreference: bool, selectors: list<string>, body: string}>
     */
    private function rules(): array
    {
        $css = (string)file_get_contents(self::CSS);
        self::assertNotSame('', $css, 'backend.css must be built before this test runs.');

        $css = str_replace("\u{FEFF}", '', $css);
        $css = (string)preg_replace(['#/\*.*?\*/#s', '/@(charset|import)[^;]*;/'], '', $css);

        return $this->parse($css, false);
    }

    /**
     * @return list<array{darkPreference: bool, selectors: list<string>, body: string}>
     */
    private function parse(string $css, bool $darkPreference): array
    {
        $rules = [];
        $offset = 0;
        while (($open = strpos($css, '{', $offset)) !== false) {
            $prelude = trim(substr($css, $offset, $open - $offset));
            $close = $this->matchingBrace($css, $open);
            $inner = substr($css, $open + 1, $close - $open - 1);

            if (str_starts_with($prelude, '@')) {
                if (preg_match('/^@(media|supports|container)\b/', $prelude) === 1) {
                    $isDark = $darkPreference || preg_match('/prefers-color-scheme:\s*dark/', $prelude) === 1;
                    array_push($rules, ...$this->parse($inner, $isDark));
                }
            } else {
                $rules[] = [
                    'darkPreference' => $darkPreference,
                    'selectors' => $this->splitSelectors($prelude),
                    'body' => $inner,
                ];
            }
            $offset = $close + 1;
        }

        return $rules;
    }

    private function matchingBrace(string $css, int $open): int
    {
        $depth = 0;
        for ($i = $open, $length = strlen($css); $i < $length; $i++) {
            if ($css[$i] === '{') {
                $depth++;
            } elseif ($css[$i] === '}' && --$depth === 0) {
                return $i;
            }
        }

        self::fail('Unbalanced braces in backend.css.');
    }

    /**
     * Splits a selector list on top-level commas only, so `:is(a, b)` stays one selector.
     *
     * @return list<string>
     */
    private function splitSelectors(string $prelude): array
    {
        $selectors = [];
        $depth = 0;
        $current = '';
        foreach (str_split($prelude) as $char) {
            if ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0) {
                $selectors[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $selectors[] = trim($current);

        return array_values(array_filter($selectors, static fn (string $selector): bool => $selector !== ''));
    }
}
