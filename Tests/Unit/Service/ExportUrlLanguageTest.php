<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\ExportUrlBuilderService;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Http\Uri;

/** The Content Scan export links name the language the view shows, like the Frontend scan links already do. */
final class ExportUrlLanguageTest extends TestCase
{
    #[Test]
    public function localExportLinksCarryTheSelectedLanguage(): void
    {
        $parameters = [];
        $uriBuilder = $this->createMock(UriBuilder::class);
        $uriBuilder->method('buildUriFromRoute')->willReturnCallback(static function (string $route, array $routeParameters) use (&$parameters): Uri {
            $parameters[] = $routeParameters;

            return new Uri('/typo3/export');
        });
        $urls = new ExportUrlBuilderService($uriBuilder);

        $urls->buildOverviewCsvUrl('main', false, null, 1);
        $urls->buildOverviewPdfUrl('main', false, null, 1);
        $urls->buildLocalPageCsvUrl('main', 34, 'open', 'all', 1);
        $urls->buildLocalPagePdfUrl('main', 34, 'open', 'all', 1);

        self::assertSame([1, 1, 1, 1], array_column($parameters, 'language'));
        self::assertArrayNotHasKey('scope', $parameters[0], 'A local export stays a Content Scan export.');
    }
}
