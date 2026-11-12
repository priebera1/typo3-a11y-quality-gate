<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\PageDetailController;
use Priebera\A11yQualityGate\Pro\Service\RemoteScanAccessSettingsService;
use Priebera\A11yQualityGate\Service\RemotePageScanTargetResolver;

/**
 * Atlas 09·A (audit F-06): the preview-scan card on a page visitors cannot open offers "Scan unpublished page" only
 * when that scan can work, and otherwise says why. A public page gets no card.
 */
final class PageDetailPreviewScanAccessTest extends TestCase
{
    /**
     * @return iterable<string, array{string, bool, bool, string, bool, ?string}>
     */
    public static function states(): iterable
    {
        // restriction, licence with crawler, scanner token, unsupported restriction, scan permission → state
        yield 'public page: no card' => ['', true, true, '', true, null];
        yield 'free or no crawler' => ['hidden', false, true, '', true, 'free'];
        yield 'start/stop or fe_group' => ['fe_group', true, true, 'fe_group', true, 'unsupported'];
        yield 'no scanner token' => ['hidden', true, false, '', true, 'noToken'];
        yield 'no scan permission' => ['hidden', true, true, '', false, 'noPermission'];
        yield 'available' => ['hidden', true, true, '', true, 'available'];
    }

    #[Test]
    #[DataProvider('states')]
    public function theCardOffersTheScanOnlyWhenItCanWork(
        string $restriction,
        bool $hasCrawler,
        bool $hasToken,
        string $unsupported,
        bool $canScanNow,
        ?string $expectedState,
    ): void {
        $resolver = $this->createMock(RemotePageScanTargetResolver::class);
        $resolver->method('visitorRestriction')->willReturn($restriction);
        $resolver->method('unsupportedPreviewRestriction')->willReturn($unsupported);
        $settings = $this->createMock(RemoteScanAccessSettingsService::class);
        $settings->method('hasScannerTokenForSite')->willReturn($hasToken);

        $controller = (new \ReflectionClass(PageDetailController::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(PageDetailController::class, 'remotePageScanTargetResolver'))->setValue($controller, $resolver);
        (new \ReflectionProperty(PageDetailController::class, 'remoteScanAccessSettingsService'))->setValue($controller, $settings);

        $proStatus = (object)['valid' => $hasCrawler, 'hasCrawler' => $hasCrawler];
        // canShowSettings = false: the settings link needs the route builder, which this test does not wire.
        $result = (new \ReflectionMethod(PageDetailController::class, 'buildPreviewScanAccess'))
            ->invoke($controller, $proStatus, 'main', 4, 0, $canScanNow, false, []);

        if ($expectedState === null) {
            self::assertNull($result);
            return;
        }
        self::assertSame($expectedState, $result['state']);
        self::assertSame($restriction, $result['restriction']);
        self::assertSame('', $result['settingsUrl']);
    }
}
