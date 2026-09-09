<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Controller;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Controller\ExportController;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Remote export authorization must follow the record being exported.
 *
 * Regression guard: the PRO capability check used to be resolved from the caller-supplied
 * `siteIdentifier` while the exported rows were selected by `remoteScanUid` / `remotePageUid`, so a
 * request could name a licensed site while exporting another site's scan.
 */
final class ExportSiteBindingTest extends TestCase
{
    #[Test]
    public function ownerIsTakenFromTheScanRecordNotFromTheRequest(): void
    {
        $owner = $this->resolveOwner(
            requestedSiteIdentifier: '',
            scan: ['uid' => 10, 'site_identifier' => 'free-site'],
            remoteScanUid: 10,
        );

        self::assertSame('free-site', $owner);
    }

    #[Test]
    public function requestNamingADifferentSiteThanTheScanIsRejected(): void
    {
        $owner = $this->resolveOwner(
            requestedSiteIdentifier: 'licensed-site',
            scan: ['uid' => 10, 'site_identifier' => 'free-site'],
            remoteScanUid: 10,
        );

        self::assertSame(
            '',
            $owner,
            'A caller must not borrow another site\'s entitlement by naming it in the request.'
        );
    }

    #[Test]
    public function matchingRequestedIdentifierIsAccepted(): void
    {
        $owner = $this->resolveOwner(
            requestedSiteIdentifier: 'free-site',
            scan: ['uid' => 10, 'site_identifier' => 'free-site'],
            remoteScanUid: 10,
        );

        self::assertSame('free-site', $owner);
    }

    #[Test]
    public function remotePageRequestResolvesTheOwnerThroughItsScan(): void
    {
        $owner = $this->resolveOwner(
            requestedSiteIdentifier: 'licensed-site',
            scan: ['uid' => 10, 'site_identifier' => 'free-site'],
            remotePageUid: 77,
            remotePage: ['uid' => 77, 'remote_scan' => 10],
        );

        self::assertSame('', $owner);
    }

    #[Test]
    public function unknownScanYieldsNoOwnerSoTheExportIsDenied(): void
    {
        self::assertSame('', $this->resolveOwner('licensed-site', null, remoteScanUid: 999));
        self::assertSame('', $this->resolveOwner('licensed-site', null, remotePageUid: 999));
    }

    #[Test]
    public function scanWithoutAnOwningSiteYieldsNoOwner(): void
    {
        $owner = $this->resolveOwner(
            requestedSiteIdentifier: 'licensed-site',
            scan: ['uid' => 10, 'site_identifier' => '  '],
            remoteScanUid: 10,
        );

        self::assertSame('', $owner);
    }

    #[Test]
    public function siteWideExportWithoutAScanUidStillUsesTheRequestedSite(): void
    {
        // The overview export has no scan uid; canAccessRemoteExport() enforces page permissions
        // for that path, so the requested identifier remains the only available context.
        self::assertSame('aqg', $this->resolveOwner('aqg', null));
    }

    #[Test]
    public function bothRemoteExportActionsResolveTheOwnerBeforeCheckingEntitlement(): void
    {
        $source = (string)file_get_contents(
            __DIR__ . '/../../../Classes/Controller/ExportController.php'
        );

        self::assertSame(
            2,
            substr_count($source, '$ownerSiteIdentifier = $this->resolveRemoteExportOwnerSiteIdentifier('),
            'Both csvAction() and pdfAction() must resolve the owning site.'
        );
        self::assertStringContainsString('$this->hasPaidRemoteAccess($ownerSiteIdentifier)', $source);
        self::assertStringContainsString('$this->canExportPdf($site, $ownerSiteIdentifier)', $source);
        self::assertStringNotContainsString('$this->hasPaidRemoteAccess($context[\'siteIdentifier\'])', $source);

        // The remote PDF branch must resolve its Site from the owning identifier. The local export
        // path legitimately keeps using the request context, so scope the check to the remote block.
        $remotePdfBranch = $this->remotePdfBranch($source);
        self::assertStringContainsString(
            '$this->siteResolutionService->resolveSiteByIdentifier($ownerSiteIdentifier)',
            $remotePdfBranch
        );
        self::assertStringNotContainsString(
            'resolveSiteForExport(',
            $remotePdfBranch,
            'The remote PDF branch must not derive its Site from the request context.'
        );
    }

    private function remotePdfBranch(string $source): string
    {
        $start = strpos($source, 'public function pdfAction');
        self::assertIsInt($start, 'pdfAction() not found.');

        $end = strpos($source, 'canAccessLocalExport', $start);
        self::assertIsInt($end, 'End of the remote pdfAction branch not found.');

        return substr($source, $start, $end - $start);
    }

    /**
     * @param array<string, mixed>|null $scan
     * @param array<string, mixed>|null $remotePage
     */
    private function resolveOwner(
        string $requestedSiteIdentifier,
        ?array $scan,
        int $remotePageUid = 0,
        int $remoteScanUid = 0,
        ?array $remotePage = null,
    ): string {
        $repository = $this->createMock(RemoteScanRepository::class);
        $repository->method('findScanByUid')->willReturn($scan);
        $repository->method('findPageByUid')->willReturn($remotePage);

        $subject = (new ReflectionClass(ExportController::class))->newInstanceWithoutConstructor();
        self::assertInstanceOf(ExportController::class, $subject);

        (new ReflectionProperty(ExportController::class, 'remoteScanRepository'))
            ->setValue($subject, $repository);

        $method = new ReflectionMethod(ExportController::class, 'resolveRemoteExportOwnerSiteIdentifier');

        return (string)$method->invoke($subject, $requestedSiteIdentifier, $remotePageUid, $remoteScanUid);
    }
}
