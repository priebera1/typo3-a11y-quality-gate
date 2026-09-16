<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Domain\Repository\FieldConfigRepository;
use Psr\Log\LoggerInterface;

/**
 * First-use setup of the scanned fields.
 *
 * A fresh installation has no field configuration, so a content scan would not check any TYPO3 content
 * and report a misleading clean result. The first time AQG is used, the TCA field discovery behind
 * "Refresh fields" runs automatically. Once any field configuration has been stored — even one with
 * every field switched off on purpose — it never runs again, so a deliberate configuration is kept.
 */
final class FieldConfigurationBootstrapService
{
    public function __construct(
        private readonly FieldConfigRepository $fieldConfigRepository,
        private readonly TcaFieldDiscoveryService $tcaFieldDiscoveryService,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int number of fields registered; 0 when a configuration already exists or TCA offers none
     */
    public function initializeIfUnconfigured(): int
    {
        try {
            if ($this->fieldConfigRepository->hasAnyConfiguration()) {
                return 0;
            }

            $discoveredFields = $this->tcaFieldDiscoveryService->discover();
            if ($discoveredFields === []) {
                return 0;
            }

            $this->fieldConfigRepository->refreshFromDiscovery($discoveredFields);

            return count($discoveredFields);
        } catch (\Throwable $exception) {
            // A concurrent first request can insert the same rows first (unique table/field key). First-use
            // setup must never break the module or a scan; the next request reads the stored configuration.
            $this->logger->warning('AQG could not initialise the scanned field configuration.', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);

            return 0;
        }
    }
}
