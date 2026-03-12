<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Scan;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Scan\ContentHashCalculator;

class ContentHashCalculatorTest extends TestCase
{
    private ContentHashCalculator $calc;

    protected function setUp(): void
    {
        $this->calc = new ContentHashCalculator();
    }

    // =========================================================================
    // RTE field hashing
    // =========================================================================

    #[Test]
    public function rteHashIsIdempotent(): void
    {
        $html = '<p>Hello <strong>world</strong></p>';
        self::assertSame($this->calc->forRteField($html), $this->calc->forRteField($html));
    }

    #[Test]
    public function rteHashIgnoresLeadingTrailingWhitespace(): void
    {
        $html1 = '<p>Hello</p>';
        $html2 = '  <p>Hello</p>  ';
        self::assertSame($this->calc->forRteField($html1), $this->calc->forRteField($html2));
    }

    #[Test]
    public function rteHashCollapsesInternalWhitespace(): void
    {
        $html1 = '<p>Hello  world</p>';
        $html2 = "<p>Hello\n world</p>";
        self::assertSame($this->calc->forRteField($html1), $this->calc->forRteField($html2));
    }

    #[Test]
    public function rteHashIsCaseInsensitive(): void
    {
        self::assertSame(
            $this->calc->forRteField('<P>Hello</P>'),
            $this->calc->forRteField('<p>Hello</p>')
        );
    }

    #[Test]
    public function rteHashDiffersForDifferentContent(): void
    {
        self::assertNotSame(
            $this->calc->forRteField('<p>Hello</p>'),
            $this->calc->forRteField('<p>World</p>')
        );
    }

    #[Test]
    public function rteHashIs40CharsSha1(): void
    {
        $hash = $this->calc->forRteField('<p>test</p>');
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $hash);
    }

    // =========================================================================
    // Structured field hashing
    // =========================================================================

    #[Test]
    public function structuredHashIsIdempotentForString(): void
    {
        self::assertSame(
            $this->calc->forStructuredField('Hello'),
            $this->calc->forStructuredField('Hello')
        );
    }

    #[Test]
    public function structuredHashIsIdempotentForInt(): void
    {
        self::assertSame(
            $this->calc->forStructuredField(42),
            $this->calc->forStructuredField(42)
        );
    }

    #[Test]
    public function structuredHashIsIdempotentForArray(): void
    {
        $arr = ['key' => 'value', 'num' => 3];
        self::assertSame(
            $this->calc->forStructuredField($arr),
            $this->calc->forStructuredField($arr)
        );
    }

    #[Test]
    public function structuredHashDiffersForDifferentValues(): void
    {
        self::assertNotSame(
            $this->calc->forStructuredField('foo'),
            $this->calc->forStructuredField('bar')
        );
    }

    #[Test]
    public function structuredHashIgnoresLeadingTrailingWhitespace(): void
    {
        self::assertSame(
            $this->calc->forStructuredField('hello'),
            $this->calc->forStructuredField('  hello  ')
        );
    }
}
