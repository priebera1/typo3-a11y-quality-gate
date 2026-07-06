<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

final class ImageRemediationPermissionService implements ImageRemediationPermissionServiceInterface
{
    public function __construct(
        private readonly AccessControlServiceInterface $accessControlService,
        private readonly BackendRecordAccessServiceInterface $recordAccessService,
        private readonly BackendUserServiceInterface $backendUserService,
    ) {}

    public function assertCapability(
        int $findingId,
        string $operation,
        string $authorizationStage = 'service',
    ): BackendUserAuthentication
    {
        $backendUser = $this->backendUserService->getBackendUser();
        if ($backendUser === null) {
            $this->logDecision(null, $operation, $findingId, false, false, $authorizationStage);
            throw new ImageRemediationPermissionException('backend_login_required', 1771001100);
        }

        $capabilityAllowed = $this->accessControlService->canRemediateImages($backendUser);
        $this->logDecision(
            $backendUser,
            $operation,
            $findingId,
            $capabilityAllowed,
            $capabilityAllowed,
            $authorizationStage,
        );

        if (!$capabilityAllowed) {
            throw new ImageRemediationPermissionException('permission_denied', 1771001104);
        }

        return $backendUser;
    }

    public function assertCanModify(
        ImageFindingContext $context,
        array $fileReferenceFields = [],
        string $operation = 'unknown',
    ): void
    {
        $findingId = (int)($context->issue['uid'] ?? 0);
        $backendUser = $this->assertCapability($findingId, $operation);

        if (!$this->hasMatchingFindingContext($context)
            || !$this->recordAccessService->isRecordOnPage(
                $context->sourceTable,
                $context->sourceUid,
                $context->pageUid,
            )
            || !$this->recordAccessService->isRecordOnPage(
                Tables::SYS_FILE_REFERENCE,
                $context->fileReferenceUid,
                $context->pageUid,
            )
            || !$this->recordAccessService->canEditRecordFields($context->sourceTable, $context->sourceUid)
            || !$this->recordAccessService->canEditRecordFields(
                Tables::SYS_FILE_REFERENCE,
                $context->fileReferenceUid,
                $fileReferenceFields,
            )) {
            $this->logDecision($backendUser, $operation, $findingId, true, false, 'record');
            throw new ImageRemediationPermissionException('permission_denied', 1771001101);
        }

        $referenceWorkspace = (int)($context->fileReference['t3ver_wsid'] ?? 0);
        $currentWorkspace = (int)($backendUser->workspace ?? 0);
        if ($referenceWorkspace > 0 && $referenceWorkspace !== $currentWorkspace) {
            $this->logDecision($backendUser, $operation, $findingId, true, false, 'workspace');
            throw new ImageRemediationPermissionException('workspace_mismatch', 1771001102);
        }

        $referenceLanguageUid = (int)($context->fileReference['sys_language_uid'] ?? 0);
        if ($referenceLanguageUid >= 0
            && $context->languageUid >= 0
            && $referenceLanguageUid !== $context->languageUid) {
            $this->logDecision($backendUser, $operation, $findingId, true, false, 'language');
            throw new ImageRemediationPermissionException('language_mismatch', 1771001103);
        }

        $this->logDecision($backendUser, $operation, $findingId, true, true, 'final');
    }

    private function logDecision(
        ?BackendUserAuthentication $backendUser,
        string $operation,
        int $findingId,
        bool $canRemediateImages,
        bool $finalPermissionDecision,
        string $authorizationStage,
    ): void
    {
        $value = null;
        if ($backendUser instanceof BackendUserAuthentication) {
            $userTsConfig = $backendUser->getTSConfig();
            $value = $userTsConfig['options.']['a11y_quality_gate.']['allowImageRemediation'] ?? null;
        }

        try {
            GeneralUtility::makeInstance(LogManager::class)
                ->getLogger(self::class)
                ->warning('AQG image remediation permission decision', [
                    'backendUserUid' => (int)($backendUser?->user['uid'] ?? 0),
                    'isAdmin' => $backendUser?->isAdmin() ?? false,
                    'allowImageRemediation' => $this->normalizeTraceValue($value),
                    'canRemediateImages' => $canRemediateImages,
                    'operation' => $operation,
                    'findingUid' => $findingId,
                    'authorizationStage' => $authorizationStage,
                    'finalPermissionDecision' => $finalPermissionDecision,
                ]);
        } catch (\Throwable) {
        }
    }

    private function normalizeTraceValue(mixed $value): string|int|bool|null
    {
        if ($value === null || is_string($value) || is_int($value) || is_bool($value)) {
            return $value;
        }

        return get_debug_type($value);
    }

    private function hasMatchingFindingContext(ImageFindingContext $context): bool
    {
        return $context->sourceTable !== ''
            && $context->sourceUid > 0
            && $context->pageUid > 0
            && $context->sourceField !== ''
            && $context->fileReferenceUid > 0
            && (int)($context->fileReference['uid'] ?? 0) === $context->fileReferenceUid
            && (string)($context->fileReference['tablenames'] ?? '') === $context->sourceTable
            && (int)($context->fileReference['uid_foreign'] ?? 0) === $context->sourceUid
            && (string)($context->fileReference['fieldname'] ?? '') === $context->sourceField;
    }
}
