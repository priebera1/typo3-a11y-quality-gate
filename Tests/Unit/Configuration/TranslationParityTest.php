<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\FormEngine\EditorFeedbackLabels;
use Priebera\A11yQualityGate\Service\BackendJavaScriptModuleService;

/**
 * English and German labels stay in lockstep, and every label the UI asks for exists.
 *
 * Fluid defaults, JavaScript fallbacks and PHP fallbacks all render English when a key is missing, so a
 * gap never fails loudly in the backend. This test is where it fails.
 */
final class TranslationParityTest extends TestCase
{
    private const EXTENSION_ROOT = __DIR__ . '/../../..';
    private const LANGUAGE_DIRECTORY = self::EXTENSION_ROOT . '/Resources/Private/Language/';
    private const XLIFF_NAMESPACE = 'urn:oasis:names:tc:xliff:document:1.2';

    /**
     * Visible template text that is a name or a code, not prose: plan names, technical record
     * identifiers and example values. PDF export templates are English by design and are not checked.
     */
    private const UNTRANSLATED_TEXT_ALLOWLIST = [
        'PRO', '· PRO', 'FREE', 'TRIAL', '· TRIAL', 'AGENCY', 'ADMIN', 'KEY', 'OpenAI', 'WCAG', 'UID', 'ID',
        'table:', 'uid:', 'CType:', 'CID:', '&laquo;', '&raquo;', 'en', 'de', 'nl', 'cs', 'sk-proj-…',
    ];

    /** @var array<string, string>|null */
    private static ?array $englishSources = null;

    /**
     * @return array<string, array{string}>
     */
    public static function languageFiles(): array
    {
        return [
            'locallang.xlf' => ['locallang.xlf'],
            'locallang_mod.xlf' => ['locallang_mod.xlf'],
            'locallang_db.xlf' => ['locallang_db.xlf'],
        ];
    }

    #[Test]
    #[DataProvider('languageFiles')]
    public function germanFileMirrorsEveryEnglishLabel(string $file): void
    {
        $english = self::readUnits(self::LANGUAGE_DIRECTORY . $file);
        $german = self::readUnits(self::LANGUAGE_DIRECTORY . 'de.' . $file);

        self::assertNotSame([], $english, $file . ' has no labels.');
        self::assertSame([], array_values(array_diff(array_keys($english), array_keys($german))), 'Missing in de.' . $file);
        self::assertSame([], array_values(array_diff(array_keys($german), array_keys($english))), 'Stale keys only in de.' . $file);

        $drift = [];
        $untranslated = [];
        foreach ($english as $id => $unit) {
            if (($german[$id]['source'] ?? null) !== $unit['source']) {
                $drift[] = $id;
            }
            if (trim($german[$id]['target'] ?? '') === '') {
                $untranslated[] = $id;
            }
        }

        self::assertSame([], $drift, 'de.' . $file . ' must repeat the English <source> of every unit.');
        self::assertSame([], $untranslated, 'de.' . $file . ' units without a German <target>.');
    }

    #[Test]
    public function everyTemplateLabelHasAnEnglishSource(): void
    {
        $english = self::englishSources();
        $missing = [];

        foreach (self::files(self::EXTENSION_ROOT . '/Resources/Private', 'html') as $file => $source) {
            // Possessive match: a dynamic key such as "severity.{issue.severity}" is skipped, not truncated.
            preg_match_all('/locallang\.xlf:([A-Za-z0-9_.\-]++)(?!\{)/', $source, $fullReferences);
            preg_match_all('/<f:translate\s+key="([A-Za-z0-9_.\-]+)"\s+extensionName="A11yQualityGate"/', $source, $shortReferences);

            foreach (array_unique(array_merge($fullReferences[1], $shortReferences[1])) as $key) {
                if (!isset($english[$key])) {
                    $missing[] = $file . ': ' . $key;
                }
            }
        }

        self::assertSame([], $missing, 'Template labels without an English source.');
    }

    #[Test]
    public function everyJavaScriptLabelExistsAndReachesTheBrowser(): void
    {
        $english = self::englishSources();
        $problems = [];

        foreach (self::files(self::EXTENSION_ROOT . '/Resources/Public/JavaScript', 'js') as $file => $source) {
            preg_match_all('/\btranslate\(\s*[\'"]([A-Za-z0-9_.\-]+)[\'"]/', $source, $matches);

            foreach (array_unique($matches[1]) as $key) {
                if (!isset($english[$key])) {
                    $problems[] = sprintf('%s: "%s" has no English source', $file, $key);
                    continue;
                }
                if (!self::startsWithAny($key, BackendJavaScriptModuleService::JAVASCRIPT_LABEL_PREFIXES)) {
                    $problems[] = sprintf('%s: "%s" is not delivered by BackendJavaScriptModuleService::JAVASCRIPT_LABEL_PREFIXES', $file, $key);
                }
            }
        }

        self::assertSame([], $problems);
    }

    #[Test]
    public function editorFeedbackLabelsMatchTheirJavaScriptFallbacks(): void
    {
        $english = self::englishSources();
        $problems = [];

        foreach (EditorFeedbackLabels::LABELS as $key => $fallback) {
            $id = 'rte.' . $key;
            if (!isset($english[$id])) {
                $problems[] = $id . ' is missing';
            } elseif ($english[$id] !== $fallback) {
                $problems[] = $id . ' differs from the English fallback in EditorFeedbackLabels';
            }
        }

        self::assertSame([], $problems);
    }

