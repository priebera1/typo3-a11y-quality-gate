<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Domain\Repository;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\MonitoringRunRepository;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;

/**
 * Only a monitoring run with complete coverage becomes the next baseline, on the real schema.
 */
final class MonitoringRunRepositoryTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function theBaselineIsTheLatestRunWithCompleteCoverage(): void
    {
        $runs = $this->get(MonitoringRunRepository::class);

        $complete = $runs->insertSubmitted('main', 0, 'job-complete');
        $runs->update($complete, ['status' => 'evaluated', 'outcome' => 'clear', 'coverage_complete' => 1]);
        $incomplete = $runs->insertSubmitted('main', 0, 'job-incomplete');
        $fingerprint = 'incomplete:' . hash('sha256', 'not_in_current|https://example.org/b');
        $runs->update($incomplete, ['status' => 'evaluated', 'outcome' => 'incomplete', 'coverage_complete' => 0, 'state_fingerprint' => $fingerprint]);
        $german = $runs->insertSubmitted('main', 1, 'job-german');
        $runs->update($german, ['status' => 'evaluated', 'outcome' => 'clear', 'coverage_complete' => 1]);
        $next = $runs->insertSubmitted('main', 0, 'job-next');

        self::assertSame('job-complete', $runs->findLatestTrustedRun('main', 0, $next)['job_id'] ?? null);
        self::assertSame($fingerprint, $runs->findPreviousEvaluated('main', 0, $next)['state_fingerprint'] ?? null, 'the full fingerprint is stored');
        self::assertTrue($runs->isUntrustedRunJob('job-incomplete'));
        self::assertFalse($runs->isUntrustedRunJob('job-complete'));
        self::assertFalse($runs->isUntrustedRunJob('a-user-scan'));
        self::assertNull($runs->findLatestTrustedRun('main', 0, $complete));
    }
}
