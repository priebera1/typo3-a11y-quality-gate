<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Rendered;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Rendered\RenderedFrontendUrlSanitizer;

class RenderedFrontendUrlSanitizerTest extends TestCase
{
    private RenderedFrontendUrlSanitizer $sanitizer;

    protected function setUp(): void
    {
        $this->sanitizer = new RenderedFrontendUrlSanitizer();
    }

    #[Test]
    public function removesInternalRenderedCheckParameters(): void
    {
        $url = 'https://typo3test.priebera.sk/aqg-16?aqgDebug=1&tx_aqg_rendered_check=1&no_cache=1&_aqg_page=749&_aqg_lang=0&_aqg_nonce=secret';

        self::assertSame(
            'https://typo3test.priebera.sk/aqg-16',
            $this->sanitizer->sanitize($url)
        );
    }

    #[Test]
    public function preservesPublicQueryParametersAndFragment(): void
    {
        $url = 'https://example.org/page?foo=bar&aqgDebug=1&page=2&_aqg_nonce=secret#content';

        self::assertSame(
            'https://example.org/page?foo=bar&page=2#content',
            $this->sanitizer->sanitize($url)
        );
    }

    #[Test]
    public function keepsRootPathForRootUrl(): void
    {
        self::assertSame(
            'https://typo3test.priebera.sk/',
            $this->sanitizer->sanitize('https://typo3test.priebera.sk/?tx_aqg_rendered_check=1&_aqg_nonce=secret')
        );
    }

    #[Test]
    public function leavesInvalidOrRelativeUrlUntouched(): void
    {
        self::assertSame('/relative/path?_aqg_nonce=secret', $this->sanitizer->sanitize('/relative/path?_aqg_nonce=secret'));
    }
}
