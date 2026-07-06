<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationTransactionManagerInterface;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class ImageRemediationTransactionManager implements ImageRemediationTransactionManagerInterface
{
    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function transactional(callable $operation): mixed
    {
        $fileReferenceConnection = $this->connectionPool->getConnectionForTable(Tables::SYS_FILE_REFERENCE);
        $issueConnection = $this->connectionPool->getConnectionForTable(Tables::ISSUE);
        if ($fileReferenceConnection !== $issueConnection) {
            throw new ImageRemediationWriteException('image_remediation_requires_shared_connection');
        }

        return $fileReferenceConnection->transactional(
            static fn(Connection $connection): mixed => $operation(),
        );
    }
}
