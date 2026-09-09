<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use TYPO3\CMS\Core\Registry;

final class InstallationIdentityServiceTest extends TestCase
{
    #[Test]
    public function installationIdIsCreatedOnce(): void
    {
        $registry = $this->createMock(Registry::class);
        $registry->expects(self::once())
            ->method('get')
            ->with('a11y_quality_gate', 'anonymous_installation_id', '')
            ->willReturn('');
        $registry->expects(self::once())
            ->method('set')
            ->with(
                'a11y_quality_gate',
                'anonymous_installation_id',
                self::callback(static fn (string $value): bool => preg_match(
                    '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
                    $value
                ) === 1),
            );

        $installationId = (new InstallationIdentityService($registry))->getOrCreateInstallationId();

        self::assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $installationId,
        );
    }

    #[Test]
    public function existingInstallationIdIsReused(): void
    {
        $existing = '018f5f5d-8d31-4f4c-9bce-2e1c6a91c444';
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturn($existing);
        $registry->expects(self::never())->method('set');

        self::assertSame(
            $existing,
            (new InstallationIdentityService($registry))->getOrCreateInstallationId(),
        );
    }
}
