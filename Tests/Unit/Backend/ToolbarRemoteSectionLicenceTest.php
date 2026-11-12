<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Backend;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Backend\ToolbarItems\A11yScanToolbarItem;
use Priebera\A11yQualityGate\Pro\Service\ProStatusResolverService;

/**
 * N-05 (final customer acceptance): every uncached backend render validated the licence for the alphabetically first
 * site, whatever site was shown — the toolbar asked "does any site have the crawler?" and validated the sites in order
 * until one answered. It asks for the site it shows, which the page validates anyway.
 */
final class ToolbarRemoteSectionLicenceTest extends TestCase
{
    #[Test]
    public function theFrontendCardAsksOnlyAboutTheSiteItShows(): void
    {
        $resolver = $this->createMock(ProStatusResolverService::class);
        $resolver->expects(self::never())->method('hasCrawlerForAnySite');
        $resolver->expects(self::once())->method('resolveForSiteIdentifier')->with('nordhafen')
            ->willReturn((object)['valid' => true, 'hasCrawler' => true]);

        self::assertTrue($this->shouldShowRemoteSection($resolver, 'nordhafen'));
    }

    #[Test]
    public function aSiteWithoutTheCrawlerShowsNoFrontendCard(): void
    {
        $resolver = $this->createMock(ProStatusResolverService::class);
        $resolver->expects(self::never())->method('hasCrawlerForAnySite');
        $resolver->method('resolveForSiteIdentifier')->willReturn((object)['valid' => true, 'hasCrawler' => false]);

        self::assertFalse($this->shouldShowRemoteSection($resolver, 'magazin'));
    }

    private function shouldShowRemoteSection(ProStatusResolverService $resolver, string $siteIdentifier): bool
    {
        $item = (new \ReflectionClass(A11yScanToolbarItem::class))->newInstanceWithoutConstructor();
        (function (ProStatusResolverService $resolver): void {
            $this->proStatusResolverService = $resolver;
        })->call($item, $resolver);

        return (new \ReflectionMethod(A11yScanToolbarItem::class, 'shouldShowRemoteSection'))->invoke($item, $siteIdentifier);
    }
}
