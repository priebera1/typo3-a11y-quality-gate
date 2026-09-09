<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AQG renders muted/subtle/neutral text at 10-12.5px, so WCAG 2.1 AA (1.4.3) requires >= 4.5:1.
 *
 * Regression guard: these tokens used to be bound to --typo3-text-color-variant, a decorative host
 * tone that resolves to ~2.67:1 in the TYPO3 light backend and ~3.5:1 in dark. Binding an AQG body
 * text token to a host variable makes its contrast unverifiable, so the tokens must stay literal.
 */
final class ModuleTextContrastTest extends TestCase
{
    private const TOKENS = __DIR__ . '/../../../Resources/Private/Scss/typo3/_theme-tokens.scss';

    private const TEXT_TOKENS = ['--aqi-fg-muted', '--aqi-fg-subtle', '--aqi-none'];

    /**
     * AQG surfaces those tokens are painted on. The first three are the token fallbacks in this
     * file; the trailing values are the surfaces the TYPO3 13/14 backends actually resolve to,
     * measured on the shared test instances.
     *
     * @var array<string, list<string>>
     */
    private const SURFACES = [
        'light' => ['#ffffff', '#f5f6f8', '#fafbfc', '#fcfcfd', '#f4f4f6', '#f8fafc'],
        'dark' => ['#14181d', '#1c2128', '#232931', '#131416', '#25292e', '#1f232a', '#252b33'],
    ];

    /**
     * AQG paints its own tinted chip/segment/count pills as
     * `color-mix(in oklch, var(--aqi-fg) 6%, transparent)` over the surface. Measuring only the
     * plain surfaces once let #667085 ship at 4.02:1 on those pills.
     */
    private const TINT_RATIO = 0.06;

    private const TINT_BASE = ['light' => ['#1f2937', '#0a0a0a'], 'dark' => ['#e7ebf0', '#f2f2f2']];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function tokenProvider(): iterable
    {
        foreach (['light', 'dark'] as $scheme) {
            foreach (self::TEXT_TOKENS as $token) {
                yield $scheme . ' ' . $token => [$scheme, $token];
            }
        }
    }

    #[Test]
    #[DataProvider('tokenProvider')]
    public function moduleTextTokenMeetsWcagAaOnEveryAqgSurface(string $scheme, string $token): void
    {
        $value = $this->tokenValue($scheme, $token);

        self::assertMatchesRegularExpression(
            '/^#[0-9a-f]{6}$/i',
            $value,
            sprintf(
                '%s must be a literal colour in the %s block. Binding it to a host variable such as '
                . '--typo3-text-color-variant makes its contrast unverifiable and drops it below AA.',
                $token,
                $scheme
            )
        );

        foreach ($this->surfacesFor($scheme) as $surface) {
            $ratio = $this->contrastRatio($value, $surface);

            self::assertGreaterThanOrEqual(
                4.5,
                $ratio,
                sprintf(
                    'WCAG AA 1.4.3: %s (%s) on %s in the %s block is %.2f:1, below 4.5:1.',
                    $token,
                    $value,
                    $surface,
                    $scheme,
                    $ratio
                )
            );
        }
    }

    #[Test]
    public function mutedStaysMoreProminentThanSubtle(): void
    {
        foreach (['light' => '#ffffff', 'dark' => '#14181d'] as $scheme => $surface) {
            $muted = $this->contrastRatio($this->tokenValue($scheme, '--aqi-fg-muted'), $surface);
            $subtle = $this->contrastRatio($this->tokenValue($scheme, '--aqi-fg-subtle'), $surface);
            $base = $this->contrastRatio($this->fallbackLiteral($scheme, '--aqi-fg'), $surface);

            self::assertGreaterThanOrEqual($muted, $base, $scheme . ': base text must not be weaker than muted.');
            self::assertGreaterThanOrEqual($subtle, $muted, $scheme . ': muted text must not be weaker than subtle.');
        }
    }

