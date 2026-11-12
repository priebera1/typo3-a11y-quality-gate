<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\ReleaseQa;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Controller\ExportController;
use Priebera\A11yQualityGate\Controller\OverviewController;
use Priebera\A11yQualityGate\Export\RemoteExportBuilder;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Priebera\A11yQualityGate\Tests\Functional\Security\RemoteResultFixtureTrait;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Http\ServerRequest;

/**
 * F12 independent review R1/R2 (residual F11-02): while a completed scan's results are not stored, the Overview offered
 * its CSV/PDF export — whose PDF carried the crawler's priority fixes, score and occurrence total as the saved report —
 * and compared it with an earlier scan through the AQG service's crawler counts. Neither is a saved result.
 *
 * Fixture (RemoteResultFixtureTrait): site scan 1 (stored, job JOB_SITE). Scan 3 is a newer compatible site scan whose
 * results are not stored.
 */
final class UnsavedRemoteExportAndCompareTest extends AbstractFunctionalTestCase
{
    use RemoteResultFixtureTrait;

    private const JOB_UNSAVED = '99999999-9999-4999-8999-999999999999';

    protected function setUp(): void
    {
        parent::setUp();
        $this->createRemoteResultFixture();
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => 3, 'site_identifier' => 'client-a', 'job_id' => self::JOB_UNSAVED, 'source_type' => 'crawl',
            'scan_scope' => 'site', 'page_uid' => 0, 'language_uid' => 0, 'start_url' => 'https://a.test/', 'status' => 'completed',
            'finished_at' => 5000, 'persisted_at' => 0, 'sync_error' => '', 'crdate' => 5000, 'tstamp' => time(),
            'pages_scanned' => 7, 'pages_total' => 7, 'issues_total' => 40, 'issues_new' => 12, 'issues_resolved' => 3,
        ]);
    }

    #[Test]
    public function aNamedUnsavedScanIsNoExportTarget(): void
    {
        foreach (['' => 'pending', 'Results could not be stored.' => 'failed'] as $syncError => $state) {
            $this->setSyncError($syncError);
            $target = $this->get(RemoteExportBuilder::class)->resolveOverviewExportTarget('client-a', 3, null, 0);

            self::assertNull($target['scan'], $state);
            self::assertTrue($target['explicitInvalid'], $state . ': no other scan is put in its place');
            self::assertTrue($target['resultsUnsaved'], $state);
        }
    }

    #[Test]
    public function theLatestScanBeingUnsavedRefusesTheExportInsteadOfFallingBack(): void
    {
        $target = $this->get(RemoteExportBuilder::class)->resolveOverviewExportTarget('client-a', 0, null, 0);

        self::assertNull($target['scan'], 'neither the unsaved scan nor the older stored one');
        self::assertTrue($target['resultsUnsaved']);
    }

    #[Test]
    public function theExportControllerRefusesAnUnsavedTargetForAdministrators(): void
    {
        $authorized = fn (int $scanUid): ?array => $this->as(self::ADMIN, fn (): ?array => (new \ReflectionMethod(ExportController::class, 'authorizedOverviewTarget'))
            ->invoke($this->get(ExportController::class), 'client-a', $scanUid, null, 0));

        self::assertNull($authorized(3));
        self::assertNull($authorized(0));
    }

    #[Test]
    public function afterStorageTheScanIsTheExportTargetAgain(): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['persisted_at' => 5001], ['uid' => 3]);

        $named = $this->get(RemoteExportBuilder::class)->resolveOverviewExportTarget('client-a', 3, null, 0);
        $latest = $this->get(RemoteExportBuilder::class)->resolveOverviewExportTarget('client-a', 0, null, 0);

        self::assertSame(3, (int)($named['scan']['uid'] ?? 0));
        self::assertFalse($named['resultsUnsaved']);
        self::assertSame(3, (int)($latest['scan']['uid'] ?? 0));
        self::assertSame(3, (int)($this->as(self::ADMIN, fn (): ?array => (new \ReflectionMethod(ExportController::class, 'authorizedOverviewTarget'))
            ->invoke($this->get(ExportController::class), 'client-a', 3, null, 0))['scan']['uid'] ?? 0));
    }

    #[Test]
    public function anUnsavedScanIsNotComparedThroughTheCrawlersCounts(): void
    {
        $comparison = $this->compare(self::JOB_SITE, self::JOB_UNSAVED);

        self::assertFalse($comparison['available']);
        self::assertSame('The comparison is available once the results of both scans are saved.', $comparison['message']);
        $this->setSyncError('failed');
        self::assertSame('The comparison is available once the results of both scans are saved.', $this->compare(self::JOB_UNSAVED, self::JOB_SITE)['message']);
    }

    private function setSyncError(string $syncError): void
    {
        $this->get(ConnectionPool::class)->getConnectionForTable('tx_a11y_remote_scan')->update('tx_a11y_remote_scan', ['sync_error' => $syncError], ['uid' => 3]);
    }

    /**
     * @return array<string, mixed>
     */
    private function compare(string $fromJobId, string $toJobId): array
    {
        return $this->as(self::ADMIN, function () use ($fromJobId, $toJobId): array {
            $request = (new ServerRequest('https://a.test/typo3/module/web/a11y'))
                ->withQueryParams(['compareFromJobId' => $fromJobId, 'compareToJobId' => $toJobId])
                ->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);

            return (new \ReflectionMethod(OverviewController::class, 'buildRemoteScanCompare'))
                ->invoke($this->get(OverviewController::class), $request, 'https://a.test/', 'client-a');
        });
    }

    private function as(int $beUserUid, \Closure $callback): mixed
    {
        $previous = $GLOBALS['BE_USER'] ?? null;
        $this->setUpBackendUser($beUserUid);
        try {
            return $callback();
        } finally {
            $GLOBALS['BE_USER'] = $previous;
        }
    }
}
