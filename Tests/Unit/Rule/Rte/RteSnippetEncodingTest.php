<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rule\Rte;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\Rte\AbstractRteRule;

/**
 * Regression (audit C7): rich text was parsed without a charset, so libxml read UTF-8 as Latin-1. "Predvolený" was
 * stored as `Predvolen&#xC3;&#xBD;` and rules saw "PredvolenÃ½" as the element's text.
 */
final class RteSnippetEncodingTest extends TestCase
{
    #[Test]
    public function nonAsciiTextKeepsItsCharacters(): void
    {
        $probe = $this->probe();
        $dom = $probe->dom('<textarea name="msg">Predvolený text č ľ ť ň ł ő Д</textarea>');
        $textarea = $dom->getElementsByTagName('textarea')->item(0);

        self::assertInstanceOf(\DOMElement::class, $textarea);
        self::assertSame('Predvolený text č ľ ť ň ł ő Д', $textarea->textContent);
        self::assertSame(
            'Predvolený text č ľ ť ň ł ő Д',
            html_entity_decode(strip_tags($probe->snippet($textarea)), ENT_QUOTES | ENT_HTML5, 'UTF-8')
        );
        self::assertStringNotContainsString('&#xC3;', $probe->snippet($textarea));
    }

    private function probe(): object
    {
        return new class () extends AbstractRteRule {
            public function getRuleId(): string
            {
                return 'test.probe';
            }

            public function getDefaultSeverity(): Severity
            {
                return Severity::Info;
            }

            public function getMessage(): string
            {
                return '';
            }

            public function getHint(): string
            {
                return '';
            }

            public function check(CheckContext $context): array
            {
                return [];
            }

            public function dom(string $html): \DOMDocument
            {
                return $this->loadDom($html);
            }

            public function snippet(\DOMElement $element): string
            {
                return $this->elementSnippet($element);
            }
        };
    }
}
