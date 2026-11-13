<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scan\ScanOrchestrator;

/**
 * Found while verifying the 2026-11-05 company acceptance fixes: `rendered.landmark_unique` (since 1.6.0) was missing
 * from the rule ids a completed rendered check may resolve, so its findings stayed open after the template was fixed and
 * every rescan. Every rendered rule class must be listed.
 */
final class RenderedRuleResolutionListTest extends TestCase
{
    #[Test]
    public function everyRenderedRuleCanBeResolvedByARenderedCheck(): void
    {
        $listed = (new \ReflectionClassConstant(ScanOrchestrator::class, 'RENDERED_RULE_IDS'))->getValue();
        $declared = [];
        foreach (glob(__DIR__ . '/../../../Classes/Rendered/Rule/*Rule.php') ?: [] as $file) {
            if (preg_match("/function getRuleId\\(\\): string\\s*\\{\\s*return '(rendered\\.[a-z_]+)';/", (string)file_get_contents($file), $match) === 1) {
                $declared[] = $match[1];
            }
        }
        sort($declared);

        self::assertNotEmpty($declared);
        self::assertSame([], array_values(array_diff($declared, $listed)), 'Rendered rules that a rescan could never resolve.');
    }
}
