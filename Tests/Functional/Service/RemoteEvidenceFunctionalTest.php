<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\FixVerificationRepository;
use Priebera\A11yQualityGate\Domain\Repository\RemoteScanRepository;
use Priebera\A11yQualityGate\Export\AcceptanceEvidenceBuilder;
use Priebera\A11yQualityGate\Service\FixVerificationService;
use Priebera\A11yQualityGate\Service\ScanComparisonService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Configuration\SiteWriter;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stored scans → comparison, acceptance evidence and fix verification, on the real schema.
 */
final class RemoteEvidenceFunctionalTest extends AbstractFunctionalTestCase
{
    private const BASELINE_JOB = '11111111-1111-4111-8111-111111111111';
    private const CURRENT_JOB = '22222222-2222-4222-8222-222222222222';
    private const PARTIAL_JOB = '33333333-3333-4333-8333-333333333333';

    protected function setUp(): void
    {
        parent::setUp();
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('pages')->insert('pages', [
            'uid' => 1, 'pid' => 0, 'title' => 'Root', 'doktype' => 1, 'is_siteroot' => 1, 'perms_everybody' => 31,
        ]);
        $pool->getConnectionForTable('be_users')->insert('be_users', ['uid' => 1, 'username' => 'admin', 'admin' => 1]);
        $this->get(SiteWriter::class)->createNewBasicSite('main', 1, 'https://example.org/');
        $this->get(SiteFinder::class)->getAllSites(false);

        // Baseline: /a has image-alt (2) and color-contrast (1); /b has label (1); /c failed.
        $this->scan(1, self::BASELINE_JOB, 1000, [
            ['https://example.org/a', 0, 200, ['image-alt' => 2, 'color-contrast' => 1]],
            ['https://example.org/b', 0, 200, ['label' => 1]],
            ['https://example.org/c', 1, 500, []],
        ]);
        // Current: /a image-alt gone, color-contrast worse (3); /b label still there, heading new; /c ok now.
        $this->scan(2, self::CURRENT_JOB, 2000, [
            ['https://example.org/a', 0, 200, ['color-contrast' => 3]],
            ['https://example.org/b', 0, 200, ['label' => 1, 'heading-order' => 1]],
            ['https://example.org/c', 0, 200, []],
        ]);
        // A verification scan whose findings were stored only in part: the crawler reported two issue types for
        // /a, only color-contrast was stored.
        $this->scan(3, self::PARTIAL_JOB, 3000, [
            ['https://example.org/a', 0, 200, ['color-contrast' => 1], 2],
        ]);
    }

    #[Test]
    public function comparisonAndAcceptanceEvidenceComeFromTheStoredScans(): void
    {
        $this->setUpBackendUser(1);
        $scans = $this->get(RemoteScanRepository::class);
        $baseline = $scans->findScanByJobId(self::BASELINE_JOB);
        $current = $scans->findScanByJobId(self::CURRENT_JOB);

        $comparison = $this->get(ScanComparisonService::class)->compare($baseline, $current);
        self::assertSame([['https://example.org/a', 'image-alt']], array_map(static fn (array $e): array => [$e['url'], $e['ruleId']], $comparison['fixed']));
        self::assertSame(['heading-order'], array_column($comparison['new'], 'ruleId'));
        self::assertSame(['color-contrast'], array_column($comparison['regressed'], 'ruleId'));
        self::assertSame(['label'], array_column($comparison['unresolved'], 'ruleId'));
        self::assertSame([['url' => 'https://example.org/c', 'reason' => 'page_failed']], $comparison['unverified']);

        $builder = $this->get(AcceptanceEvidenceBuilder::class);
        $evidence = $builder->build($this->get(SiteFinder::class)->getSiteByIdentifier('main'), $baseline, $current);
        $pdf = $builder->renderPdf($evidence);
        self::assertStringStartsWith('%PDF', $pdf);
        self::assertStringContainsString("fixed,https://example.org/a,image-alt,critical,2,0", $builder->renderCsv($evidence));
    }

