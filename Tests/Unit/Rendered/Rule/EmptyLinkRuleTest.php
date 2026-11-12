<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered\Rule;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Rendered\Rule\EmptyLinkRule;
use Priebera\A11yQualityGate\Rule\RuleViolation;

/**
 * D-02 (2026-11-05 company acceptance): fluid_styled_content renders `<a id="c34"></a>` before every translated content
 * element. It has no href and no interactive role, so it is no link — it was reported as a critical "Rendered link has no
 * accessible name" on every translated element of every multilingual site.
 */
final class EmptyLinkRuleTest extends TestCase
{
    #[Test]
    public function aTranslationAnchorWithoutHrefIsNotALink(): void
    {
        self::assertSame([], $this->evaluate('<div id="c3" class="frame"><a id="c34"></a><p>Text</p></div>'));
        self::assertSame([], $this->evaluate('<a name="top"></a>'));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function emptyInteractiveLinks(): iterable
    {
        yield 'href' => ['<a href="https://example.org/spec.pdf"></a>'];
        yield 'empty href' => ['<a href=""></a>'];
        yield 'icon only' => ['<a href="/configure"><span class="icon-arrow"></span></a>'];
        yield 'role link' => ['<a role="link"></a>'];
        yield 'role button' => ['<a role="button"></a>'];
        yield 'tabindex' => ['<a tabindex="0"></a>'];
        yield 'click handler' => ['<a onclick="go()"></a>'];
    }

    #[Test]
    #[DataProvider('emptyInteractiveLinks')]
    public function emptyInteractiveLinksAreStillReported(string $html): void
    {
        self::assertCount(1, $this->evaluate($html));
    }

    #[Test]
    public function namedLinksAreNotReported(): void
    {
        self::assertSame([], $this->evaluate('<a href="/c2">Cargo Bike C2 details</a><a href="/x" aria-label="Open configurator"></a>'));
    }

    /**
     * @return list<RuleViolation>
     */
    private function evaluate(string $body): array
    {
        $html = '<!doctype html><html lang="en"><body>' . $body . '</body></html>';
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $context = new RenderedHtmlContext(
            pageUid: 4,
            languageUid: 1,
            siteIdentifier: 'northwind',
            url: 'https://example.test/de/produkte/lastenraeder',
            html: $html,
            document: $document,
            xpath: new \DOMXPath($document),
        );
        $factory = $this->createMock(RenderedHtmlIssueFactory::class);
        $factory->method('create')->willReturnCallback(
            static fn (RenderedHtmlContext $c, \DOMElement $e, string $ruleId): RuleViolation => (new \ReflectionClass(RuleViolation::class))->newInstanceWithoutConstructor()
        );

        return iterator_to_array((new EmptyLinkRule($factory))->evaluate($context), false);
    }
}
