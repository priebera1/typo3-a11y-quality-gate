<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Pro\Enum\RemoteScanSourceType;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanInputResolver;
use TYPO3\CMS\Core\Http\RequestFactory;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;

final class FreeRemoteScanInputResolverTest extends TestCase
{
    #[Test]
    public function inputUsesServerResolvedPageUrlAndSinglePageLimits(): void
    {
        $site = $this->createMock(Site::class);
        $site->method('getBase')->willReturn(new Uri('https://WWW.Example.Test./subsite/'));
        $site->method('getIdentifier')->willReturn('main');
        $resolver = new RemoteScanInputResolver(
            new DomainNormalizer(),
            $this->createMock(RequestFactory::class),
        );

        $result = $resolver->resolveForFreePreview(
            $site,
            'https://WWW.Example.Test./subsite/contact',
        );

        self::assertSame('main', $result->siteIdentifier);
        self::assertSame('example.test', $result->domain);
        self::assertSame('https://WWW.Example.Test./subsite/contact', $result->startUrl);
        self::assertSame(1, $result->maxPages);
        self::assertNull($result->sitemapUrl);
        self::assertSame(RemoteScanSourceType::SinglePage, $result->sourceType);
        self::assertFalse($result->followLinks);
    }
}
