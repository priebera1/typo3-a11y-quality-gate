<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class FileReferenceSchemaService implements FileReferenceSchemaServiceInterface
{
    private ?bool $hasDecorativeColumn = null;
    private ?int $alternativeStorageLimit = null;
    /** @var array<string,object>|null */
    private ?array $tableColumns = null;

    public function __construct(private readonly ConnectionPool $connectionPool) {}

    public function hasDecorativeColumn(): bool
    {
        return $this->hasDecorativeColumn ??= array_key_exists(
            'tx_a11y_is_decorative',
            $this->columns(),
        );
    }

    public function alternativeStorageLimit(): int
    {
        if ($this->alternativeStorageLimit !== null) {
            return $this->alternativeStorageLimit;
        }

        $column = $this->columns()['alternative'] ?? null;
        $length = $column !== null && method_exists($column, 'getLength') ? $column->getLength() : null;

        return $this->alternativeStorageLimit = is_int($length) && $length > 0 ? $length : 1024;
    }

    /** @return array<string,object> */
    private function columns(): array
    {
        return $this->tableColumns ??= $this->connectionPool
            ->getConnectionForTable(Tables::SYS_FILE_REFERENCE)
            ->createSchemaManager()
            ->listTableColumns(Tables::SYS_FILE_REFERENCE);
    }
}
