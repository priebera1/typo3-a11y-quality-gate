<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedPageFetcher;

/**
 * Audit F-18: a scan stores the rendered-check error as text. Views map it back to its label key and show the
 * translation instead of the stored English.
 */
final class RenderedPageFetcherErrorKeyTest extends TestCase
{
    #[Test]
    public function storedEnglishErrorsMapToTheirLabelKey(): void
    {
        self::assertSame('privateHost', RenderedPageFetcher::errorKeyForMessage(
            'Rendered page check was skipped because the frontend URL resolves to a private/local address. For trusted DDEV or staging environments, enable “Allow private/local frontend hosts for rendered checks” in Settings → Rules.'
        ));
        self::assertSame('timeout', RenderedPageFetcher::errorKeyForMessage(
            ' Rendered page fetch timed out. This can happen on very large, protected or slow pages. Try scanning a smaller page, or use a frontend scan for browser-based checks. '
        ));
        self::assertSame('httpStatus', RenderedPageFetcher::errorKeyForMessage(
            'Rendered page returned HTTP 503. The local rendered check can only inspect successful HTML responses.'
        ));
    }

    #[Test]
    public function unknownTextHasNoKey(): void
    {
        self::assertSame('', RenderedPageFetcher::errorKeyForMessage(''));
        self::assertSame('', RenderedPageFetcher::errorKeyForMessage('Something else went wrong.'));
    }
}
