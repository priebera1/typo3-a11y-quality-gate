<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedErrorPageDetector;

final class RenderedErrorPageDetectorTest extends TestCase
{
    #[Test]
    public function detectsShortTechnicalErrorHtmlInsteadOfFrontendPage(): void
    {
        $result = (new RenderedErrorPageDetector())->detect('<html><body><p>Application exception - Search backend connection failed</p></body></html>');

        self::assertTrue($result->suspectedErrorPage);
        self::assertSame('application_exception', $result->reason);
    }

    #[Test]
    public function detectsGenericExceptionServerErrorWithoutClientSpecificPatterns(): void
    {
        $html = '<html><body><p>Unhandled exception - Search backend connection failed - Server error: GET https://search.example.invalid/select</p></body></html>';

        $result = (new RenderedErrorPageDetector())->detect($html);

        self::assertTrue($result->suspectedErrorPage);
        self::assertSame('exception_server_error', $result->reason);
    }

    #[Test]
    public function doesNotFlagNormalStructuredPageAboutExceptionHandling(): void
    {
        $html = '<!doctype html><html lang="en"><head><title>Exception handling in PHP</title></head><body><header>Header</header><main><h1>Exception handling in PHP</h1><p>This article explains exception handling patterns in PHP applications.</p></main><footer>Footer</footer></body></html>';

        $result = (new RenderedErrorPageDetector())->detect($html);

        self::assertFalse($result->suspectedErrorPage);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function detectsTypo3ExceptionMarkup(): void
    {
        $result = (new RenderedErrorPageDetector())->detect('<html><body><div class="typo3-exception"><h1>Oops, an error occurred!</h1></div></body></html>');

        self::assertTrue($result->suspectedErrorPage);
        self::assertSame('technical_exception_markup', $result->reason);
    }

    #[Test]
    public function doesNotFlagNormalServerRenderedPage(): void
    {
        $html = '<!doctype html><html lang="de-DE"><head><title>Mobile Fahrzeugsperren</title></head><body><header>Header</header><main><h1>Mobile Fahrzeugsperren</h1><p>Normal frontend content.</p></main><footer>Footer</footer></body></html>';

        $result = (new RenderedErrorPageDetector())->detect($html);

        self::assertFalse($result->suspectedErrorPage);
        self::assertSame('', $result->reason);
    }

    #[Test]
    public function doesNotFlagSimpleLegitimatePageWithoutMainLandmark(): void
    {
        $html = '<!doctype html><html lang="de-DE"><head><title>Test page</title></head><body><div>Normal content about the product.</div></body></html>';

        $result = (new RenderedErrorPageDetector())->detect($html);

        self::assertFalse($result->suspectedErrorPage);
        self::assertSame('', $result->reason);
    }
}
