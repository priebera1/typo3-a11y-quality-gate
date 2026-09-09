<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\FreePreview;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Contract\InstallationIdentityServiceInterface;
use Priebera\A11yQualityGate\FreePreview\FreePreviewProofService;
use Priebera\A11yQualityGate\Pro\Service\DomainNormalizer;

final class FreePreviewProofServiceTest extends TestCase
{
    #[Test]
    public function proofV1DigestExactlyMatchesApiContract(): void
    {
        $identity = $this->createMock(InstallationIdentityServiceInterface::class);
        $identity->method('getOrCreateInstallationId')
            ->willReturn('018f5f5d-8d31-7f4c-9bce-2e1c6a91c444');
        $service = new FreePreviewProofService($identity, new DomainNormalizer());

        self::assertSame(
            'cUofMh7juUvU1l-qDj89ETVNGPnFrPodjZOTRpf8YMI',
            $service->buildForSiteBase('https://WWW.Example.COM./subsite/'),
        );
        self::assertStringNotContainsString('=', $service->buildForSiteBase('https://example.com/'));
    }
}
