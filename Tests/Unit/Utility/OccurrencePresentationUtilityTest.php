<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Utility\OccurrencePresentationUtility;

final class OccurrencePresentationUtilityTest extends TestCase
{
    #[Test]
    public function snippetPreviewDecodesEntitiesAndCollapsesWhitespace(): void
    {
        // Stored rendered snippets keep numeric entities: the row shows the markup an editor would recognise.
        self::assertSame('<h2> </h2>', OccurrencePresentationUtility::snippetPreview('<h2>&#xA0;</h2>'));
        self::assertSame(
            '<select name="country"> <option>Slovakia</option> </select>',
            OccurrencePresentationUtility::snippetPreview("<select name=\"country\">\n    <option>Slovakia</option>\n</select>")
        );
        self::assertSame('', OccurrencePresentationUtility::snippetPreview("  \n "));
        // DOMDocument writes a carriage return in RTE markup as &#13;, which HTML5 decoding leaves as text.
        self::assertSame(
            '<table> <tr><th>Name</th></tr> </table>',
            OccurrencePresentationUtility::snippetPreview('<table>&#13; <tr><th>Name</th></tr>&#xD;&#x0d; </table>')
        );
    }

    #[Test]
    public function snippetPreviewShortensLongMarkupWithAnEllipsis(): void
    {
        $preview = OccurrencePresentationUtility::snippetPreview('<iframe src="https://example.com/a/very/long/path" title=""></iframe>', 30);

        self::assertSame(30, mb_strlen($preview));
        self::assertStringEndsWith('…', $preview);
        self::assertStringStartsWith('<iframe src="https://example', $preview);
    }

    #[Test]
    public function failureSummaryBecomesHeadingsWithTheirChecks(): void
    {
        $summary = "Fix any of the following:\n  Element does not have an alt attribute\n  aria-label attribute does not exist or is empty\n\nFix all of the following:\n  Element is in tab order";

        self::assertSame([
            ['heading' => 'Fix any of the following:', 'items' => ['Element does not have an alt attribute', 'aria-label attribute does not exist or is empty']],
            ['heading' => 'Fix all of the following:', 'items' => ['Element is in tab order']],
        ], OccurrencePresentationUtility::failureSummaryGroups($summary));
    }

    #[Test]
    public function aSingleLineFailureSummaryStaysOneItem(): void
    {
        self::assertSame(
            [['heading' => '', 'items' => ['Element has insufficient color contrast of 2.1']]],
            OccurrencePresentationUtility::failureSummaryGroups('Element has insufficient color contrast of 2.1')
        );
        self::assertSame([], OccurrencePresentationUtility::failureSummaryGroups(''));
    }

    #[Test]
    public function highlightSelectorAcceptsOnlyAPlainTopLevelTarget(): void
    {
        self::assertSame('img[src$="test.jpg"]', OccurrencePresentationUtility::highlightSelector('["img[src$=\"test.jpg\"]"]'));
        // iframe and shadow DOM targets are nested lists one querySelector() cannot resolve.
        self::assertSame('', OccurrencePresentationUtility::highlightSelector('["iframe", "button"]'));
        self::assertSame('', OccurrencePresentationUtility::highlightSelector('[["#host", "button"]]'));
        self::assertSame('', OccurrencePresentationUtility::highlightSelector('not json'));
        self::assertSame('', OccurrencePresentationUtility::highlightSelector(''));
        self::assertSame('', OccurrencePresentationUtility::highlightSelector(json_encode([str_repeat('a', 501)], JSON_THROW_ON_ERROR)));
    }

    #[Test]
    public function remediationSharedByEveryNodeIsRuleGuidance(): void
    {
        $remediation = ['summary' => 'Add alt text.', 'steps' => ['Open the element.'], 'hasSteps' => true];

        self::assertSame($remediation, OccurrencePresentationUtility::sharedRemediation([
            ['nodeRemediation' => $remediation],
            ['nodeRemediation' => []],
            ['nodeRemediation' => $remediation],
        ]));
        self::assertSame([], OccurrencePresentationUtility::sharedRemediation([
            ['nodeRemediation' => $remediation],
            ['nodeRemediation' => ['summary' => 'Change the foreground to #1f2937.', 'steps' => [], 'hasSteps' => false]],
        ]));
        self::assertSame([], OccurrencePresentationUtility::sharedRemediation([['nodeRemediation' => []]]));
    }

    /**
     * Regression (audit C5): an occurrence mapped to a record that does not exist in this installation (deleted, or a
     * uid from another installation scanning the same host) was labelled "No edit access", as if a permission was missing.
     */
    #[Test]
    public function aMissingRecordIsNotReportedAsMissingPermission(): void
    {
        self::assertSame('missing', OccurrencePresentationUtility::recordAccessState(recordExists: false, canEdit: false));
        self::assertSame('no-access', OccurrencePresentationUtility::recordAccessState(recordExists: true, canEdit: false));
        self::assertSame('edit', OccurrencePresentationUtility::recordAccessState(recordExists: true, canEdit: true));
    }

    /**
     * Audit D3: Content cards are titled with the plain rule title ("Heading is empty"); an occurrence repeats its
     * stored message only when that says more than the title ("Heading levels jump from H2 to H4").
     */
    #[Test]
    public function anOccurrenceShowsItsMessageOnlyWhenItAddsInformation(): void
    {
        self::assertSame('', OccurrencePresentationUtility::occurrenceWhat('Rendered heading is empty.', 'Heading is empty', 'en'));
        self::assertSame('', OccurrencePresentationUtility::occurrenceWhat('Link has no accessible name.', 'Link has no accessible name', 'en'));
        self::assertSame(
            'Heading levels jump from H2 to H4.',
            OccurrencePresentationUtility::occurrenceWhat('Heading levels jump from H2 to H4.', 'Heading levels are not sequential', 'en')
        );
        self::assertSame('', OccurrencePresentationUtility::occurrenceWhat('Heading levels jump from H2 to H4.', 'Überschriftenebenen sind nicht fortlaufend', 'de'), 'Stored messages are English.');
    }
}
