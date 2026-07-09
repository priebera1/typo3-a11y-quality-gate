<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Domain\Repository\Contract\AiConfigurationRepositoryInterface;
use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class AiConfigurationRepository extends AbstractRepository implements AiConfigurationRepositoryInterface
{
    public function findBySiteIdentifier(string $siteIdentifier): ?array
    {
        $qb = $this->getQueryBuilder(Tables::AI_CONFIGURATION);
        $rows = $qb->select('*')->from(Tables::AI_CONFIGURATION)
            ->where(
                $qb->expr()->eq('site_identifier', $qb->createNamedParameter($siteIdentifier)),
                $qb->expr()->eq('provider', $qb->createNamedParameter('openai')),
                $qb->expr()->eq('deleted', $qb->createNamedParameter(0, Connection::PARAM_INT)),
            )->setMaxResults(2)->executeQuery()->fetchAllAssociative();
        if (count($rows) > 1) {
            throw new \RuntimeException('Multiple active AI configurations exist for the same site.', 1771002501);
        }

        return $rows[0] ?? null;
    }

    public function saveKey(
        string $siteIdentifier,
        string $encryptedApiKey,
        string $keyHint,
        bool $enabled = true,
    ): void {
        $existing = $this->findBySiteIdentifier($siteIdentifier);
        $now = time();
        $values = [
            'site_identifier' => $siteIdentifier,
            'provider' => 'openai',
            'encrypted_api_key' => $encryptedApiKey,
            'key_hint' => $keyHint,
            'enabled' => $enabled ? 1 : 0,
            'selected_model_id' => (string)($existing['selected_model_id'] ?? ''),
            'discovered_models_cache' => '',
            'discovered_models_at' => 0,
            'verified_key_fingerprint' => '',
            'verified_model_id' => '',
            'verified_prompt_version' => '',
            'verified_connection_contract_version' => '',
            'last_tested_at' => 0,
            'last_verified_at' => 0,
            'last_test_error_code' => '',
            'link_text_suggestions_enabled' => (int)($existing['link_text_suggestions_enabled'] ?? 0),
            'tstamp' => $now,
        ];

        $connection = $this->getConnection(Tables::AI_CONFIGURATION);
        if ($existing === null) {
            $values += [
                'pid' => 0,
                'model' => '',
                'crdate' => $now,
            ];
            $connection->insert(Tables::AI_CONFIGURATION, $values);
            return;
        }

        $connection->update(Tables::AI_CONFIGURATION, $values, ['uid' => (int)$existing['uid']]);
    }

    public function saveDiscovery(
        string $siteIdentifier,
        string $normalizedCacheJson,
        int $discoveredAt,
        string $selectedModelId,
    ): void {
        $row = $this->findBySiteIdentifier($siteIdentifier);
        $oldSelection = trim((string)($row['selected_model_id'] ?? ''));
        $selectionChanged = $oldSelection !== trim($selectedModelId);
        $now = max(time(), $discoveredAt);

        $values = [
            'discovered_models_cache' => $normalizedCacheJson,
            'discovered_models_at' => $discoveredAt,
            'selected_model_id' => trim($selectedModelId),
            'last_test_error_code' => '',
            'tstamp' => $now,
        ];
        if ($selectionChanged) {
            $values += $this->verificationResetValues();
        }

        if ($row === null) {
            $this->insertEnvironmentMetadataRow($siteIdentifier, $values, $now);
            return;
        }

        $this->getConnection(Tables::AI_CONFIGURATION)
            ->update(Tables::AI_CONFIGURATION, $values, ['uid' => (int)$row['uid']]);
    }

    public function markDiscoveryFailed(
        string $siteIdentifier,
        string $safeErrorCode,
        bool $invalidateSelection,
    ): void {
        $row = $this->findBySiteIdentifier($siteIdentifier);
        $now = time();
        $values = [
            'last_test_error_code' => $this->normalizeSafeCode($safeErrorCode),
            'tstamp' => $now,
        ];
        if ($invalidateSelection) {
            $values += [
                'last_tested_at' => $now,
                'selected_model_id' => '',
                'discovered_models_cache' => '',
                'discovered_models_at' => 0,
            ] + $this->verificationResetValues();
        }

        if ($row === null) {
            $this->insertEnvironmentMetadataRow($siteIdentifier, $values, $now);
            return;
        }

        $this->getConnection(Tables::AI_CONFIGURATION)
            ->update(Tables::AI_CONFIGURATION, $values, ['uid' => (int)$row['uid']]);
    }

    public function selectModel(string $siteIdentifier, string $modelId): void
    {
        $row = $this->findBySiteIdentifier($siteIdentifier);
        if ($row === null) {
            throw new \RuntimeException('AI configuration metadata does not exist.', 1771002502);
        }

        $modelId = trim($modelId);
        if ((string)($row['selected_model_id'] ?? '') === $modelId) {
            return;
        }

        $values = [
            'selected_model_id' => $modelId,
            'last_test_error_code' => '',
            'tstamp' => time(),
        ] + $this->verificationResetValues();

        $this->getConnection(Tables::AI_CONFIGURATION)
            ->update(Tables::AI_CONFIGURATION, $values, ['uid' => (int)$row['uid']]);
    }

    public function setLinkTextSuggestionsEnabled(string $siteIdentifier, bool $enabled): void
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            throw new \InvalidArgumentException('Site identifier is required.');
        }

        $row = $this->findBySiteIdentifier($siteIdentifier);
        $now = time();
        $values = [
            'link_text_suggestions_enabled' => $enabled ? 1 : 0,
            'tstamp' => $now,
        ];

        if ($row === null) {
            $this->insertEnvironmentMetadataRow($siteIdentifier, $values, $now);
            return;
        }

        $this->getConnection(Tables::AI_CONFIGURATION)
            ->update(Tables::AI_CONFIGURATION, $values, ['uid' => (int)$row['uid']]);
    }

    public function isLinkTextSuggestionsEnabled(string $siteIdentifier): bool
    {
        $row = $this->findBySiteIdentifier(trim($siteIdentifier));

        return is_array($row) && (int)($row['link_text_suggestions_enabled'] ?? 0) === 1;
    }

    public function markTested(
        string $siteIdentifier,
        bool $verified,
        string $keyFingerprint,
        string $modelId,
        string $promptVersion,
        string $connectionContractVersion,
        string $safeErrorCode = '',
    ): void {
        if ($siteIdentifier === '') {
            return;
        }

        $row = $this->findBySiteIdentifier($siteIdentifier);
        $now = time();
        $values = [
            'last_tested_at' => $now,
            'last_test_error_code' => $verified ? '' : $this->normalizeSafeCode($safeErrorCode),
            'tstamp' => $now,
        ];

        if ($verified) {
            $values += [
                'last_verified_at' => $now,
                'verified_key_fingerprint' => $keyFingerprint,
                'verified_model_id' => $modelId,
                'verified_prompt_version' => $promptVersion,
                'verified_connection_contract_version' => $connectionContractVersion,
            ];
        } else {
            $values += $this->verificationResetValues();
            $values['last_tested_at'] = $now;
            $values['last_test_error_code'] = $this->normalizeSafeCode($safeErrorCode);
            $values['tstamp'] = $now;
        }

        if ($row === null) {
            $this->insertEnvironmentMetadataRow($siteIdentifier, $values, $now);
            return;
        }

        $this->getConnection(Tables::AI_CONFIGURATION)
            ->update(Tables::AI_CONFIGURATION, $values, ['uid' => (int)$row['uid']]);
    }

    /** @return array<string,int|string> */
    private function verificationResetValues(): array
    {
        return [
            'verified_key_fingerprint' => '',
            'verified_model_id' => '',
            'verified_prompt_version' => '',
            'verified_connection_contract_version' => '',
            'last_verified_at' => 0,
        ];
    }

    /** @param array<string,int|string> $values */
    private function insertEnvironmentMetadataRow(string $siteIdentifier, array $values, int $now): void
    {
        $defaults = [
            'pid' => 0,
            'site_identifier' => $siteIdentifier,
            'provider' => 'openai',
            'encrypted_api_key' => '',
            'key_hint' => '',
            'enabled' => 0,
            'model' => '',
            'selected_model_id' => '',
            'discovered_models_cache' => '',
            'discovered_models_at' => 0,
            'verified_key_fingerprint' => '',
            'verified_model_id' => '',
            'verified_prompt_version' => '',
            'verified_connection_contract_version' => '',
            'last_tested_at' => 0,
            'last_verified_at' => 0,
            'last_test_error_code' => '',
            'link_text_suggestions_enabled' => 0,
            'crdate' => $now,
            'tstamp' => $now,
        ];
        $this->getConnection(Tables::AI_CONFIGURATION)->insert(
            Tables::AI_CONFIGURATION,
            array_replace($defaults, $values),
        );
    }

    private function normalizeSafeCode(string $safeErrorCode): string
    {
        $safeErrorCode = trim((string)preg_replace('/[^a-z0-9_:-]+/i', '_', $safeErrorCode));

        return substr($safeErrorCode, 0, 64);
    }
}
