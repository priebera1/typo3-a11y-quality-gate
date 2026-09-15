<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * AQG-owned controls must come from AQG's shared control vocabulary, never from TYPO3 core or browser defaults, and
 * keep the semantics their role needs.
 */
final class ModuleControlConsistencyTest extends TestCase
{
    private const RESOURCES = __DIR__ . '/../../../Resources';

    /** Variants the shared AQG button family (Scss/base/_buttons.scss) styles. */
    private const BUTTON_VARIANTS = ['btn-default', 'btn-primary', 'btn-secondary', 'btn-info', 'btn-warning'];

    #[Test]
    public function everyAqgButtonIsStyledByAqgInsteadOfTheBrowser(): void
    {
        $styled = $this->classesWithOwnSurface();
        self::assertContains('aqg-bulkbar__clear', $styled, 'Expected AQG control rules in the built CSS.');

        $offenders = [];
        foreach ($this->templates() as $file => $source) {
            foreach ($this->controls($source) as [$line, $tag, $classes]) {
                $isButtonFamily = in_array('btn', $classes, true);
                if (!$isButtonFamily && $tag !== 'button') {
                    continue;
                }

                $hasVariant = array_intersect($classes, self::BUTTON_VARIANTS) !== [];
                if ($hasVariant || array_intersect($classes, $styled) !== []) {
                    continue;
                }

                $offenders[] = sprintf('%s:%d <%s class="%s">', $file, $line, $tag, implode(' ', $classes));
            }
        }

        self::assertSame(
            [],
            $offenders,
            'These controls fall back to TYPO3/browser default button styling. Use a btn variant from the shared AQG '
            . "button family or an AQG control class:\n" . implode("\n", $offenders)
        );
    }

    #[Test]
    public function ruleDocumentationIsALinkStyledLikeItsSiblingActions(): void
    {
        $template = (string)file_get_contents(self::RESOURCES . '/Private/Templates/RemotePageDetail/Show.html');

        self::assertSame(
            1,
            preg_match('/<div class="aqg-rule__actions aqg-rule__actions--body">(.*?)<\/div>/s', $template, $row),
            'Remote rule body action row not found.'
        );
        self::assertSame(
            1,
            preg_match('/<button\b([^>]*data-action="a11y-highlight-node"[^>]*)>(.*?)<\/button>/s', $row[1], $sibling),
            'The "Highlight all" sibling action is the reference style for this row.'
        );
        self::assertSame(
            1,
            preg_match('/<a\b([^>]*)>((?:(?!<\/a>).)*module\.remotePageDetail\.ruleDocs(?:(?!<\/a>).)*)<\/a>/s', $row[1], $docs),
            'Rule documentation must stay a link: it navigates to external documentation.'
        );

        [, $docsAttributes, $docsContent] = $docs;
        self::assertStringContainsString('href="{issue.help_url}"', $docsAttributes);
        self::assertStringContainsString('target="_blank"', $docsAttributes);
        self::assertMatchesRegularExpression('/rel="[^"]*\bnoopener\b[^"]*"/', $docsAttributes);

        self::assertSame(
            $this->classList($sibling[1]),
            $this->classList($docsAttributes),
            'Rule documentation must use the same AQG action style and size as the actions next to it.'
        );
        self::assertMatchesRegularExpression(
            '/<core:icon identifier="[a-z0-9-]+" size="small" \/>/',
            $docsContent,
            'Like its sibling actions, the link carries a decorative TYPO3 core icon.'
        );
        self::assertMatchesRegularExpression(
            '/<span>\s*<f:translate key="[^"]*:module\.remotePageDetail\.ruleDocs"/',
            $docsContent,
            'Like its sibling actions, the visible label is wrapped next to the icon.'
        );
    }

    #[Test]
    public function languageSwitcherTriggerKeepsItsVisibleValueInTheAccessibleName(): void
    {
        $partial = (string)file_get_contents(self::RESOURCES . '/Private/Partials/Shared/LanguageSwitcher.html');

        self::assertSame(1, preg_match('/<summary\b([^>]*)>(.*?)<\/summary>/s', $partial, $summary));
        [, $attributes, $content] = $summary;

        self::assertStringNotContainsString(
            'aria-label',
            $attributes,
            'An aria-label replaces the visible current language in the trigger\'s accessible name (WCAG 2.5.3).'
        );
        self::assertMatchesRegularExpression(
            '/<span class="visually-hidden">[^<]*Site language[^<]*<\/span>/',
            $content,
            'The trigger purpose stays announced through visually hidden text.'
        );
        self::assertStringContainsString('{currentLanguageOption.title}', $content);
    }