    #[Test]
    public function everyLiteralPhpLabelKeyHasAnEnglishSource(): void
    {
        $english = self::englishSources();
        $missing = [];

        foreach (self::files(self::EXTENSION_ROOT . '/Classes', 'php') as $file => $source) {
            preg_match_all(
                '/(?:translateWithFallback|BackendLabelUtility::translate|->translate|->label)\(\s*\'([A-Za-z][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)+)\'\s*(?:,\s*\'([^\']*)\')?/',
                $source,
                $calls,
                PREG_SET_ORDER
            );
            foreach ($calls as $call) {
                // A second argument naming another language file is a key of that file.
                if (str_ends_with($call[2] ?? '', '.xlf')) {
                    continue;
                }
                if (!isset($english[$call[1]])) {
                    $missing[] = $file . ': ' . $call[1];
                }
            }

            // formatCountLabel()/countLabelFor() read "<prefix>.singular" and "<prefix>.plural".
            preg_match_all('/(?:formatCountLabel|countLabelFor)\([^,]+,\s*\'([A-Za-z0-9_.]+)\'/', $source, $countLabels);
            foreach ($countLabels[1] as $prefix) {
                foreach (['.singular', '.plural'] as $suffix) {
                    if (!isset($english[$prefix . $suffix])) {
                        $missing[] = $file . ': ' . $prefix . $suffix;
                    }
                }
            }
        }

        self::assertSame([], array_values(array_unique($missing)), 'PHP labels without an English source.');
    }

    #[Test]
    public function moduleTemplatesContainNoUntranslatedText(): void
    {
        $offenders = [];

        foreach (self::files(self::EXTENSION_ROOT . '/Resources/Private', 'html') as $file => $source) {
            if (str_contains($file, '/Export/')) {
                continue;
            }
            foreach (self::untranslatedText($source) as $text) {
                $offenders[] = $file . ': ' . $text;
            }
        }

        self::assertSame(
            [],
            $offenders,
            "Visible text must use f:translate, or be added to UNTRANSLATED_TEXT_ALLOWLIST when it is a name or code:\n"
            . implode("\n", $offenders)
        );
    }

    /**
     * @return list<string>
     */
    private static function untranslatedText(string $source): array
    {
        $source = (string)preg_replace(['/<!--.*?-->/s', '/<svg\b.*?<\/svg>/s', '/<f:translate\b[^>]*?\/>/s'], '', $source);

        // Fluid inline syntax, including nested expressions such as {items -> f:count()}.
        do {
            $source = (string)preg_replace('/\{[^{}]*\}/', '', $source, -1, $replaced);
        } while ($replaced > 0);

        $found = [];
        preg_match_all('/>([^<>]*)</', $source, $nodes);
        foreach ($nodes[1] as $node) {
            $text = trim((string)preg_replace('/\s+/', ' ', $node));
            if ($text === '' || preg_match('/[A-Za-z]{2,}/', $text) !== 1 || self::isAllowedLiteral($text)) {
                continue;
            }
            $found[] = $text;
        }

        preg_match_all('/\s(?:aria-label|title|placeholder|alt)="([^"]*)"/', $source, $attributes);
        foreach ($attributes[1] as $value) {
            $value = trim($value);
            if ($value === '' || preg_match('/[A-Za-z]{3,}/', $value) !== 1 || self::isAllowedLiteral($value)) {
                continue;
            }
            $found[] = '@' . $value;
        }

        return $found;
    }

    private static function isAllowedLiteral(string $text): bool
    {
        return in_array($text, self::UNTRANSLATED_TEXT_ALLOWLIST, true)
            // CSS selectors, paths, identifiers and URLs shown as examples.
            || preg_match('/^[#.\/]|[\[\]*_]|:\/\//', $text) === 1;
    }

    /**
     * @param list<string> $prefixes
     */
    private static function startsWithAny(string $key, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($key, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, string>
     */
    private static function englishSources(): array
    {
        if (self::$englishSources === null) {
            self::$englishSources = array_map(
                static fn (array $unit): string => $unit['source'],
                self::readUnits(self::LANGUAGE_DIRECTORY . 'locallang.xlf')
            );
        }

        return self::$englishSources;
    }

    /**
     * @return array<string, array{source: string, target: string}>
     */
    private static function readUnits(string $path): array
    {
        self::assertFileExists($path);

        $document = new \DOMDocument();
        self::assertTrue($document->load($path), 'Invalid XML: ' . $path);

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', self::XLIFF_NAMESPACE);

        $units = [];
        foreach ($xpath->query('//x:trans-unit') ?: [] as $unit) {
            if (!$unit instanceof \DOMElement) {
                continue;
            }
            $units[$unit->getAttribute('id')] = [
                'source' => self::childText($xpath, $unit, 'source'),
                'target' => self::childText($xpath, $unit, 'target'),
            ];
        }

        return $units;
    }

    private static function childText(\DOMXPath $xpath, \DOMElement $unit, string $name): string
    {
        $nodes = $xpath->query('./x:' . $name, $unit);
        $node = $nodes !== false ? $nodes->item(0) : null;

        return $node instanceof \DOMNode ? (string)$node->textContent : '';
    }

    /**
     * @return array<string, string> relative path => file content
     */
    private static function files(string $directory, string $extension): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== $extension) {
                continue;
            }
            $path = str_replace('\\', '/', $file->getPathname());
            // Bundled third-party code.
            if (str_contains($path, '/codemirror/')) {
                continue;
            }
            $files[substr($path, strlen(self::EXTENSION_ROOT) + 1)] = (string)file_get_contents($path);
        }
        ksort($files);

        return $files;
    }
}
