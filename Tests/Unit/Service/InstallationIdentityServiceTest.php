<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Service\InstallationIdentityService;
use TYPO3\CMS\Core\Registry;

final class InstallationIdentityServiceTest extends TestCase
{
    private const UUID_V4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

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
                self::callback(static fn (string $value): bool => preg_match(self::UUID_V4, $value) === 1),
            );

        $installationId = (new InstallationIdentityService($registry))->getOrCreateInstallationId();

        self::assertMatchesRegularExpression(self::UUID_V4, $installationId);
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

    #[Test]
    public function theIdentityLivesInTheDatabaseSoARestoreOrRedeploymentKeepsIt(): void
    {
        $database = [];
        $first = (new InstallationIdentityService($this->registry($database)))->getOrCreateInstallationId();

        // A new release, a restored backup or a moved server: new PHP processes, the same sys_registry rows.
        $restored = $database;
        $again = new InstallationIdentityService($this->registry($restored));

        self::assertSame($first, $again->getOrCreateInstallationId());
        self::assertSame($first, $again->findInstallationId());
        self::assertNull($again->findLastReassignment(), 'nothing ever replaces the identity on its own');
    }

    #[Test]
    public function aDatabaseCloneSharesItsSourceIdentityUntilItIsGivenANewOne(): void
    {
        $source = [];
        $sourceService = new InstallationIdentityService($this->registry($source));
        $sourceId = $sourceService->getOrCreateInstallationId();

        $clone = $source;
        $cloneService = new InstallationIdentityService($this->registry($clone));
        self::assertSame($sourceId, $cloneService->getOrCreateInstallationId(), 'a copied database is the same installation to AQG');

        $result = $cloneService->assignNewInstallationId();
        $cloneId = $cloneService->getOrCreateInstallationId();

        self::assertMatchesRegularExpression(self::UUID_V4, $cloneId);
        self::assertNotSame($sourceId, $cloneId);
        self::assertSame(InstallationIdentityService::fingerprint($sourceId), $result['previousFingerprint']);
        self::assertSame(InstallationIdentityService::fingerprint($cloneId), $result['fingerprint']);
        self::assertSame($sourceId, $sourceService->getOrCreateInstallationId(), 'the source keeps its identity');

        $reassignment = $cloneService->findLastReassignment();
        self::assertNotNull($reassignment);
        self::assertSame(InstallationIdentityService::fingerprint($sourceId), $reassignment['previousFingerprint']);
        self::assertGreaterThan(0, $reassignment['reassignedAt']);

        // The copy no longer holds its source's credential anywhere in its registry.
        self::assertStringNotContainsString($sourceId, serialize($clone));
    }

    #[Test]
    public function theFingerprintIsAShortStableLabelThatRevealsNothingOfTheId(): void
    {
        $id = '018f5f5d-8d31-4f4c-9bce-2e1c6a91c444';
        $fingerprint = InstallationIdentityService::fingerprint($id);

        self::assertMatchesRegularExpression('/^[0-9a-f]{4}(-[0-9a-f]{4}){3}$/', $fingerprint);
        self::assertSame($fingerprint, InstallationIdentityService::fingerprint(' ' . $id . ' '));
        self::assertNotSame($fingerprint, InstallationIdentityService::fingerprint('018f5f5d-8d31-4f4c-9bce-2e1c6a91c445'));
        // Not a prefix of the hash the AQG service stores for the installation's Agency project.
        self::assertStringStartsNotWith(
            str_replace('-', '', $fingerprint),
            hash('sha256', "aqg-licence-project:v1\n" . $id),
        );
    }

    #[Test]
    public function aNewIdentityOnAnInstallationWithoutOneRecordsNoPreviousFingerprint(): void
    {
        $database = [];
        $service = new InstallationIdentityService($this->registry($database));

        $result = $service->assignNewInstallationId();

        self::assertNull($result['previousFingerprint']);
        self::assertSame($result['fingerprint'], InstallationIdentityService::fingerprint((string)$service->findInstallationId()));
    }

    /**
     * sys_registry rows of one database, as an array the test can copy like a database dump.
     *
     * @param array<string, mixed> $rows
     */
    private function registry(array &$rows): Registry
    {
        $registry = $this->createMock(Registry::class);
        $registry->method('get')->willReturnCallback(
            static function (string $namespace, string $key, mixed $default = null) use (&$rows): mixed {
                return $rows[$namespace . ':' . $key] ?? $default;
            }
        );
        $registry->method('set')->willReturnCallback(
            static function (string $namespace, string $key, mixed $value) use (&$rows): void {
                $rows[$namespace . ':' . $key] = $value;
            }
        );

        return $registry;
    }
}
