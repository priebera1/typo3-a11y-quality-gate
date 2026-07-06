<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Remediation\ImageRemediationTransactionManager;
use Priebera\A11yQualityGate\Remediation\ImageRemediationWriteException;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ImageRemediationTransactionManagerTest extends TestCase
{
    #[Test]
    public function operationRunsInsideSharedConnectionTransaction(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())
            ->method('transactional')
            ->willReturnCallback(static function(callable $operation) use ($connection): mixed {
                return $operation($connection);
            });
        $pool = $this->createMock(ConnectionPool::class);
        $pool->method('getConnectionForTable')->willReturn($connection);

        $result = (new ImageRemediationTransactionManager($pool))->transactional(
            static fn(): string => 'committed',
        );

        self::assertSame('committed', $result);
    }

    #[Test]
    public function differentConnectionsAreRejectedBeforeOperationRuns(): void
    {
        $fileReferenceConnection = $this->createMock(Connection::class);
        $issueConnection = $this->createMock(Connection::class);
        $pool = $this->createMock(ConnectionPool::class);
        $pool->expects(self::exactly(2))
            ->method('getConnectionForTable')
            ->willReturnOnConsecutiveCalls($fileReferenceConnection, $issueConnection);
        $operationCalled = false;

        try {
            (new ImageRemediationTransactionManager($pool))->transactional(
                static function() use (&$operationCalled): void {
                    $operationCalled = true;
                },
            );
            self::fail('Expected an ImageRemediationWriteException.');
        } catch (ImageRemediationWriteException) {
            self::assertFalse($operationCalled);
        }
    }
}
