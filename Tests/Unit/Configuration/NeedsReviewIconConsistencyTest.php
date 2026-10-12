<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * "Needs review" had three symbols: a diamond with a question mark in the scan views, an empty diamond in the Page
 * module and the PDFs, and an eye in the tone badge. Every context now draws the scan views' symbol, next to its word.
 */
final class NeedsReviewIconConsistencyTest extends TestCase
{
    private const BASE = __DIR__ . '/../../../Resources/Private/Partials/';
    private const DIAMOND = 'M12 2.8 21.2 12 12 21.2 2.8 12z';
    private const QUESTION_MARK = 'M9.9 9.7a2.1 2.1 0 1 1 3 1.9c-.6.3-.9.8-.9 1.4M12 15.6v.1';

    public static function reviewIconProvider(): iterable
    {
        yield 'scan views (AqgIcon)' => ['Shared/AqgIcon.html', 'rev'];
        yield 'Page module' => ['Backend/PageModuleIndicator/Icon.html', 'review'];
        yield 'PDF severity' => ['Export/PdfSeverity.html', 'review'];
        yield 'tone badge' => ['Shared/ToneBadge.html', 'needs_review'];
    }

    #[Test]
    #[DataProvider('reviewIconProvider')]
    public function everyContextDrawsTheSameNeedsReviewSymbol(string $partial, string $case): void
    {
        $template = (string)file_get_contents(self::BASE . $partial);
        preg_match('/<f:case value="' . $case . '">(.*?)<\/f:case>/s', $template, $match);

        self::assertNotEmpty($match, 'The case "' . $case . '" exists.');
        self::assertStringContainsString(self::DIAMOND, $match[1]);
        self::assertStringContainsString(self::QUESTION_MARK, $match[1]);
    }
}
