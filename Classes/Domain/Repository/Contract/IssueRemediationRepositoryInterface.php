<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository\Contract;

interface IssueRemediationRepositoryInterface
{
    public function findByUid(int $issueUid): ?array;
    public function markOpenAfterRemediation(int $issueUid): void;
    public function markResolvedAfterRemediation(int $issueUid, int $resolvedBy = 0, string $resolvedByName = '', string $resolvedByUsername = ''): void;
}
