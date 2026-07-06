<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface FileReferenceRepositoryInterface
{
    public function findByUid(int $uid): ?array;
    /** @return array<int,array<string,mixed>> */
    public function findVisibleImageReferencesWithMetadata(string $tableName, int $recordUid, string $fieldName): array;
}
