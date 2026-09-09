<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class AccessControlServiceFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function databaseAdminCanManageAdminOnlySettings(): void
    {
        $this->insertBackendUser(42, true);

        self::assertTrue($this->get(AccessControlServiceInterface::class)
            ->canManageAdminOnlySettings($this->staleBackendUser(42)));
    }

    #[Test]
    public function databaseDowngradeOverridesStaleAdministratorSession(): void
    {
        $this->insertBackendUser(42, false);

        self::assertFalse($this->get(AccessControlServiceInterface::class)
            ->canManageAdminOnlySettings($this->staleBackendUser(42)));
    }

    private function insertBackendUser(int $uid, bool $admin): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_users')
            ->insert('be_users', [
                'uid' => $uid,
                'username' => 'access-control-test',
                'password' => '',
                'admin' => $admin ? 1 : 0,
                'disable' => 0,
                'deleted' => 0,
            ]);
    }

    private function staleBackendUser(int $uid): BackendUserAuthentication
    {
        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isAdmin'])
            ->getMock();
        $backendUser->method('isAdmin')->willReturn(true);
        $backendUser->user = ['uid' => $uid, 'admin' => 1];

        return $backendUser;
    }
}
