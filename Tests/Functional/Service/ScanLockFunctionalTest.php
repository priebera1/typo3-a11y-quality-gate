<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Service\ScanStatusService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * NEW-06 (resilience audit): "one content scan at a time" was a registry read followed by a write, so two editors
 * started scans together in 9 of 20 barrier trials. The lock is now an atomic row; a dead process cannot block forever.
 */
final class ScanLockFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function onlyOneCallerGetsTheLockUntilItIsReleased(): void
    {
        $first = $this->service()->acquireScanLock();
        self::assertNotNull($first);
        self::assertNull($this->service()->acquireScanLock(), 'A second scan must not start while the first holds the lock.');
        self::assertNull($this->service()->acquireScanLock());

        $this->service()->releaseScanLock($first);
        $next = $this->service()->acquireScanLock();
        self::assertNotNull($next);
        self::assertNotSame($first, $next);
    }

    #[Test]
    public function aForeignTokenCannotReleaseTheLock(): void
    {
        $token = $this->service()->acquireScanLock();
        self::assertNotNull($token);

        $this->service()->releaseScanLock('not-the-owner');

        self::assertNull($this->service()->acquireScanLock());
        self::assertTrue($this->service()->isScanLockHeld());
    }

    #[Test]
    public function aLockOfADeadProcessExpiresAndCanBeTakenOver(): void
    {
        self::assertNotNull($this->service()->acquireScanLock());
        $this->expireLock();

        self::assertFalse($this->service()->isScanLockHeld());
        self::assertNotNull($this->service()->acquireScanLock(), 'An expired lock must not block scans forever.');
    }

    #[Test]
    public function aRunningFlagLeftByADeadProcessIsNotReportedAsRunningOnceItsLockExpired(): void
    {
        $service = $this->service();
        $token = $service->acquireScanLock();
        self::assertNotNull($token);
        $service->markRunning('page', 'editor', 5);
        self::assertTrue($this->service()->isRunning());

        // The PHP process died: no markFinished(), no release.
        $this->expireLock();

        $status = $this->service()->getStatus();
        self::assertFalse($status['running']);
        self::assertTrue($status['interrupted'] ?? false);
        self::assertFalse($this->service()->isRunning());
    }

    /**
     * Review P2-1: a site scan can run longer than the TTL; it renews its lock on every page, so a second scan cannot
     * start and Cancel still finds it. Only the owner renews, and a lock already taken over is never renewed back.
     */
    #[Test]
    public function aRunningScanRenewsItsLockAndOnlyTheOwnerCan(): void
    {
        $token = $this->service()->acquireScanLock();
        self::assertNotNull($token);
        $this->setLock($token, time() + 60);

        self::assertFalse($this->service()->renewScanLock('not-the-owner'));
        self::assertTrue($this->service()->renewScanLock($token));
        self::assertGreaterThan(time() + ScanStatusService::LOCK_TTL - 10, $this->expiresAt(), 'renewed for a full TTL');

        $this->setLock($token, time() - 1);
        $taker = $this->service()->acquireScanLock();
        self::assertNotNull($taker, 'the dead owner\'s lock is taken over');
        self::assertFalse($this->service()->renewScanLock($token), 'the old owner cannot take it back');
        self::assertTrue($this->service()->renewScanLock($taker));
    }

    #[Test]
    public function theCliScanTakesTheSameLock(): void
    {
        $token = $this->service()->acquireScanLock();
        self::assertNotNull($token);

        $tester = new \Symfony\Component\Console\Tester\CommandTester($this->get(\Priebera\A11yQualityGate\Command\ScanCommand::class));
        $exit = $tester->execute(['--page-uid' => '1']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('already running', $tester->getDisplay());
        self::assertTrue($this->service()->renewScanLock($token), 'the refused CLI run did not release the holder\'s lock');
    }

    private function setLock(string $token, int $expiresAt): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_registry')->update(
            'sys_registry',
            ['entry_value' => serialize(['token' => $token, 'expiresAt' => $expiresAt])],
            ['entry_namespace' => 'a11y_quality_gate_lock', 'entry_key' => 'content_scan']
        );
    }

    private function expiresAt(): int
    {
        $raw = (string)GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_registry')
            ->select(['entry_value'], 'sys_registry', ['entry_namespace' => 'a11y_quality_gate_lock', 'entry_key' => 'content_scan'])->fetchOne();
        $lock = unserialize($raw, ['allowed_classes' => false]);

        return (int)($lock['expiresAt'] ?? 0);
    }

    private function service(): ScanStatusService
    {
        return new ScanStatusService(GeneralUtility::makeInstance(Registry::class), GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function expireLock(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_registry')->update(
            'sys_registry',
            ['entry_value' => serialize(['token' => 'dead-process', 'expiresAt' => time() - 1])],
            ['entry_namespace' => 'a11y_quality_gate_lock', 'entry_key' => 'content_scan']
        );
    }
}
