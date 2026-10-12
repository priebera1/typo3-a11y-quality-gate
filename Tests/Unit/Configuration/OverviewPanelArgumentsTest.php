<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Overview renders its source panels with an explicit argument list, so a view variable the controller assigns
 * reaches a panel partial only when Index.html names it. The stored occurrence totals were assigned but not passed:
 * the Frontend status head fell back to the crawler's summary (408) while the toolbar, PDF and CSV showed the 407
 * stored occurrences of the same scan.
 */
final class OverviewPanelArgumentsTest extends TestCase
{
    private const INDEX = __DIR__ . '/../../../Resources/Private/Templates/Overview/Index.html';

    #[Test]
    #[DataProvider('panelVariableProvider')]
    public function theOverviewPassesEveryPanelVariable(string $partial, string $variable): void
    {
        $index = (string)file_get_contents(self::INDEX);
        preg_match('/partial="' . preg_quote($partial, '/') . '"\s+arguments="\{(.*?)\}"/s', $index, $call);

        self::assertNotEmpty($call, $partial . ' is rendered with an argument list.');
        self::assertMatchesRegularExpression('/\b' . $variable . ':\s*' . $variable . '\b/', $call[1], $variable . ' reaches ' . $partial . '.');
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function panelVariableProvider(): iterable
    {
        yield 'stored Frontend occurrence totals' => ['Overview/RemotePanel', 'remoteOccurrences'];
        yield 'Content rendered-check coverage' => ['Overview/LocalPanel', 'localRenderedCoverage'];
    }
}