    #[Test]
    public function fixVerificationReadsTheStoredVerificationScan(): void
    {
        $this->setUpBackendUser(1);
        $verifications = $this->get(FixVerificationRepository::class);
        $service = $this->get(FixVerificationService::class);

        $resolved = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'image-alt', 'url' => 'https://example.org/a', 'baseline_scan' => 1,
            'verification_job_id' => self::CURRENT_JOB, 'outcome' => 'pending',
        ]);
        $still = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'label', 'url' => 'https://example.org/b', 'baseline_scan' => 1,
            'verification_job_id' => self::CURRENT_JOB, 'outcome' => 'pending',
        ]);
        $missing = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'label', 'url' => 'https://example.org/gone', 'baseline_scan' => 1,
            'verification_job_id' => self::CURRENT_JOB, 'outcome' => 'pending',
        ]);

        self::assertSame('resolved', $service->evaluate($verifications->findByUid($resolved))['outcome']);
        self::assertSame('still_present', $service->evaluate($verifications->findByUid($still))['outcome']);
        $notVerified = $service->evaluate($verifications->findByUid($missing));
        self::assertSame('not_verified', $notVerified['outcome']);
        self::assertSame('page_missing', $notVerified['outcome_reason']);
        self::assertSame(2, (int)$verifications->findByUid($resolved)['verification_scan'], 'The deciding scan is recorded.');

        $partial = $verifications->insert([
            'site_identifier' => 'main', 'rule_id' => 'image-alt', 'url' => 'https://example.org/a', 'baseline_scan' => 1,
            'verification_job_id' => self::PARTIAL_JOB, 'outcome' => 'pending',
        ]);
        $inconclusive = $service->evaluate($verifications->findByUid($partial));
        self::assertSame('not_verified', $inconclusive['outcome'], 'A finding missing from partially stored results is not resolved.');
        self::assertSame('evidence_incomplete', $inconclusive['outcome_reason']);
    }

    /**
     * @param list<array{0:string,1:int,2:int,3:array<string,int>,4?:int}> $pages the optional fifth value is the
     *        crawler's issue type count when it differs from the stored rules
     */
    private function scan(int $uid, string $jobId, int $finishedAt, array $pages): void
    {
        $pool = GeneralUtility::makeInstance(ConnectionPool::class);
        $pool->getConnectionForTable('tx_a11y_remote_scan')->insert('tx_a11y_remote_scan', [
            'uid' => $uid, 'site_identifier' => 'main', 'job_id' => $jobId, 'source_type' => 'sitemap', 'scan_scope' => 'site',
            'language_uid' => 0, 'start_url' => 'https://example.org/', 'status' => 'completed', 'finished_at' => $finishedAt,
            'persisted_at' => $finishedAt, 'pages_scanned' => count($pages),
        ]);
        foreach ($pages as $page) {
            [$url, $failed, $httpStatus, $rules] = $page;
            $pool->getConnectionForTable('tx_a11y_remote_scan_page')->insert('tx_a11y_remote_scan_page', [
                'remote_scan' => $uid, 'url' => $url, 'is_failed' => $failed, 'http_status' => $httpStatus, 'issues_count' => $page[4] ?? count($rules),
            ]);
            $pageUid = (int)$pool->getConnectionForTable('tx_a11y_remote_scan_page')->lastInsertId();
            foreach ($rules as $ruleId => $nodes) {
                $pool->getConnectionForTable('tx_a11y_remote_issue')->insert('tx_a11y_remote_issue', [
                    'remote_scan' => $uid, 'remote_scan_page' => $pageUid, 'rule_id' => $ruleId,
                    'impact' => $ruleId === 'image-alt' ? 'critical' : 'serious', 'nodes_count' => $nodes, 'status' => 'open',
                ]);
            }
        }
    }
}
