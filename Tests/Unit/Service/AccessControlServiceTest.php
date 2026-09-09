<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\AccessControlService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class AccessControlServiceTest extends TestCase
{
    private bool $hadBackendUser = false;
    private mixed $originalBackendUser = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->hadBackendUser = array_key_exists('BE_USER', $GLOBALS);
        $this->originalBackendUser = $GLOBALS['BE_USER'] ?? null;
        unset($GLOBALS['BE_USER']);
    }

    protected function tearDown(): void
    {
        if ($this->hadBackendUser) {
            $GLOBALS['BE_USER'] = $this->originalBackendUser;
        } else {
            unset($GLOBALS['BE_USER']);
        }
        parent::tearDown();
    }

    #[Test]
    public function missingBackendUserCannotRemediateImages(): void
    {
        self::assertFalse($this->accessControlServiceWithAdminFlag(0)->canRemediateImages());
    }

    #[Test]
    public function administratorCanManageAdminOnlySettingsWhenDatabaseStillMarksUserAsAdmin(): void
    {
        self::assertTrue($this->accessControlServiceWithAdminFlag(1)->canManageAdminOnlySettings(
            $this->backendUser(true, [], 42),
        ));
    }

    #[Test]
    public function staleAdministratorSessionCannotManageAdminOnlySettingsAfterDatabaseDowngrade(): void
    {
        self::assertFalse($this->accessControlServiceWithAdminFlag(0)->canManageAdminOnlySettings(
            $this->backendUser(true, [], 42),
        ));
    }

    #[Test]
    public function nonAdministratorCannotManageAdminOnlySettings(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->expects(self::never())->method('getConnectionForTable');

        self::assertFalse((new AccessControlService($connectionPool))->canManageAdminOnlySettings(
            $this->backendUser(false, [], 42),
        ));
    }

    #[Test]
    public function databaseFailureDeniesAdminOnlySettings(): void
    {
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->willThrowException(new \RuntimeException('Unavailable'));

        self::assertFalse((new AccessControlService($connectionPool))->canManageAdminOnlySettings(
            $this->backendUser(true, [], 42),
        ));
    }

    #[Test]
    public function administratorCanRemediateImagesWithoutTsConfigFlag(): void
    {
        self::assertTrue($this->accessControlServiceWithAdminFlag(0)
            ->canRemediateImages($this->backendUser(true, [])));
    }

    #[Test]
    #[DataProvider('enabledValueProvider')]
    public function nonAdminCanRemediateImagesOnlyForExplicitEnabledValue(mixed $value): void
    {
        self::assertTrue($this->accessControlServiceWithAdminFlag(0)->canRemediateImages(
            $this->backendUser(false, $this->tsConfig($value)),
        ));
    }

    #[Test]
    #[DataProvider('disabledValueProvider')]
    public function missingDisabledOrInvalidValueDeniesImageRemediation(mixed $value, bool $includeValue): void
    {
        $tsConfig = $includeValue ? $this->tsConfig($value) : [];

        self::assertFalse($this->accessControlServiceWithAdminFlag(0)->canRemediateImages(
            $this->backendUser(false, $tsConfig),
        ));
    }

    public static function enabledValueProvider(): iterable
    {
        yield 'integer one' => [1];
        yield 'string one' => ['1'];
    }

    public static function disabledValueProvider(): iterable
    {
        yield 'missing' => [null, false];
        yield 'null' => [null, true];
        yield 'empty string' => ['', true];
        yield 'zero integer' => [0, true];
        yield 'zero string' => ['0', true];
        yield 'boolean true' => [true, true];
        yield 'boolean false' => [false, true];
        yield 'float one' => [1.0, true];
        yield 'array value' => [['1'], true];
        yield 'two integer' => [2, true];
        yield 'two string' => ['2', true];
        yield 'leading zero' => ['01', true];
        yield 'padded one' => [' 1 ', true];
        yield 'word true' => ['true', true];
    }

    private function backendUser(bool $admin, array $tsConfig, int $uid = 0): BackendUserAuthentication
    {
        $backendUser = $this->getMockBuilder(BackendUserAuthentication::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isAdmin', 'getTSConfig'])
            ->getMock();
        $backendUser->method('isAdmin')->willReturn($admin);
        $backendUser->method('getTSConfig')->willReturn($tsConfig);
        $backendUser->user = ['uid' => $uid];

        return $backendUser;
    }

    private function accessControlServiceWithAdminFlag(int $adminFlag): AccessControlService
    {
        $connection = $this->createMock(Connection::class);
        $connection->method('fetchOne')->willReturn($adminFlag);
        $connectionPool = $this->createMock(ConnectionPool::class);
        $connectionPool->method('getConnectionForTable')->with('be_users')->willReturn($connection);

        return new AccessControlService($connectionPool);
    }

    private function tsConfig(mixed $value): array
    {
        return [
            'options.' => [
                'a11y_quality_gate.' => [
                    'allowImageRemediation' => $value,
                ],
            ],
        ];
    }
}
