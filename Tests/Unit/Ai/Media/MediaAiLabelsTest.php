<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Ai\Media;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Media\MediaAiLabels;

/**
 * The AI media UI receives its labels from MediaAiLabels; the JavaScript keeps English fallbacks. Both must say the
 * same, every label needs an English source and a German target in the language files.
 */
final class MediaAiLabelsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    #[Test]
    public function everyLabelHasAnEnglishSourceAndAGermanTarget(): void
    {
        $english = $this->units('locallang.xlf');
        $german = $this->units('de.locallang.xlf');
        $problems = [];
        foreach (MediaAiLabels::LABELS as $key => $fallback) {
            $id = MediaAiLabels::PREFIX . $key;
            if (($english[$id]['source'] ?? null) !== $fallback) {
                $problems[] = $id . ' differs from its English source';
            }
            if (($german[$id]['target'] ?? '') === '' || ($german[$id]['source'] ?? null) !== $fallback) {
                $problems[] = $id . ' has no German target or a stale source';
            }
            preg_match_all('/%[sd]/', $fallback, $source);
            preg_match_all('/%[sd]/', (string)($german[$id]['target'] ?? ''), $target);
            if ($source[0] !== $target[0]) {
                $problems[] = $id . ' has different placeholders in German';
            }
        }

        self::assertSame([], $problems);
    }

    #[Test]
    public function javaScriptFallbacksMatchTheLabelCatalogue(): void
    {
        $problems = [];
        foreach (glob(self::ROOT . '/Resources/Public/JavaScript/media-ai/*.js') ?: [] as $file) {
            $source = (string)file_get_contents($file);
            preg_match_all("/\\.(?:l|h)\\(\\s*'([A-Za-z0-9_]+)'\\s*,\\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $calls, PREG_SET_ORDER);
            foreach ($calls as [, $key, $fallback]) {
                $fallback = str_replace("\\'", "'", $fallback);
                if (!isset(MediaAiLabels::LABELS[$key])) {
                    $problems[] = basename($file) . ': ' . $key . ' is not in MediaAiLabels';
                } elseif (MediaAiLabels::LABELS[$key] !== $fallback) {
                    $problems[] = basename($file) . ': ' . $key . ' has another fallback';
                }
            }
        }

        self::assertSame([], $problems);
    }

    /** @return array<string,array{source:string,target:string}> */
    private function units(string $file): array
    {
        $xml = simplexml_load_file(self::ROOT . '/Resources/Private/Language/' . $file);
        self::assertNotFalse($xml);
        $units = [];
        foreach ($xml->file->body->{'trans-unit'} as $unit) {
            $units[(string)$unit['id']] = ['source' => (string)$unit->source, 'target' => (string)$unit->target];
        }

        return $units;
    }
}