    /**
     * Module templates and partials; PDF exports use their own stylesheet.
     *
     * @return array<string, string>
     */
    private function templates(): array
    {
        $templates = [];
        foreach (['Templates', 'Partials'] as $directory) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator(self::RESOURCES . '/Private/' . $directory)
            );
            foreach ($files as $file) {
                if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'html') {
                    continue;
                }
                $path = str_replace('\\', '/', $file->getPathname());
                if (str_contains($path, '/Export/') || str_ends_with($path, 'Pdf.html')) {
                    continue;
                }
                $templates[substr($path, strpos($path, 'Resources/'))] = (string)file_get_contents($path);
            }
        }
        ksort($templates);
        self::assertNotSame([], $templates);

        return $templates;
    }

    /**
     * `<button>` elements and `<a>` elements using the button family, with their static classes.
     *
     * @return list<array{int, string, list<string>}>
     */
    private function controls(string $source): array
    {
        $controls = [];
        preg_match_all('/<(button|a)\b/', $source, $starts, PREG_OFFSET_CAPTURE);
        foreach ($starts[1] as [$tag, $offset]) {
            $attributes = $this->openingTag($source, (int)$offset);
            $classes = $this->classList($attributes);
            if ($tag === 'a' && !in_array('btn', $classes, true)) {
                continue;
            }
            $controls[] = [substr_count($source, "\n", 0, (int)$offset) + 1, $tag, $classes];
        }

        return $controls;
    }

    /**
     * The opening tag, ending at the first `>` outside a double-quoted attribute (Fluid conditions may contain `>`).
     */
    private function openingTag(string $source, int $offset): string
    {
        $inQuote = false;
        for ($i = $offset, $length = strlen($source); $i < $length; $i++) {
            if ($source[$i] === '"') {
                $inQuote = !$inQuote;
            } elseif ($source[$i] === '>' && !$inQuote) {
                return substr($source, $offset, $i - $offset);
            }
        }

        return substr($source, $offset);
    }

    /**
     * Static class names of a tag; Fluid inline expressions are dropped.
     *
     * @return list<string>
     */
    private function classList(string $attributes): array
    {
        if (preg_match('/\bclass="([^"]*)"/', $attributes, $match) !== 1) {
            return [];
        }

        $value = $match[1];
        do {
            $value = (string)preg_replace('/\{[^{}]*\}/', ' ', $value, -1, $count);
        } while ($count > 0);

        $classes = preg_split('/\s+/', trim($value)) ?: [];
        $classes = array_filter(
            $classes,
            static fn (string $class): bool => preg_match('/^[a-z][a-z0-9_-]*[a-z0-9]$/i', $class) === 1
        );
        sort($classes);

        return array_values(array_unique($classes));
    }

    /**
     * Classes that some AQG rule paints a surface for in their base (non-pseudo) state.
     *
     * @return list<string>
     */
    private function classesWithOwnSurface(): array
    {
        $classes = [];
        foreach (['backend.css', 'toolbar.css', 'page-module-indicator.css'] as $stylesheet) {
            $css = (string)file_get_contents(self::RESOURCES . '/Public/Css/' . $stylesheet);
            self::assertNotSame('', $css, $stylesheet . ' must be built before this test runs.');

            preg_match_all('/([^{}]+)\{([^{}]*)\}/', $css, $rules, PREG_SET_ORDER);
            foreach ($rules as [, $selectors, $body]) {
                if (preg_match('/(^|;)\s*(background(-color)?|border(-color)?|appearance)\s*:/', $body) !== 1) {
                    continue;
                }
                foreach (explode(',', $selectors) as $selector) {
                    $parts = preg_split('/[\s>+~]+/', trim($selector)) ?: [];
                    $subject = (string)end($parts);
                    if ($subject === '' || str_contains($subject, ':')) {
                        continue;
                    }
                    preg_match_all('/\.([a-z0-9_-]+)/i', $subject, $names);
                    array_push($classes, ...$names[1]);
                }
            }
        }

        return array_values(array_unique($classes));
    }
}
