<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Contract;

use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

interface ImageRemediationPermissionServiceInterface
{
    public function assertCapability(
        int $findingId,
        string $operation,
        string $authorizationStage = 'service',
    ): BackendUserAuthentication;

    /** @param list<string> $fileReferenceFields */
    public function assertCanModify(
        ImageFindingContext $context,
        array $fileReferenceFields = [],
        string $operation = 'unknown',
    ): void;
}
