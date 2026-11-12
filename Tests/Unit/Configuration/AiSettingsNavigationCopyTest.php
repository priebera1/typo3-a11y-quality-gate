<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Messages that send an administrator to the AI settings name the path they can actually click: the module
 * ("Accessibility"), its "Settings" button and the tab "AI suggestions" (owner decision F-28: the tab keeps that label).
 *
 * Regression guard: 3.0 release candidates told admins to open "Settings › AI" / "Einstellungen › KI", a tab that
 * does not exist; a German admin found "KI-Vorschläge" instead. The path is built from the labels the backend renders,
 * so renaming the tab or the module fails here until every pointer follows.
 */
final class AiSettingsNavigationCopyTest extends TestCase
{
    private const LANGUAGE_DIR = __DIR__ . '/../../../Resources/Private/Language/';
    private const ROOT = __DIR__ . '/../../../';

    /** Keys that point an administrator to the AI suggestions tab. */
    private const POINTER_KEYS = [
        'rte.aiErrorPausedAdmin',
        'rte.aiPausedAckAdmin',
        'rte.aiPausedAckEditor',
        'rte.aiPausedBudgetAdmin',
        'rte.aiPausedBudgetEditor',
        'rte.aiBudgetReachedAdmin',
        'rte.aiNotConfiguredAdmin',
        'pageDetail.ai.paused',
        'remediation.reason.ai_paused',
        'mail.budgetAlert.reasonSite',
    ];

    /**
     * @return iterable<string, array{string}>
     */
    public static function pointerKeyProvider(): iterable
    {
        foreach (self::POINTER_KEYS as $key) {
            yield $key => [$key];
        }
    }

    #[Test]
    #[DataProvider('pointerKeyProvider')]
    public function englishPointerNamesTheVisiblePath(string $key): void
    {
        $path = $this->path('source');

        self::assertSame('Accessibility › Settings › AI suggestions', $path);
        self::assertStringContainsString($path, $this->units('locallang.xlf', 'source')[$key] ?? '', $key);
    }

    #[Test]
    #[DataProvider('pointerKeyProvider')]
    public function germanPointerNamesTheVisiblePath(string $key): void
    {
        $path = $this->path('target');

        self::assertSame('Barrierefreiheit › Einstellungen › KI-Vorschläge', $path);
        self::assertStringContainsString($path, $this->units('de.locallang.xlf', 'target')[$key] ?? '', $key);
    }

    #[Test]
    public function noLabelOrFallbackPointsToATabNamedAi(): void
    {
        self::assertSame([], $this->filesMatching(
            '/Settings [›>] AI(?! suggestions)|Einstellungen › KI(?!-Vorschläge)|guilabel:`AI`|Open AI settings/u'
        ), 'The settings tab is "AI suggestions" / "KI-Vorschläge".');
    }

    #[Test]
    public function everyPathToTheTabStartsAtTheModule(): void
    {
        // Most pointers are read outside the module (CKEditor, e-mail, docs); only the link label inside the module may
        // start at Settings.
        self::assertSame([], $this->filesMatching(
            '/(?<!Accessibility [›>] )(?<!Open )Settings [›>] AI suggestions|(?<!Barrierefreiheit › )Einstellungen › KI-Vorschläge(?! öffnen)/u'
        ), 'Name the module: "Accessibility › Settings › AI suggestions".');
    }

    #[Test]
    public function warningThresholdHelpRecommendsLessThanTheFullBudget(): void
    {
        $key = 'settings.ai.limits.warnHelp';

        self::assertStringContainsString('below 100%', $this->units('locallang.xlf', 'source')[$key] ?? '');
        self::assertStringContainsString('unter 100 %', $this->units('de.locallang.xlf', 'target')[$key] ?? '');
    }

    /**
     * @return list<string>
     */
    private function filesMatching(string $pattern): array
    {
        $paths = [self::ROOT . 'CHANGELOG.md'];
        foreach (['Classes', 'Resources/Private', 'Resources/Public/JavaScript', 'Documentation'] as $dir) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::ROOT . $dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if (preg_match('/\.(php|html|xlf|js|rst)$/', $file->getFilename())) {
                    $paths[] = $file->getPathname();
                }
            }
        }
        $offenders = [];
        foreach ($paths as $path) {
            if (preg_match($pattern, (string)file_get_contents($path))) {
                $offenders[] = substr($path, strlen(self::ROOT));
            }
        }
        sort($offenders);

        return $offenders;
    }

    /** The module label, the docheader button and the tab, as the backend renders them. */
    private function path(string $element): string
    {
        $prefix = $element === 'target' ? 'de.' : '';
        $module = $this->units($prefix . 'locallang_mod.xlf', $element)['mlang_tabs_tab'] ?? '';
        $labels = $this->units($prefix . 'locallang.xlf', $element);

        return $module . ' › ' . ($labels['settings.title'] ?? '') . ' › ' . ($labels['settings.tab.ai'] ?? '');
    }

    /**
     * @return array<string, string>
     */
    private function units(string $file, string $element): array
    {
        $xml = simplexml_load_file(self::LANGUAGE_DIR . $file);
        self::assertNotFalse($xml, $file);
        $units = [];
        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $units[(string)$unit['id']] = (string)$unit->{$element};
        }

        return $units;
    }
}
