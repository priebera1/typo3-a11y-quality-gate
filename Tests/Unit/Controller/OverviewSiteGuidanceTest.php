<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The Overview without a site context: "select a page" for no selection, a Site Configuration hint for a selected
 * page outside every site (PageSiteContextFunctionalTest tells the states apart), and the general hint otherwise.
 */
final class OverviewSiteGuidanceTest extends TestCase
{
    private const CONTROLLER = __DIR__ . '/../../../Classes/Controller/OverviewController.php';
    private const LABELS = __DIR__ . '/../../../Resources/Private/Language/locallang.xlf';

    #[Test]
    public function eachSiteContextStateGetsItsOwnGuidance(): void
    {
        $controller = (string)file_get_contents(self::CONTROLLER);

        self::assertMatchesRegularExpression(
            '/\$currentPageUid <= 0 => \[\s*\'title\' => \$this->translateWithFallback\(\'overview\.emptyState\.selectPage\.title\'/',
            $controller,
            'No selected page keeps "Select a page to start".'
        );
        self::assertMatchesRegularExpression(
            '/SiteResolutionService::PAGE_CONTEXT_OUTSIDE_SITE => \[\s*\'title\' => \$this->translateWithFallback\(\'overview\.emptyState\.pageOutsideSite\.title\'/',
            $controller
        );
        self::assertMatchesRegularExpression(
            '/default => \[\s*\'title\' => \$this->translateWithFallback\(\'overview\.emptyState\.noSite\.title\'/',
            $controller,
            'A page id that names no page keeps the existing hint.'
        );
    }

    #[Test]
    public function theOutsideSiteGuidanceNamesTheFixNotThePageSelection(): void
    {
        $labels = (string)file_get_contents(self::LABELS);
        preg_match_all('/<trans-unit id="overview\.emptyState\.pageOutsideSite\.[a-z]+">\s*<source>([^<]+)<\/source>/', $labels, $matches);
        $text = implode(' ', $matches[1]);

        self::assertCount(3, $matches[1]);
        self::assertStringContainsString('Site Configuration', $text);
        self::assertStringNotContainsString('Select a page inside', $text);
    }
}
