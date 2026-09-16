<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Service\FieldConfigurationBootstrapService;
use Priebera\A11yQualityGate\Service\TcaFieldDiscoveryService;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * A fresh installation must not need a hidden "Refresh fields" step before a scan checks content, and an
 * installation that has stored any field configuration — even one with every field off — is never touched.
 */
final class FieldConfigurationBootstrapServiceTest extends TestCase
{
    private const DISCOVERED = [
        ['table_name' => 'tt_content', 'field_name' => 'bodytext', 'field_type' => 'rte', 'field_label' => 'Text', 'is_enabled' => 1, 'is_auto_detected' => 1],
        ['table_name' => 'tt_content', 'field_name' => 'image', 'field_type' => 'file', 'field_label' => 'Images', 'is_enabled' => 1, 'is_auto_detected' => 1],
    ];

    #[Test]
    public function freshInstallationRegistersTheDiscoveredFields(): void
    {
        $repository = $this->createMock(FieldConfigRepository::class);
        $repository->method('hasAnyConfiguration')->willReturn(false);
        $repository->expects(self::once())->method('refreshFromDiscovery')->with(self::DISCOVERED);

        $discovery = $this->createMock(TcaFieldDiscoveryService::class);
        $discovery->expects(self::once())->method('discover')->willReturn(self::DISCOVERED);

        $service = new FieldConfigurationBootstrapService($repository, $discovery, new NullLogger());

        self::assertSame(2, $service->initializeIfUnconfigured());
    }

    #[Test]
    public function storedConfigurationIsNeverRediscoveredEvenWhenEveryFieldIsDisabled(): void
    {
        $repository = $this->createMock(FieldConfigRepository::class);
        $repository->method('hasAnyConfiguration')->willReturn(true);
        $repository->method('hasEnabledFields')->willReturn(false);
        $repository->expects(self::never())->method('refreshFromDiscovery');

        $discovery = $this->createMock(TcaFieldDiscoveryService::class);
        $discovery->expects(self::never())->method('discover');

        $service = new FieldConfigurationBootstrapService($repository, $discovery, new NullLogger());

        self::assertSame(0, $service->initializeIfUnconfigured());
    }

    #[Test]
    public function installationWithoutScannableFieldsWritesNothing(): void
    {
        $repository = $this->createMock(FieldConfigRepository::class);
        $repository->method('hasAnyConfiguration')->willReturn(false);
        $repository->expects(self::never())->method('refreshFromDiscovery');

        $discovery = $this->createMock(TcaFieldDiscoveryService::class);
        $discovery->method('discover')->willReturn([]);

        $service = new FieldConfigurationBootstrapService($repository, $discovery, new NullLogger());

        self::assertSame(0, $service->initializeIfUnconfigured());
    }

    #[Test]
    public function failedFirstRunIsLoggedAndNeverBreaksTheModuleOrScan(): void
    {
        $repository = $this->createMock(FieldConfigRepository::class);
        $repository->method('hasAnyConfiguration')->willReturn(false);
        $repository->method('refreshFromDiscovery')->willThrowException(new \RuntimeException('Duplicate entry for key uniq_table_field'));

        $discovery = $this->createMock(TcaFieldDiscoveryService::class);
        $discovery->method('discover')->willReturn(self::DISCOVERED);

        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning');

        $service = new FieldConfigurationBootstrapService($repository, $discovery, $logger);

        self::assertSame(0, $service->initializeIfUnconfigured());
    }
}
