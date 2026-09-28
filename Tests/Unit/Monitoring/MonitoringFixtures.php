<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Monitoring;

use Priebera\A11yQualityGate\Monitoring\MonitoringTargetResolver;
use Priebera\A11yQualityGate\Service\SiteLanguageService;
use Priebera\A11yQualityGate\Service\SiteResolutionService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\Entity\Site;

/**
 * Two configured sites with different languages: "main" (English default, German as language 2, a disabled
 * French language 3) and "shop" with only its default language. Language 1 exists on neither.
 */
trait MonitoringFixtures
{
    /**
     * @return array<string, Site>
     */
    protected static function sites(): array
    {
        return [
            'shop' => new Site('shop', 20, [
                'base' => 'https://shop.example.org/',
                'websiteTitle' => 'Shop',
                'languages' => [
                    ['languageId' => 0, 'title' => 'English', 'locale' => 'en_GB.UTF-8', 'base' => '/', 'enabled' => true],
                ],
            ]),
            'main' => new Site('main', 1, [
                'base' => 'https://example.org/',
                'websiteTitle' => 'Example',
                'languages' => [
                    ['languageId' => 0, 'title' => 'English', 'locale' => 'en_US.UTF-8', 'base' => '/', 'enabled' => true],
                    ['languageId' => 2, 'title' => 'Deutsch', 'locale' => 'de_DE.UTF-8', 'base' => '/de/', 'enabled' => true],
                    ['languageId' => 3, 'title' => 'Français', 'locale' => 'fr_FR.UTF-8', 'base' => '/fr/', 'enabled' => false],
                ],
            ]),
        ];
    }

    protected function resolverFor(?array $sites = null): MonitoringTargetResolver
    {
        $sites ??= self::sites();
        $siteResolution = $this->createMock(SiteResolutionService::class);
        $siteResolution->method('getAllSites')->willReturn($sites);
        $siteResolution->method('resolveSiteByIdentifier')->willReturnCallback(static fn(string $identifier): ?Site => $sites[trim($identifier)] ?? null);

        return new MonitoringTargetResolver(
            $siteResolution,
            new SiteLanguageService($siteResolution, $this->createMock(ConnectionPool::class)),
        );
    }
}
