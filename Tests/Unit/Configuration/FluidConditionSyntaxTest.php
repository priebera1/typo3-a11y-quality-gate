<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Fluid does not decode HTML entities in a condition: "{a} &amp;&amp; {b}" is no AND. Once the first operand is true
 * the whole condition is true, so "{freePreview.submitCapable} &amp;&amp; {canScanNow}" showed the Free scan button
 * to users without the scan permission, and the Free trial offer appeared without a Free result. Conditions use "&&".
 */
final class FluidConditionSyntaxTest extends TestCase
{
    #[Test]
    public function noConditionUsesAnEncodedAndOperator(): void
    {
        $offenders = [];
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(__DIR__ . '/../../../Resources/Private', \FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'html') {
                continue;
            }
            $source = (string)file_get_contents($file->getPathname());
            if (preg_match_all('/condition="[^"]*&amp;(?:&amp;|&)[^"]*"/', $source, $matches) > 0) {
                foreach ($matches[0] as $condition) {
                    $offenders[] = basename($file->getPathname()) . ': ' . $condition;
                }
            }
        }

        self::assertSame([], $offenders);
    }
}
