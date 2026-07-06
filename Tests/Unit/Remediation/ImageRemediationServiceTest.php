<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Domain\Repository\Contract\IssueRemediationRepositoryInterface;
use Priebera\A11yQualityGate\Remediation\Contract\FileReferenceSchemaServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingContextResolverInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageFindingVersionTokenServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageReferenceWriterInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationPermissionServiceInterface;
use Priebera\A11yQualityGate\Remediation\Contract\ImageRemediationTransactionManagerInterface;
use Priebera\A11yQualityGate\Remediation\ImageAltTextValidator;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationService;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;

final class ImageRemediationServiceTest extends TestCase
{
    #[Test]
    public function staleApplyIsRejectedBeforeAnyWrite(): void
    {
        $context = $this->context('structured.file_reference_alt');
        $resolver = $this->resolver($context);
        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $token = $this->createMock(ImageFindingVersionTokenServiceInterface::class);
        $token->method('assertValid')->willThrowException(new StaleImageFindingException('stale'));
        $writer = $this->createMock(ImageReferenceWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->expects(self::never())->method('markOpenAfterRemediation');
        $issues->expects(self::never())->method('markResolvedAfterRemediation');

        $subject = $this->subject($resolver, $permission, $token, $writer, $issues);

        $this->expectException(StaleImageFindingException::class);
        $subject->applyAlt(12, 'Reviewed text', 'stale-token');
    }

    #[Test]
    public function markDecorativeChecksFieldsBeforeWriting(): void
    {
        $context = $this->context('structured.file_reference_alt');
        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permission->expects(self::once())->method('assertCanModify')->with(
            $context,
            ['tx_a11y_is_decorative', 'alternative'],
            'mark-decorative',
        );
        $writer = $this->createMock(ImageReferenceWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($context, [
            'tx_a11y_is_decorative' => 1,
            'alternative' => '',
        ]);
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->expects(self::once())->method('markResolvedAfterRemediation')
            ->with(12, 7, 'Editor Name', 'editor');

        $this->subject($this->resolver($context), $permission, null, $writer, $issues)
            ->markDecorative(12, 'valid');
    }

    #[Test]
    public function markInformativeChecksDecorativeFieldBeforeWriting(): void
    {
        $context = $this->context('structured.file_reference_alt');
        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permission->expects(self::once())->method('assertCanModify')->with(
            $context,
            ['tx_a11y_is_decorative'],
            'mark-informative',
        );
        $writer = $this->createMock(ImageReferenceWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($context, [
            'tx_a11y_is_decorative' => 0,
        ]);
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->expects(self::once())->method('markOpenAfterRemediation')->with(12);

        $this->subject($this->resolver($context), $permission, null, $writer, $issues)
            ->markInformative(12, 'valid');
    }

    #[Test]
    public function applyAltChecksBothFieldsBeforeWriting(): void
    {
        $context = $this->context('structured.file_reference_alt');
        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permission->expects(self::once())->method('assertCanModify')->with(
            $context,
            ['alternative', 'tx_a11y_is_decorative'],
            'apply-alt',
        );
        $writer = $this->createMock(ImageReferenceWriterInterface::class);
        $writer->expects(self::once())->method('write')->with($context, [
            'alternative' => 'Reviewed text',
            'tx_a11y_is_decorative' => 0,
        ]);
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->expects(self::once())->method('markResolvedAfterRemediation')
            ->with(12, 7, 'Editor Name', 'editor');
        $issues->expects(self::never())->method('markOpenAfterRemediation');

        $this->subject($this->resolver($context), $permission, null, $writer, $issues)
            ->applyAlt(12, 'Reviewed text', 'valid');
    }

    #[Test]
    public function qualityFindingRemainsOpenUntilRescan(): void
    {
        $context = $this->context('structured.file_reference_alt_quality');
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->expects(self::never())->method('markResolvedAfterRemediation');
        $issues->expects(self::once())->method('markOpenAfterRemediation')->with(12);
        $writer = $this->createMock(ImageReferenceWriterInterface::class);
        $writer->expects(self::once())->method('write');

        $this->subject($this->resolver($context), null, null, $writer, $issues)
            ->applyAlt(12, 'image.jpg', 'valid');
    }

    #[Test]
    #[DataProvider('operationProvider')]
    public function permissionFailurePreventsAllWrites(string $operation): void
    {
        $context = $this->context('structured.file_reference_alt');
        $permission = $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $permission->method('assertCanModify')->willThrowException(
            new ImageRemediationPermissionException('permission_denied'),
        );
        $token = $this->createMock(ImageFindingVersionTokenServiceInterface::class);
        $token->expects(self::never())->method('assertValid');
        $writer = $this->createMock(ImageReferenceWriterInterface::class);
        $writer->expects(self::never())->method('write');
        $issues = $this->createMock(IssueRemediationRepositoryInterface::class);
        $issues->expects(self::never())->method('markOpenAfterRemediation');
        $issues->expects(self::never())->method('markResolvedAfterRemediation');
        $transaction = $this->createMock(ImageRemediationTransactionManagerInterface::class);
        $transaction->expects(self::never())->method('transactional');

        $subject = $this->subject(
            $this->resolver($context),
            $permission,
            $token,
            $writer,
            $issues,
            $transaction,
        );

        $this->expectException(ImageRemediationPermissionException::class);
        match ($operation) {
            'markDecorative' => $subject->markDecorative(12, 'valid'),
            'markInformative' => $subject->markInformative(12, 'valid'),
            'applyAlt' => $subject->applyAlt(12, 'Reviewed text', 'valid'),
        };
    }

    public static function operationProvider(): iterable
    {
        yield 'mark decorative' => ['markDecorative'];
        yield 'mark informative' => ['markInformative'];
        yield 'apply alt' => ['applyAlt'];
    }

    private function subject(
        ImageFindingContextResolverInterface $resolver,
        ?ImageRemediationPermissionServiceInterface $permission = null,
        ?ImageFindingVersionTokenServiceInterface $token = null,
        ?ImageReferenceWriterInterface $writer = null,
        ?IssueRemediationRepositoryInterface $issues = null,
        ?ImageRemediationTransactionManagerInterface $transaction = null,
    ): ImageRemediationService {
        $permission ??= $this->createMock(ImageRemediationPermissionServiceInterface::class);
        $token ??= $this->createMock(ImageFindingVersionTokenServiceInterface::class);
        $schema = $this->createMock(FileReferenceSchemaServiceInterface::class);
        $schema->method('alternativeStorageLimit')->willReturn(1024);
        $issues ??= $this->createMock(IssueRemediationRepositoryInterface::class);
        $user = $this->createMock(BackendUserServiceInterface::class);
        $user->method('getBackendUserSnapshot')->willReturn([
            'uid' => 7,
            'name' => 'Editor Name',
            'username' => 'editor',
        ]);
        $writer ??= $this->createMock(ImageReferenceWriterInterface::class);
        if ($transaction === null) {
            $transaction = $this->createMock(ImageRemediationTransactionManagerInterface::class);
            $transaction->method('transactional')->willReturnCallback(
                static fn(callable $operation): mixed => $operation(),
            );
        }

        return new ImageRemediationService(
            $resolver,
            $permission,
            new ImageAltTextValidator($schema),
            $token,
            $issues,
            $user,
            $writer,
            $transaction,
        );
    }

    private function resolver(ImageFindingContext $context): ImageFindingContextResolverInterface
    {
        $resolver = $this->createMock(ImageFindingContextResolverInterface::class);
        $resolver->method('resolve')->with(12)->willReturn($context);

        return $resolver;
    }

    private function context(string $ruleId): ImageFindingContext
    {
        return new ImageFindingContext(
            issue: ['uid' => 12, 'rule_id' => $ruleId],
            fileReference: [
                'uid' => 10,
                'uid_foreign' => 42,
                'tablenames' => 'tt_content',
                'fieldname' => 'image',
                'sys_language_uid' => 0,
            ],
            siteIdentifier: 'main',
            pageUid: 1,
            languageUid: 0,
            sourceTable: 'tt_content',
            sourceUid: 42,
            sourceField: 'image',
            fileReferenceUid: 10,
            fileUid: 20,
            fingerprint: 'fingerprint',
            issueTimestamp: 100,
            fileReferenceTimestamp: 200,
        );
    }
}
