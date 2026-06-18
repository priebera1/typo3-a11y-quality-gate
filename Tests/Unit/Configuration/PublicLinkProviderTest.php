<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Configuration\PublicLinkProvider;

final class PublicLinkProviderTest extends TestCase
{
    private PublicLinkProvider $subject;

    protected function setUp(): void
    {
        $this->subject = new PublicLinkProvider();
    }

    #[Test]
    public function canonicalLinksContainAllSupportedDestinationsWithoutTrackingParameters(): void
    {
        self::assertSame([
            PublicLinkProvider::PRODUCT => 'https://typo3.priebera.sk/products/accessibility-quality-gate',
            PublicLinkProvider::DOCUMENTATION => 'https://typo3.priebera.sk/docs',
            PublicLinkProvider::PRICING => 'https://typo3.priebera.sk/pricing',
            PublicLinkProvider::TRIAL => 'https://typo3.priebera.sk/trial',
            PublicLinkProvider::SUPPORT => 'https://typo3.priebera.sk/contact',
            PublicLinkProvider::PORTAL => 'https://typo3.priebera.sk/portal',
        ], $this->subject->getCanonicalLinks());

        foreach ($this->subject->getCanonicalLinks() as $url) {
            self::assertStringNotContainsString('utm_', $url);
        }
    }

    #[Test]
    public function backendLinksAddReferralParametersExceptForPortal(): void
    {
        $links = $this->subject->getBackendLinks();

        foreach ([
            PublicLinkProvider::PRODUCT,
            PublicLinkProvider::DOCUMENTATION,
            PublicLinkProvider::PRICING,
            PublicLinkProvider::TRIAL,
            PublicLinkProvider::SUPPORT,
        ] as $name) {
            self::assertSame([
                'utm_source' => 'typo3_backend',
                'utm_medium' => 'referral',
                'utm_campaign' => 'aqg_extension',
            ], $this->queryParameters($links[$name]));
        }

        self::assertSame('https://typo3.priebera.sk/portal', $links[PublicLinkProvider::PORTAL]);
    }

    #[Test]
    public function individualAccessorsReturnCanonicalAndBackendVariants(): void
    {
        self::assertSame(
            'https://typo3.priebera.sk/docs',
            $this->subject->getCanonicalUrl(PublicLinkProvider::DOCUMENTATION)
        );
        self::assertSame(
            'https://typo3.priebera.sk/docs?utm_source=typo3_backend&utm_medium=referral&utm_campaign=aqg_extension',
            $this->subject->getBackendUrl(PublicLinkProvider::DOCUMENTATION)
        );
        self::assertSame(
            'https://typo3.priebera.sk/portal',
            $this->subject->getBackendUrl(PublicLinkProvider::PORTAL)
        );
    }

    #[Test]
    public function unknownLinkNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unknown public link "unknown".');

        $this->subject->getCanonicalUrl('unknown');
    }

    /**
     * @return array<string, string>
     */
    private function queryParameters(string $url): array
    {
        parse_str((string)parse_url($url, PHP_URL_QUERY), $parameters);

        return array_map(static fn (mixed $value): string => (string)$value, $parameters);
    }
}
