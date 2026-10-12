<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rendered\RenderedFrontendUrlSanitizer;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueMapper;
use Priebera\A11yQualityGate\Rule\CheckContext;

/**
 * The content scan's rendered check requests AQG's record markers (`data-aqg-debug`, `div.aqg-marker`) to map
 * findings back to records. Those markers are AQG's own, not the page's.
 *
 * Regression guard (shared TYPO3 13 test instance, page 766, 2026-10-07): the page-level "main landmark missing"
 * finding stored `<body data-aqg-debug="1"><div class="aqg-marker" data-aqg-uid="1996" …` as its snippet. Whichever
 * content element came first changed the fingerprint, so every unchanged rescan resolved one variant and reopened
 * the other ("0 new, 1 resolved").
 */
final class RenderedHtmlIssueSnippetTest extends TestCase
{
    #[Test]
    public function aPageLevelFindingDoesNotDependOnAqgMarkersOrOnTheFirstContentElement(): void
    {
        $first = $this->bodyViolation(1996, 'image', '<p>Photo</p>');
        $second = $this->bodyViolation(1995, 'textmedia', '<p>Text and media</p>');

        self::assertStringNotContainsString('aqg', $first->contextSnippet);
        self::assertSame($first->contextSnippet, $second->contextSnippet);
        self::assertSame($first->fingerprint($this->checkContext()), $second->fingerprint($this->checkContext()));
    }

    #[Test]
    public function anElementSnippetLeavesOutAqgMarkersButKeepsThePagesOwnMarkup(): void
    {
        $document = $this->document(
            '<body data-aqg-debug="1"><div class="aqg-marker" data-aqg-table="tt_content" data-aqg-uid="7" data-aqg-content-uid="7">'
            . '<figure class="image"><div class="aqg-marker" data-aqg-uid="8"><img src="/fileadmin/a.png" data-aqg-x="1"></div></figure></div></body>'
        );
        $figure = $document->getElementsByTagName('figure')->item(0);
        self::assertInstanceOf(\DOMElement::class, $figure);

        self::assertSame('<figure class="image"><img src="/fileadmin/a.png"></figure>', $this->factory()->elementSnippet($figure));
    }

    private function bodyViolation(int $contentUid, string $cType, string $content): \Priebera\A11yQualityGate\Rule\RuleViolation
    {
        $document = $this->document(sprintf(
            '<body data-aqg-debug="1"><div class="aqg-marker" data-aqg-table="tt_content" data-aqg-uid="%1$d" data-aqg-content-uid="%1$d" data-aqg-pid="766" data-aqg-cid="tt_content:%1$d" data-aqg-ctype="%2$s" data-aqg-c-type="%2$s">%3$s</div></body>',
            $contentUid,
            $cType,
            $content,
        ));
        $body = $document->getElementsByTagName('body')->item(0);
        self::assertInstanceOf(\DOMElement::class, $body);

        return $this->factory()->create(
            new RenderedHtmlContext(pageUid: 766, languageUid: 0, siteIdentifier: 'global2', url: 'https://example.org/test', html: '', document: $document, xpath: new \DOMXPath($document)),
            $body,
            'rendered.main_landmark_missing',
            Severity::Warning,
            'Rendered page may be missing a main landmark.',
            '',
        );
    }

    private function document(string $body): \DOMDocument
    {
        $document = new \DOMDocument();
        $document->loadHTML('<!doctype html><html><head><title>t</title></head>' . $body . '</html>', LIBXML_NOERROR | LIBXML_NOWARNING);

        return $document;
    }

    private function factory(): RenderedHtmlIssueFactory
    {
        return new RenderedHtmlIssueFactory(new RenderedHtmlIssueMapper(), new RenderedFrontendUrlSanitizer());
    }

    private function checkContext(): CheckContext
    {
        return new CheckContext(
            siteIdentifier: 'global2',
            pageUid: 766,
            sourceLangUid: 0,
            sourceTable: 'pages',
            sourceUid: 766,
            sourceField: '__rendered_html',
            content: '',
            cType: '',
            contextPath: 'Page:766 > rendered HTML',
            sourceType: 'rendered',
        );
    }
}
