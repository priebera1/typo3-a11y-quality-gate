<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered\Rule;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlContext;
use Priebera\A11yQualityGate\Rendered\RenderedHtmlIssueFactory;
use Priebera\A11yQualityGate\Rendered\Rule\ImgMissingAltRule;

final class ImgMissingAltRuleTest extends TestCase
{
    #[Test]
    public function emptyAltAttributeIsAcceptedForDecorativeFrontendImage(): void
    {
        $html = '<!doctype html><html lang="en"><body><img src="/decorative.jpg" alt=""></body></html>';
        $document = new \DOMDocument();
        $document->loadHTML($html, LIBXML_NOERROR | LIBXML_NOWARNING);
        $context = new RenderedHtmlContext(
            pageUid: 1,
            languageUid: 0,
            siteIdentifier: 'main',
            url: 'https://example.test/',
            html: $html,
            document: $document,
            xpath: new \DOMXPath($document),
        );
        $issueFactory = (new \ReflectionClass(RenderedHtmlIssueFactory::class))->newInstanceWithoutConstructor();
        $rule = new ImgMissingAltRule($issueFactory);

        self::assertSame([], iterator_to_array($rule->evaluate($context)));
        $image = $document->getElementsByTagName('img')->item(0);
        self::assertInstanceOf(\DOMElement::class, $image);
        self::assertTrue($image->hasAttribute('alt'));
        self::assertSame('', $image->getAttribute('alt'));
    }
}
