<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Remediation;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\BackendUserServiceInterface;
use Priebera\A11yQualityGate\Remediation\ImageFindingContext;
use Priebera\A11yQualityGate\Remediation\ImageFindingVersionTokenService;
use Priebera\A11yQualityGate\Remediation\InvalidImageVersionTokenException;
use Priebera\A11yQualityGate\Remediation\StaleImageFindingException;

final class ImageFindingVersionTokenServiceTest extends TestCase
{
    protected function setUp(): void
    {
        $GLOBALS['TYPO3_CONF_VARS']['SYS']['encryptionKey'] = 'unit-test-encryption-key';
    }

    #[Test]
    public function validTokenIsBoundToFindingSiteReferenceAndBackendUser(): void
    {
        $subject = $this->subjectForUser(42);
        $context = $this->context();
        $token = $subject->create($context);

        $subject->assertValid($token, $context);
        self::assertNotSame('', $token);
    }

    #[Test]
    public function tokenCannotBeCreatedWithoutAuthenticatedBackendUser(): void
    {
        $this->expectException(InvalidImageVersionTokenException::class);
        $this->subjectForUser(0)->create($this->context());
    }

    #[Test]
    public function tamperedTokenIsRejected(): void
    {
        $subject = $this->subjectForUser(42);
        $token = $subject->create($this->context());
        $token[5] = $token[5] === 'a' ? 'b' : 'a';

        $this->expectException(InvalidImageVersionTokenException::class);
        $subject->assertValid($token, $this->context());
    }

    #[Test]
    public function tokenFromAnotherBackendUserIsRejected(): void
    {
        $token = $this->subjectForUser(42)->create($this->context());

        $this->expectException(StaleImageFindingException::class);
        $this->subjectForUser(43)->assertValid($token, $this->context());
    }

    #[Test]
    public function tokenFromAnotherSiteIsRejected(): void
    {
        $subject = $this->subjectForUser(42);
        $token = $subject->create($this->context());

        $this->expectException(StaleImageFindingException::class);
        $subject->assertValid($token, $this->context('other-site'));
    }

    #[Test]
    public function changedFileReferenceIsRejectedAsStale(): void
    {
        $subject = $this->subjectForUser(42);
        $token = $subject->create($this->context());
        $changed = $this->context();
        $changed = new ImageFindingContext(
            issue: $changed->issue,
            fileReference: $changed->fileReference,
            siteIdentifier: $changed->siteIdentifier,
            pageUid: $changed->pageUid,
            languageUid: $changed->languageUid,
            sourceTable: $changed->sourceTable,
            sourceUid: $changed->sourceUid,
            sourceField: $changed->sourceField,
            fileReferenceUid: $changed->fileReferenceUid,
            fileUid: $changed->fileUid,
            fingerprint: $changed->fingerprint,
            issueTimestamp: $changed->issueTimestamp,
            fileReferenceTimestamp: 201,
        );

        $this->expectException(StaleImageFindingException::class);
        $subject->assertValid($token, $changed);
    }

    private function subjectForUser(int $uid): ImageFindingVersionTokenService
    {
        $backendUserService = $this->createMock(BackendUserServiceInterface::class);
        $backendUserService->method('getBackendUserUid')->willReturn($uid);

        return new ImageFindingVersionTokenService($backendUserService);
    }

    private function context(string $site = 'main'): ImageFindingContext
    {
        return new ImageFindingContext(
            issue: ['uid' => 12],
            fileReference: [],
            siteIdentifier: $site,
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
