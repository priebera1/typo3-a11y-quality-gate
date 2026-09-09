<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Export;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Export\CsvValueSanitizer;

/**
 * CSV cells carry editor- and API-controlled text, so formula prefixes must be neutralised before
 * the file reaches a spreadsheet application.
 */
final class CsvValueSanitizerTest extends TestCase
{
    /**
     * @return iterable<string, array{mixed, mixed}>
     */
    public static function valueProvider(): iterable
    {
        yield 'equals formula' => ['=SUM(1,1)', "'=SUM(1,1)"];
        yield 'plus formula' => ['+cmd', "'+cmd"];
        yield 'minus formula' => ['-1+1', "'-1+1"];
        yield 'at formula' => ['@SUM(A1:A9)', "'@SUM(A1:A9)"];
        yield 'dde payload' => ['=cmd|\' /C calc\'!A0', "'=cmd|' /C calc'!A0"];
        yield 'tab smuggled formula' => ["\t=SUM(1,1)", "'\t=SUM(1,1)"];
        yield 'carriage return smuggled formula' => ["\r=SUM(1,1)", "'\r=SUM(1,1)"];
        // A leading line feed is stripped by spreadsheet importers before the first-character check,
        // so "\n=SUM(1,1)" opens as a live formula unless the cell is quoted here.
        yield 'line feed smuggled formula' => ["\n=SUM(1,1)", "'\n=SUM(1,1)"];
        yield 'line feed smuggled command' => ["\n+cmd|' /C calc'!A0", "'\n+cmd|' /C calc'!A0"];

        yield 'ordinary text' => ['Image is missing an alt attribute', 'Image is missing an alt attribute'];
        yield 'selector' => ['div > .content a[href="#"]', 'div > .content a[href="#"]'];
        yield 'url' => ['https://example.test/page-a', 'https://example.test/page-a'];
        yield 'html snippet' => ['<img src="x.png">', '<img src="x.png">'];
        yield 'empty string' => ['', ''];
        yield 'negative number stays numeric' => ['-5', '-5'];
        yield 'negative float stays numeric' => ['-1.5', '-1.5'];
        yield 'positive signed number stays numeric' => ['+42', '+42'];
        yield 'integer value untouched' => [42, 42];
        yield 'null untouched' => [null, null];
        yield 'leading space is not a formula' => [' =SUM(1,1)', ' =SUM(1,1)'];
    }

    #[Test]
    #[DataProvider('valueProvider')]
    public function formulaPrefixesAreNeutralisedAndOrdinaryContentIsPreserved(mixed $input, mixed $expected): void
    {
        self::assertSame($expected, CsvValueSanitizer::sanitize($input));
    }

    #[Test]
    public function rowsAreSanitisedCellByCell(): void
    {
        $row = [729, 'AQG 01', '=HYPERLINK("http://evil.test")', 'Critical', -5, null];

        self::assertSame(
            [729, 'AQG 01', "'=HYPERLINK(\"http://evil.test\")", 'Critical', -5, null],
            CsvValueSanitizer::sanitizeRow($row)
        );
    }

    #[Test]
    public function sanitisingTwiceDoesNotStackQuotes(): void
    {
        $once = CsvValueSanitizer::sanitize('=SUM(1,1)');

        self::assertSame($once, CsvValueSanitizer::sanitize($once));
    }

    #[Test]
    public function everyCsvWriterRoutesItsRowThroughTheSharedPolicy(): void
    {
        foreach (['IssueExporter.php', 'RemoteExportBuilder.php'] as $file) {
            $source = (string)file_get_contents(__DIR__ . '/../../../Classes/Export/' . $file);
            $writes = substr_count($source, 'fputcsv(');
            $sanitised = substr_count($source, 'fputcsv($output, CsvValueSanitizer::sanitizeRow(');

            self::assertGreaterThan(0, $writes, $file . ' has no CSV writes.');
            self::assertSame(
                $writes,
                $sanitised,
                $file . ' writes a CSV row without the shared formula-injection policy.'
            );
        }
    }
}
