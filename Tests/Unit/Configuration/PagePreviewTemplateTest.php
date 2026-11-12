<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Atlas D9: wherever a preview scan of a page that was not public is shown as the page's result, it says so — the
 * frontend page detail (header and every finding card), the Overview status of the page and the page PDF export.
 */
final class PagePreviewTemplateTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../Resources/Private/';

    #[Test]
    public function thePageDetailLabelsTheHeaderAndEveryFindingCard(): void
    {
        $show = $this->read('Templates/RemotePageDetail/Show.html');

        self::assertStringContainsString('data-aqg-page-preview="header"', $show);
        self::assertStringContainsString('module.remotePageDetail.pagePreview.badgeWasNotPublic', $show);
        $rule = substr($show, (int)strpos($show, '<f:section name="Rule">'));
        self::assertStringContainsString('data-aqg-page-preview="card"', substr($rule, 0, (int)strpos($rule, '</f:section>')));
        // Separate histories with the public empty state.
        self::assertStringContainsString('remote.history.publicEmpty.title', $show);
        self::assertStringContainsString('role="tablist" data-aqg-history-tabs="true"', $show);
    }

    #[Test]
    public function theOverviewStatusOfAPreviewScanCarriesTheLabel(): void
    {
        $head = $this->read('Partials/Overview/RemoteStatusHead.html');

        self::assertStringContainsString('<f:if condition="{remoteScan.is_page_preview} == 1">', $head);
        self::assertStringContainsString('module.remotePageDetail.pagePreview.badge', $head);
    }

    #[Test]
    public function thePagePdfNamesAPreviewScan(): void
    {
        self::assertStringContainsString(
            '<f:if condition="{scanKindLabel}"> · <b>{scanKindLabel}</b></f:if>',
            $this->read('Templates/Export/RemotePagePdf.html')
        );
    }

    private function read(string $path): string
    {
        return (string)file_get_contents(self::ROOT . $path);
    }
}
