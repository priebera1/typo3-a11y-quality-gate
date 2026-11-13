<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Utility;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Utility\ExternalLinkUtility;

final class ExternalLinkUtilityTest extends TestCase
{
    /** @return iterable<string, array{mixed, string}> */
    public static function urls(): iterable
    {
        yield 'https' => ['https://dequeuniversity.com/rules/axe/4.10/image-alt', 'https://dequeuniversity.com/rules/axe/4.10/image-alt'];
        yield 'http with port and query' => ['http://client.test:8080/page?x=1#a', 'http://client.test:8080/page?x=1#a'];
        yield 'surrounding whitespace trimmed' => ['  https://example.com/  ', 'https://example.com/'];
        yield 'javascript' => ['javascript:alert(1)', ''];
        yield 'javascript upper case' => ['JaVaScRiPt:alert(1)', ''];
        yield 'data' => ['data:text/html,<script>alert(1)</script>', ''];
        yield 'vbscript' => ['vbscript:msgbox(1)', ''];
        yield 'control character inside the scheme' => ["java\tscript:alert(1)", ''];
        yield 'protocol-relative' => ['//evil.example/x', ''];
        yield 'relative path' => ['/typo3/module/x', ''];
        yield 'no host' => ['https:///path', ''];
        yield 'not a string' => [['https://example.com'], ''];
        yield 'empty' => ['', ''];
    }

    #[Test]
    #[DataProvider('urls')]
    public function onlyAbsoluteHttpUrlsWithAHostAreLinked(mixed $input, string $expected): void
    {
        self::assertSame($expected, ExternalLinkUtility::httpOrEmpty($input));
    }
}
