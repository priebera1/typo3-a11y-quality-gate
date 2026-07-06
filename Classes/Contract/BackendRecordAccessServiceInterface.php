<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Contract;

interface BackendRecordAccessServiceInterface
{
    public function canEditRecord(string $table, int $uid): bool;

    /** @param list<string> $fields */
    public function canEditRecordFields(string $table, int $uid, array $fields = []): bool;

    public function isRecordOnPage(string $table, int $uid, int $pageUid): bool;
}
