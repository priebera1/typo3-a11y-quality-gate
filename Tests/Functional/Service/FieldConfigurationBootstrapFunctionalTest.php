<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Tests\Functional\Service;

use PHPUnit\Framework\Attributes\Test;
use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Priebera\A11yQualityGate\Service\BackendLanguageService;
use Priebera\A11yQualityGate\Service\FieldConfigurationBootstrapService;
use Priebera\A11yQualityGate\Service\TcaFieldDiscoveryService;
use Priebera\A11yQualityGate\Tests\Functional\AbstractFunctionalTestCase;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * First use against the real TCA and database: the scanned fields are set up without a manual discovery
 * step, and a configuration an administrator changed afterwards is kept on every later request.
 */
final class FieldConfigurationBootstrapFunctionalTest extends AbstractFunctionalTestCase
{
    #[Test]
    public function firstUseRegistersContentFieldsFromTca(): void
    {
        $repository = $this->createRepository();
        self::assertFalse($repository->hasAnyConfiguration());

        $registered = $this->createService($repository)->initializeIfUnconfigured();

        self::assertGreaterThan(0, $registered);
        self::assertTrue($repository->hasEnabledFields());
        self::assertContains('bodytext', $repository->findEnabledFieldMap()['tt_content'] ?? []);
    }

    #[Test]
    public function laterRequestsKeepAConfigurationWithEveryFieldDisabled(): void
    {
        $repository = $this->createRepository();
        $service = $this->createService($repository);
        self::assertGreaterThan(0, $service->initializeIfUnconfigured());

        $repository->saveEnabledState([]);

        self::assertSame(0, $service->initializeIfUnconfigured());
        self::assertFalse($repository->hasEnabledFields(), 'A deliberately disabled configuration must not be re-enabled.');
    }

    #[Test]
    public function deletedRowsStillCountAsAStoredConfiguration(): void
    {
        GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('tx_a11y_field_config')
            ->insert('tx_a11y_field_config', [
                'pid' => 0,
                'deleted' => 1,
                'hidden' => 0,
                'table_name' => 'tt_content',
                'field_name' => 'bodytext',
                'field_type' => 'rte',
                'field_label' => 'Text',
                'is_enabled' => 1,
                'is_auto_detected' => 1,
            ]);

        $repository = $this->createRepository();

        self::assertTrue($repository->hasAnyConfiguration());
        self::assertSame(0, $this->createService($repository)->initializeIfUnconfigured());
        self::assertFalse($repository->hasEnabledFields());
    }

    private function createRepository(): FieldConfigRepository
    {
        return new FieldConfigRepository(GeneralUtility::makeInstance(ConnectionPool::class));
    }

    private function createService(FieldConfigRepository $repository): FieldConfigurationBootstrapService
    {
        return new FieldConfigurationBootstrapService(
            $repository,
            new TcaFieldDiscoveryService(new BackendLanguageService()),
            new NullLogger(),
        );
    }
}
