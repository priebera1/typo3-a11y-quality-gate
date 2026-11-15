<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Ai\Editor\EditorAiRuleCatalog;
use Priebera\A11yQualityGate\Ai\Editor\EditorFindingTarget;
use Priebera\A11yQualityGate\Remediation\Workflow\RichTextElementWriter;

/**
 * The direct apply's element change (AQG 3.0.0): exactly one attribute or one plain text, every other byte of the
 * field unchanged, proven against the editor flow's document; anything not provable is refused.
 */
final class RichTextElementWriterTest extends TestCase
{
    private static function target(string $tag, int $occurrence, string $operation, string $attribute = ''): EditorFindingTarget
    {
        return new EditorFindingTarget(
            ruleId: 'rte.test', kind: EditorAiRuleCatalog::KIND_LINK_TEXT, operation: $operation, attribute: $attribute, tag: $tag,
            occurrence: $occurrence, total: -1, contextPath: '', attributes: [], text: '', hasChildElements: false, currentValue: '',
        );
    }

    /** @return iterable<string, array{0:string,1:EditorFindingTarget,2:string,3:string}> */
    public static function changes(): iterable
    {
        $attr = EditorAiRuleCatalog::OPERATION_ATTRIBUTE;
        $text = EditorAiRuleCatalog::OPERATION_TEXT;
        yield 'iframe title added, other attributes and formatting kept' => [
            "<p>Map</p>\n<IFRAME  src='https://maps.example.test/?a=1&amp;b=2'   width=600></IFRAME>",
            self::target('iframe', 0, $attr, 'title'), 'Map: shop',
            "<p>Map</p>\n<IFRAME  src='https://maps.example.test/?a=1&amp;b=2'   width=600 title=\"Map: shop\"></IFRAME>",
        ];
        yield 'empty title attribute replaced' => [
            '<iframe title="" src="/v"></iframe>', self::target('iframe', 0, $attr, 'title'), 'Video', '<iframe title="Video" src="/v"></iframe>',
        ];
        yield 'self-closing iframe' => [
            '<iframe src="/v" />', self::target('iframe', 0, $attr, 'title'), 'Video', '<iframe src="/v" title="Video"/>',
        ];
        yield 'button aria-label, value escaped' => [
            '<button type="submit" class="i"></button>', self::target('button', 0, $attr, 'aria-label'), 'Send "now" & go',
            '<button type="submit" class="i" aria-label="Send &quot;now&quot; &amp; go"></button>',
        ];
        yield 'second of two identical links, a commented one and an attribute with ">" ignored' => [
            "<!-- <a href=\"/a\">x</a> --><p data-x=\"1 > 0\"><a href=\"/a\">read more</a> and <a href=\"/a\">read more</a></p>",
            self::target('a', 1, $text), 'Our prices',
            "<!-- <a href=\"/a\">x</a> --><p data-x=\"1 > 0\"><a href=\"/a\">read more</a> and <a href=\"/a\">Our prices</a></p>",
        ];
        yield 'link text escaped, never markup' => [
            '<p><a href="/x">here</a></p>', self::target('a', 0, $text), '<script>alert(1)</script> Prices', '<p><a href="/x">&lt;script&gt;alert(1)&lt;/script&gt; Prices</a></p>',
        ];
        yield 'empty heading filled' => [
            "<h2>Intro</h2>\n<h2>  </h2>\n<p>Text</p>", self::target('h2', 1, $text), 'Opening hours', "<h2>Intro</h2>\n<h2>Opening hours</h2>\n<p>Text</p>",
        ];
    }

    #[Test]
    #[DataProvider('changes')]
    public function changesExactlyTheTargetElement(string $html, EditorFindingTarget $target, string $value, string $expected): void
    {
        $result = RichTextElementWriter::apply($html, $target, $value);

        self::assertSame(RichTextElementWriter::OK, $result['reason']);
        self::assertSame($expected, $result['html']);
    }

    /** @return iterable<string, array{0:string,1:EditorFindingTarget,2:string}> */
    public static function refusals(): iterable
    {
        $text = EditorAiRuleCatalog::OPERATION_TEXT;
        yield 'markup inside the link' => ['<a href="/s"><span class="icon"></span></a>', self::target('a', 0, $text), RichTextElementWriter::NESTED_MARKUP];
        yield 'element not there' => ['<p>No links</p>', self::target('a', 0, $text), RichTextElementWriter::ELEMENT_NOT_FOUND];
        yield 'no end tag' => ['<p><a href="/x">open', self::target('a', 0, $text), RichTextElementWriter::ELEMENT_NOT_FOUND];
        yield 'escaped HTML source' => ['<p>&lt;a href="/x"&gt;here&lt;/a&gt;</p>', self::target('a', 0, $text), RichTextElementWriter::ENCODED_SOURCE];
        yield 'heading level change is not a text change' => ['<h4>x</h4>', self::target('h4', 0, EditorAiRuleCatalog::OPERATION_HEADING_LEVEL), RichTextElementWriter::UNSUPPORTED];
        yield 'parser-repaired markup cannot be proven' => ['<p><a href="/x">a<p>b</p></a></p>', self::target('a', 0, $text), RichTextElementWriter::NESTED_MARKUP];
        yield 'element count differs from the parser (tag inside a script)' => ['<script>var s="<a href=x>";</script><p><a href="/y">here</a></p>', self::target('a', 0, $text), '*'];
    }

    #[Test]
    #[DataProvider('refusals')]
    public function refusesWhatItCannotProve(string $html, EditorFindingTarget $target, string $reason): void
    {
        $result = RichTextElementWriter::apply($html, $target, 'Some text');

        // '*': any refusal (which check stops it first is an implementation detail).
        $reason === '*' ? self::assertNotSame(RichTextElementWriter::OK, $result['reason']) : self::assertSame($reason, $result['reason']);
        self::assertSame('', $result['html']);
    }

    #[Test]
    public function anAttributeNameInsideAnotherAttributeValueNeverMisleadsTheWrite(): void
    {
        // A naive attribute match would rewrite the quoted value; the proof refuses that or the write is exact.
        $html = '<iframe data-note=" title=old" src="/v"></iframe>';
        $result = RichTextElementWriter::apply($html, self::target('iframe', 0, EditorAiRuleCatalog::OPERATION_ATTRIBUTE, 'title'), 'Video');

        if ($result['reason'] === RichTextElementWriter::OK) {
            self::assertSame('<iframe data-note=" title=old" src="/v" title="Video"></iframe>', $result['html']);
        } else {
            self::assertSame(RichTextElementWriter::NOT_PROVEN, $result['reason']);
        }
    }

    #[Test]
    public function anEmptyValueIsNeverWritten(): void
    {
        self::assertSame(RichTextElementWriter::UNSUPPORTED, RichTextElementWriter::apply('<a href="/x">here</a>', self::target('a', 0, EditorAiRuleCatalog::OPERATION_TEXT), '  ')['reason']);
    }
}