    #[Test]
    public function noAqgRuleTakesItsTextColourStraightFromTheHostVariant(): void
    {
        $scssDir = __DIR__ . '/../../../Resources/Private/Scss';
        $offenders = [];

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($scssDir));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'scss') {
                continue;
            }

            $contents = (string)file_get_contents($file->getPathname());
            if (str_contains($contents, 'color: var(--typo3-text-color-variant)')) {
                $offenders[] = $file->getFilename();
            }
        }

        self::assertSame(
            [],
            $offenders,
            'AQG text must use --aqi-fg-muted / --aqi-fg-subtle, never the raw host variant.'
        );
    }

    /**
     * --aqi-fg intentionally follows the host's base text colour; only its literal fallback can be
     * measured here.
     */
    private function fallbackLiteral(string $scheme, string $token): string
    {
        $value = $this->tokenValue($scheme, $token);
        self::assertSame(
            1,
            preg_match('/#[0-9a-f]{6}(?!.*#[0-9a-f]{6})/is', $value, $matches),
            sprintf('%s has no literal fallback colour in the %s block.', $token, $scheme)
        );

        return $matches[0];
    }

    private function tokenValue(string $scheme, string $token): string
    {
        $scss = (string)file_get_contents(self::TOKENS);
        $blocks = $this->splitDeclarationBlocks($scss);

        // The light block is the first module-root block; the dark block is the first one that
        // switches color-scheme.
        $target = null;
        foreach ($blocks as $block) {
            $isDark = str_contains($block, 'color-scheme: dark');
            if (($scheme === 'dark') === $isDark && str_contains($block, '--aqi-fg-muted:')) {
                $target = $block;
                break;
            }
        }

        self::assertIsString($target, sprintf('No %s token block found in _theme-tokens.scss.', $scheme));
        self::assertSame(
            1,
            preg_match('/' . preg_quote($token, '/') . ':\s*([^;]+);/', $target, $matches),
            sprintf('%s is not declared in the %s block.', $token, $scheme)
        );

        return trim($matches[1]);
    }

    /**
     * @return list<string>
     */
    private function splitDeclarationBlocks(string $scss): array
    {
        preg_match_all('/\{([^{}]*)\}/s', $scss, $matches);

        return array_values($matches[1]);
    }

    /**
     * Plain surfaces plus the tinted backgrounds AQG composites on top of them.
     *
     * @return list<string>
     */
    private function surfacesFor(string $scheme): array
    {
        $surfaces = self::SURFACES[$scheme];

        foreach (self::SURFACES[$scheme] as $surface) {
            foreach (self::TINT_BASE[$scheme] as $base) {
                $surfaces[] = $this->mix($base, $surface, self::TINT_RATIO);
            }
        }

        return array_values(array_unique($surfaces));
    }

    private function mix(string $foreground, string $background, float $alpha): string
    {
        $fg = $this->channels($foreground);
        $bg = $this->channels($background);

        return sprintf(
            '#%02x%02x%02x',
            (int)round($alpha * $fg[0] + (1 - $alpha) * $bg[0]),
            (int)round($alpha * $fg[1] + (1 - $alpha) * $bg[1]),
            (int)round($alpha * $fg[2] + (1 - $alpha) * $bg[2]),
        );
    }

    /**
     * @return array{int, int, int}
     */
    private function channels(string $hex): array
    {
        $hex = ltrim(trim($hex), '#');

        return [
            (int)hexdec(substr($hex, 0, 2)),
            (int)hexdec(substr($hex, 2, 2)),
            (int)hexdec(substr($hex, 4, 2)),
        ];
    }

    private function contrastRatio(string $foreground, string $background): float
    {
        $lighter = max($this->relativeLuminance($foreground), $this->relativeLuminance($background));
        $darker = min($this->relativeLuminance($foreground), $this->relativeLuminance($background));

        return ($lighter + 0.05) / ($darker + 0.05);
    }

    private function relativeLuminance(string $hex): float
    {
        $hex = ltrim(trim($hex), '#');
        self::assertSame(6, strlen($hex), 'Expected a 6-digit hex colour, got "' . $hex . '".');

        $channels = [];
        foreach ([0, 2, 4] as $offset) {
            $value = hexdec(substr($hex, $offset, 2)) / 255;
            $channels[] = $value <= 0.04045
                ? $value / 12.92
                : (($value + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
