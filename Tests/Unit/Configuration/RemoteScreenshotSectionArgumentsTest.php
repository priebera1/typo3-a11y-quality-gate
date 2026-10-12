<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The frontend page detail renders its screenshot section with an explicit argument list. The screenshot evidence
 * (numbered outlines, untouched Original) was assigned by the controller but not passed, so the Highlighted / Original
 * switch and the "numbers are the issue-type numbers" caption never rendered, although the AQG service delivered both.
 */
final class RemoteScreenshotSectionArgumentsTest extends TestCase
{
    private const SHOW = __DIR__ . '/../../../Resources/Private/Templates/RemotePageDetail/Show.html';

    #[Test]
    public function theScreenshotSectionGetsEveryVariableItUses(): void
    {
        $template = (string)file_get_contents(self::SHOW);
        preg_match('/<f:section name="RemoteScreenshot">(.*?)<\/f:section>/s', $template, $section);
        preg_match('/section="RemoteScreenshot"\s+arguments="\{(.*?)\}"/s', $template, $call);
        self::assertNotEmpty($section);
        self::assertNotEmpty($call);

        preg_match_all('/\{!?([a-zA-Z]+)[.}]/', $section[1], $used);
        foreach (array_unique($used[1]) as $variable) {
            self::assertMatchesRegularExpression('/\b' . $variable . ':\s*' . $variable . '\b/', $call[1], $variable . ' reaches the screenshot section.');
        }
    }
}
