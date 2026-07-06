<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\AccessControlServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendRecordAccessServiceInterface;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionException;
use Priebera\A11yQualityGate\Remediation\ImageRemediationPermissionService;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;

final class ImageRemediationPermissionServiceTest extends TestCase
{
    #[Test]
    public function editorWithCapabilityAndRecordAccessIsAllowed(): void
    {
        $context = $this->context();
        $recordAccess = $this->recordAccess(
            static fn(string $table, int $uid, array $fields): bool => match ($table) {
                'tt_content' => $uid === 42 && $fields === [],
                'sys_file_reference' => $uid === 10 && $fields === ['alternative', 'tx_a11y_is_decorative'],
                default => false,
            },
        );

        $this->subject($recordAccess)->assertCanModify(
            $context,
            ['alternative', 'tx_a11y_is_decorative'],
        );
        self::addToAssertionCount(1);
    }

    #[Test]
    public function missingBackendUserIsRejectedBeforeCapabilityCheck(): void
    {
        $accessControl = $this->createMock(AccessControlServiceInterface::class);
        $accessControl->expects(self::never())->method('canRemediateImages');
        $recordAccess = $this->createMock(BackendRecordAccessServiceInterface::class);
        $recordAccess->expects(self::never())->method('canEditRecordFields');
        $userService = $this->createMock(BackendUserServiceInterface::class);
        $userService->method('getBackendUser')->willReturn(null);

        $this->expectException(ImageRemediationPermissionException::class);
        (new ImageRemediationPermissionService($accessControl, $recordAccess, $userService))
            ->assertCanModify($this->context());
    }

