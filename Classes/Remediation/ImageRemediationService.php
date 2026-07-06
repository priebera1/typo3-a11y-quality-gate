<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation;

use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageReferenceWriterInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationTransactionManagerInterface;

final class ImageRemediationService implements ImageRemediationServiceInterface
{
    private const DECORATIVE_FIELDS = ['tx_a11y_is_decorative', 'alternative'];
    private const INFORMATIVE_FIELDS = ['tx_a11y_is_decorative'];
    private const ALT_TEXT_FIELDS = ['alternative', 'tx_a11y_is_decorative'];

    public function __construct(
        private readonly ImageFindingContextResolverInterface $resolver,
        private readonly ImageRemediationPermissionServiceInterface $permissionService,
        private readonly ImageAltTextValidator $altTextValidator,
        private readonly ImageFindingVersionTokenServiceInterface $versionTokenService,
        private readonly IssueRemediationRepositoryInterface $issueRepository,
        private readonly BackendUserServiceInterface $backendUserService,
        private readonly ImageReferenceWriterInterface $writer,
        private readonly ImageRemediationTransactionManagerInterface $transactionManager,
    ) {}

    public function resolve(int $findingId): ImageFindingContext
    {
        return $this->resolver->resolve($findingId);
    }

    public function markDecorative(int $findingId, string $expectedVersion): ImageFindingContext
    {
        $context = $this->resolveFresh($findingId, $expectedVersion, self::DECORATIVE_FIELDS, 'mark-decorative');

        return $this->transactionManager->transactional(function() use ($context, $findingId): ImageFindingContext {
            $this->writer->write($context, [
                'tx_a11y_is_decorative' => 1,
                'alternative' => '',
            ]);
            $this->markResolvedWithAudit($findingId);

            return $context;
        });
    }

    public function markInformative(int $findingId, string $expectedVersion): ImageFindingContext
    {
        $context = $this->resolveFresh($findingId, $expectedVersion, self::INFORMATIVE_FIELDS, 'mark-informative');

        return $this->transactionManager->transactional(function() use ($context, $findingId): ImageFindingContext {
            $this->writer->write($context, ['tx_a11y_is_decorative' => 0]);
            $this->issueRepository->markOpenAfterRemediation($findingId);

            return $context;
        });
    }

    public function applyAlt(int $findingId, string $altText, string $expectedVersion): ImageFindingContext
    {
        $context = $this->resolveFresh($findingId, $expectedVersion, self::ALT_TEXT_FIELDS, 'apply-alt');
        $altText = $this->altTextValidator->validate($altText);

        return $this->transactionManager->transactional(function() use ($context, $findingId, $altText): ImageFindingContext {
            $this->writer->write($context, [
                'alternative' => $altText,
                'tx_a11y_is_decorative' => 0,
            ]);

            if ((string)($context->issue['rule_id'] ?? '') === 'structured.file_reference_alt') {
                $this->markResolvedWithAudit($findingId);
            } else {
                $this->issueRepository->markOpenAfterRemediation($findingId);
            }

            return $context;
        });
    }

    /** @param list<string> $fileReferenceFields */
    private function resolveFresh(
        int $findingId,
        string $expectedVersion,
        array $fileReferenceFields,
        string $operation,
    ): ImageFindingContext {
        if ($findingId <= 0) {
            throw new ImageRemediationValidationException('invalid_finding_id');
        }

        $context = $this->resolver->resolve($findingId);
        $this->permissionService->assertCanModify($context, $fileReferenceFields, $operation);
        if ($expectedVersion === '') {
            throw new InvalidImageVersionTokenException('The remediation token is missing.', 1771001400);
        }
        $this->versionTokenService->assertValid($expectedVersion, $context);

        return $context;
    }

    private function markResolvedWithAudit(int $findingId): void
    {
        $user = $this->backendUserService->getBackendUserSnapshot();
        $this->issueRepository->markResolvedAfterRemediation(
            $findingId,
            $user['uid'],
            $user['name'],
            $user['username'],
        );
    }
}
