<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FreeRemotePreviewUiTest extends TestCase
{
    #[Test]
    public function remoteTabAndFreePreviewAreRenderedWithoutLicenceGating(): void
    {
        $overview = file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/Overview/Index.html');
        $tabs = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Overview/SourceTabs.html');
        $free = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Overview/FreeRemotePreview.html');

        self::assertIsString($overview);
        self::assertIsString($tabs);
        self::assertIsString($free);
        self::assertStringContainsString('condition="{remoteUiAvailable}"', $overview);
        self::assertStringContainsString('data-a11y-overview-source-trigger="remote"', $tabs);
        self::assertStringContainsString('Free Remote Preview', $free);
        self::assertStringNotContainsString('data-max-pages=', $free);
        self::assertStringContainsString('data-current-page-uid="{currentPageUid}"', $free);
        self::assertStringContainsString('data-free-submit-intent=', $free);
        self::assertStringContainsString("{freePreview.state} == 'FREE_AVAILABLE'", $free);
        self::assertStringContainsString('freePreview.submitCapable', $free);
        self::assertStringContainsString('freePreview.hasTodayResult', $free);
        self::assertStringNotContainsString('!{freePreview.available} &amp;&amp; {remoteScan}', $free);
        self::assertStringContainsString('a11y-free-preview-retry', $free);
    }

    #[Test]
    public function freePreviewReusesOverviewComponentsAndFormatsResetForHumans(): void
    {
        $free = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Overview/FreeRemotePreview.html');

        self::assertIsString($free);
        self::assertStringContainsString('aqg-section aqg-section--scan', $free);
        self::assertStringContainsString('aqg-section__actions', $free);
        self::assertStringContainsString('aqg-section__meta-row', $free);
        self::assertStringContainsString('aqg-meta-item', $free);
        self::assertStringContainsString('aqg-notice aqg-tone-warning', $free);
        self::assertStringContainsString('aqg-pro-card', $free);
        self::assertStringNotContainsString('aqg-limit-pill', $free);
        self::assertStringNotContainsString('aqg-limits', $free);
        self::assertStringNotContainsString('>FREE</span>', $free);
        self::assertStringContainsString('datetime="{freePreview.resetsAt}"', $free);
        self::assertStringContainsString("f:format.date(format: 'd.m.Y H:i')", $free);
        self::assertStringNotContainsString('>{freePreview.resetsAt}</time>', $free);
    }

    #[Test]
    public function freeLicenceCopyIncludesRemotePreviewAndKeepsAdvancedFeaturesInPro(): void
    {
        $licence = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Settings/LicenceStatus.html');
        $english = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/locallang.xlf');
        $german = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/de.locallang.xlf');

        self::assertIsString($licence);
        self::assertIsString($english);
        self::assertIsString($german);
        self::assertStringContainsString('settings.licence.free.remotePreview', $licence);
        self::assertStringContainsString('settings.licence.free.advancedRemote', $licence);
        self::assertStringContainsString('up to 5 remote single-page scans per day', $english);
        self::assertStringContainsString('bis zu 5 Remote-Scans einzelner Seiten pro Tag', $german);
        self::assertStringNotContainsString(
            'Frontend crawling, block-on-publish gate and exports require PRO.',
            $english,
        );
    }

    #[Test]
    public function freeResultsDoNotRenderPaidActions(): void
    {
        $remotePanel = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Overview/RemotePanel.html');
        $remoteDetail = file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/RemotePageDetail/Show.html');

        self::assertIsString($remotePanel);
        self::assertIsString($remoteDetail);
        self::assertStringContainsString('!{freePreview.isFree}', $remotePanel);
        self::assertStringContainsString('!{isFreePreview}', $remoteDetail);
        self::assertStringContainsString('Remote/ScanHistoryTable', $remotePanel);
        self::assertStringContainsString('Remote/ScanComparison', $remotePanel);
        self::assertStringContainsString('remoteRemediationSummary} &amp;&amp; !{freePreview.isFree}', $remotePanel);
    }

    #[Test]
    public function paidEntitlementStatesRemainExplicit(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../../Classes/Controller/OverviewController.php');

        self::assertIsString($controller);
        self::assertStringContainsString("return 'trial';", $controller);
        self::assertStringContainsString("? 'agency'", $controller);
        self::assertStringContainsString(": 'pro';", $controller);
    }

    #[Test]
    public function overviewRemoteScanResolutionIsEntitlementAware(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../../Classes/Controller/OverviewController.php');
        $repository = file_get_contents(__DIR__ . '/../../../Classes/Domain/Repository/RemoteScanRepository.php');

        self::assertIsString($controller);
        self::assertIsString($repository);

        // isFreePreview must be resolved before $remoteScan, and threaded into every
        // "last completed scan" lookup that feeds the shared Overview reporting UI,
        // so a completed free-preview scan can never silently replace PRO results.
        $isFreePreviewPos = strpos($controller, '$isFreePreview = !(bool)($proStatus->valid ?? false)');
        $remoteScanPos = strpos($controller, 'resolveOverviewRemoteScan($siteIdentifier, $remoteIsPageContext');
        self::assertIsInt($isFreePreviewPos);
        self::assertIsInt($remoteScanPos);
        self::assertLessThan($remoteScanPos, $isFreePreviewPos);

        $normalizedController = preg_replace('/\s+/', ' ', $controller);
        self::assertIsString($normalizedController);

        self::assertStringContainsString('$selectedRemoteScanUid, $isFreePreview)', $normalizedController);
        self::assertStringContainsString('$currentPageUrl, $isFreePreview', $normalizedController);
        self::assertStringContainsString('findLastCompletedPageScanBySite($siteIdentifier, $currentLanguageUid, $isFreePreview)', $normalizedController);
        self::assertStringContainsString('findLastCompletedSiteScanBySite($siteIdentifier, $languageUid, $isFreePreview)', $normalizedController);
        self::assertStringContainsString('$selectedScanIsFreePreview === $isFreePreview', $normalizedController);

        self::assertStringContainsString('function addFreePreviewConstraint(', $repository);
        self::assertStringContainsString('is_free_preview', $repository);
    }

    #[Test]
    public function recordMappingShowsProUpsellInsteadOfErrorForFreePreview(): void
    {
        $remoteDetail = file_get_contents(__DIR__ . '/../../../Resources/Private/Templates/RemotePageDetail/Show.html');
        $english = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/locallang.xlf');
        $german = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/de.locallang.xlf');

        self::assertIsString($remoteDetail);
        self::assertIsString($english);
        self::assertIsString($german);

        self::assertStringContainsString('freePreview.recordMappingLocked', $remoteDetail);
        self::assertStringContainsString('condition="{isFreePreview}"', $remoteDetail);
        self::assertStringContainsString('trans-unit id="freePreview.recordMappingLocked"', $english);
        self::assertStringContainsString('trans-unit id="freePreview.recordMappingLocked"', $german);

        // The plain "no mapped record" error copy must still exist for the non-free-preview branch.
        self::assertStringContainsString('module.remotePageDetail.noMappedRecord', $remoteDetail);
    }

    #[Test]
    public function freePreviewDescriptionMatchesActualCrawlBehaviour(): void
    {
        $english = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/locallang.xlf');
        $german = file_get_contents(__DIR__ . '/../../../Resources/Private/Language/de.locallang.xlf');
        $resolver = file_get_contents(__DIR__ . '/../../../Classes/Pro/Service/RemoteScanInputResolver.php');

        self::assertIsString($english);
        self::assertIsString($german);
        self::assertIsString($resolver);

        self::assertStringContainsString('return $this->resolveForSinglePage($site, $pageUrl);', $resolver);
        self::assertStringContainsString('sourceType: RemoteScanSourceType::SinglePage', $resolver);
        self::assertStringContainsString('maxPages: 1', $resolver);
        self::assertStringContainsString('followLinks: false', $resolver);

        self::assertStringContainsString(
            "Scan the currently selected page with AQG's remote frontend checks.",
            $english,
        );
        self::assertStringNotContainsString("starting from your site's homepage", $english);
        self::assertStringContainsString('aktuell ausgewählte Seite', $german);
    }

    #[Test]
    public function freeSubmitResolvesAndPersistsTheSelectedPageServerSide(): void
    {
        $controller = file_get_contents(__DIR__ . '/../../../Classes/Controller/ProCrawlerAjaxController.php');
        $intent = file_get_contents(__DIR__ . '/../../../Classes/FreePreview/FreeSubmitIntentService.php');

        self::assertIsString($controller);
        self::assertIsString($intent);
        self::assertStringContainsString('resolveSiteByPageId($requestedPageUid)', $controller);
        self::assertStringContainsString('resolvePublicForPage($site, $requestedPageUid, 0)', $controller);
        self::assertStringContainsString('resolveForFreePreview($site, $freePageUrl)', $controller);
        self::assertStringContainsString("scanScope: \$isFreePreview ? 'page' : 'site'", $controller);
        self::assertStringContainsString('pageUid: $isFreePreview ? $requestedPageUid : 0', $controller);
        self::assertStringContainsString("'page' => \$pageUid", $intent);
        self::assertStringContainsString("(int)(\$payload['page'] ?? 0) !== \$pageUid", $intent);
        self::assertStringContainsString("(int)(\$existingSubmittedScan['persisted_at'] ?? 0) <= 0", $controller);
    }

    #[Test]
    public function proBadgeUsesTheExistingCenteredLayoutContract(): void
    {
        $overviewScss = file_get_contents(__DIR__ . '/../../../Resources/Private/Scss/views/_overview.scss');
        $free = file_get_contents(__DIR__ . '/../../../Resources/Private/Partials/Overview/FreeRemotePreview.html');

        self::assertIsString($overviewScss);
        self::assertIsString($free);
        self::assertStringContainsString('aqg-pro-card__badge', $free);
        self::assertMatchesRegularExpression(
            '/\.a11y-overview \.aqg-pro-card__badge \{[^}]*display: inline-flex;[^}]*align-items: center;[^}]*justify-content: center;/s',
            $overviewScss,
        );
    }
}