    #[Test]
    #[DataProvider('operationFieldProvider')]
    public function missingCapabilityIsRejectedBeforeRecordChecks(array $fields): void
    {
        $recordAccess = $this->createMock(BackendRecordAccessServiceInterface::class);
        $recordAccess->expects(self::never())->method('isRecordOnPage');
        $recordAccess->expects(self::never())->method('canEditRecordFields');

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess, capabilityAllowed: false)->assertCanModify(
            $this->context(),
            $fields,
        );
    }

    #[Test]
    public function missingParentRecordPermissionIsRejected(): void
    {
        $recordAccess = $this->recordAccess(
            static fn(string $table): bool => $table !== 'tt_content',
        );

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context(), ['alternative']);
    }

    #[Test]
    public function missingFileReferenceModifyPermissionIsRejected(): void
    {
        $recordAccess = $this->recordAccess(
            static fn(string $table): bool => $table !== 'sys_file_reference',
        );

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context(), ['alternative']);
    }

    #[Test]
    public function missingAlternativeFieldPermissionIsRejected(): void
    {
        $recordAccess = $this->recordAccess(
            static fn(string $table, int $uid, array $fields): bool => !(
                $table === 'sys_file_reference' && in_array('alternative', $fields, true)
            ),
        );

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context(), ['alternative']);
    }

    #[Test]
    public function missingDecorativeFieldPermissionIsRejected(): void
    {
        $recordAccess = $this->recordAccess(
            static fn(string $table, int $uid, array $fields): bool => !(
                $table === 'sys_file_reference' && in_array('tx_a11y_is_decorative', $fields, true)
            ),
        );

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context(), ['tx_a11y_is_decorative']);
    }

    #[Test]
    public function inaccessibleParentPageIsRejected(): void
    {
        $recordAccess = $this->recordAccess(
            static fn(): bool => true,
            static fn(string $table): bool => $table !== 'tt_content',
        );

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context());
    }

    #[Test]
    public function fileReferenceFromAnotherPageIsRejected(): void
    {
        $recordAccess = $this->recordAccess(
            static fn(): bool => true,
            static fn(string $table): bool => $table !== 'sys_file_reference',
        );

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context());
    }

    #[Test]
    public function findingBoundToAnotherParentRecordIsRejectedBeforeRecordChecks(): void
    {
        $recordAccess = $this->createMock(BackendRecordAccessServiceInterface::class);
        $recordAccess->expects(self::never())->method('canEditRecordFields');

        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($recordAccess)->assertCanModify($this->context(referenceParentUid: 99));
    }

    #[Test]
    public function differentWorkspaceIsRejected(): void
    {
        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($this->recordAccess(), workspace: 2)
            ->assertCanModify($this->context(workspace: 1));
    }

    #[Test]
    public function differentLanguageIsRejected(): void
    {
        $this->expectException(ImageRemediationPermissionException::class);
        $this->subject($this->recordAccess())
            ->assertCanModify($this->context(referenceLanguage: 1));
    }

    #[Test]
    public function allLanguageReferenceIsAllowedForAllLanguageFinding(): void
    {
        $this->subject($this->recordAccess())
            ->assertCanModify($this->context(referenceLanguage: -1, contextLanguage: -1));
        self::addToAssertionCount(1);
    }


    public static function operationFieldProvider(): iterable
    {
        yield 'mark decorative' => [['tx_a11y_is_decorative', 'alternative']];
        yield 'mark informative' => [['tx_a11y_is_decorative']];
        yield 'apply alt' => [['alternative', 'tx_a11y_is_decorative']];
    }

    private function subject(
        BackendRecordAccessServiceInterface $recordAccess,
        bool $capabilityAllowed = true,
        int $workspace = 0,
    ): ImageRemediationPermissionService {
        $backendUser = $this->backendUser($workspace);
        $accessControl = $this->createMock(AccessControlServiceInterface::class);
        $accessControl->expects(self::once())
            ->method('canRemediateImages')
            ->with($backendUser)
            ->willReturn($capabilityAllowed);

        return new ImageRemediationPermissionService(
            $accessControl,
            $recordAccess,
            $this->userService($backendUser),
        );
    }

    private function recordAccess(
        ?callable $canEditRecord = null,
        ?callable $isRecordOnPage = null,
    ): BackendRecordAccessServiceInterface {
        $recordAccess = $this->createMock(BackendRecordAccessServiceInterface::class);
        $recordAccess->method('canEditRecordFields')->willReturnCallback(
            $canEditRecord ?? static fn(): bool => true,
        );
        $recordAccess->method('isRecordOnPage')->willReturnCallback(
            $isRecordOnPage ?? static fn(): bool => true,
        );

        return $recordAccess;
    }

    private function userService(BackendUserAuthentication $backendUser): BackendUserServiceInterface
    {
        $userService = $this->createMock(BackendUserServiceInterface::class);
        $userService->method('getBackendUser')->willReturn($backendUser);

        return $userService;
    }

    private function backendUser(int $workspace): BackendUserAuthentication
    {
        $user = (new \ReflectionClass(BackendUserAuthentication::class))->newInstanceWithoutConstructor();
        $user->workspace = $workspace;

        return $user;
    }

    private function context(
        int $workspace = 0,
        int $referenceLanguage = 0,
        int $contextLanguage = 0,
        int $referenceParentUid = 42,
    ): ImageFindingContext {
        return new ImageFindingContext(
            issue: ['uid' => 12],
            fileReference: [
                'uid' => 10,
                'uid_foreign' => $referenceParentUid,
                'tablenames' => 'tt_content',
                'fieldname' => 'assets',
                't3ver_wsid' => $workspace,
                'sys_language_uid' => $referenceLanguage,
            ],
            siteIdentifier: 'main',
            pageUid: 1,
            languageUid: $contextLanguage,
            sourceTable: 'tt_content',
            sourceUid: 42,
            sourceField: 'assets',
            fileReferenceUid: 10,
            fileUid: 20,
            fingerprint: 'fingerprint',
            issueTimestamp: 100,
            fileReferenceTimestamp: 200,
        );
    }
}
