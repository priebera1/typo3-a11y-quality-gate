<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Mcp;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-site agent access (atlas 13 · B). A site without a row, or with MCP off, is invisible to agents even when a
 * user can open it. Narrowing takes effect on the next agent call; tokens are not reissued.
 */
final class McpSiteSettingsRepository
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array{enabled: bool, capabilities: list<string>}
     */
    public function find(string $siteIdentifier): array
    {
        $row = $this->connectionPool->getConnectionForTable(Tables::MCP_SITE)->select(
            ['enabled', 'capabilities'],
            Tables::MCP_SITE,
            ['site_identifier' => $siteIdentifier],
        )->fetchAssociative();

        if (!is_array($row)) {
            return ['enabled' => false, 'capabilities' => []];
        }

        return [
            'enabled' => (int)$row['enabled'] === 1,
            'capabilities' => McpScopes::normalize(array_map('trim', explode(',', (string)$row['capabilities']))),
        ];
    }

    /**
     * Capabilities an agent may use on the site right now: none while MCP is off for it.
     *
     * @return list<string>
     */
    public function effectiveCapabilities(string $siteIdentifier): array
    {
        $settings = $this->find($siteIdentifier);

        return $settings['enabled'] ? $settings['capabilities'] : [];
    }

    /**
     * @param list<string> $capabilities
     */
    public function save(string $siteIdentifier, bool $enabled, array $capabilities, int $changedBy): void
    {
        $connection = $this->connectionPool->getConnectionForTable(Tables::MCP_SITE);
        $values = [
            'enabled' => $enabled ? 1 : 0,
            'capabilities' => implode(',', McpScopes::normalize($capabilities)),
            'changed_by' => $changedBy,
            'tstamp' => time(),
        ];
        $existing = $connection->count('uid', Tables::MCP_SITE, ['site_identifier' => $siteIdentifier]);
        if ($existing > 0) {
            $connection->update(Tables::MCP_SITE, $values, ['site_identifier' => $siteIdentifier]);
            return;
        }
        $connection->insert(Tables::MCP_SITE, $values + ['site_identifier' => $siteIdentifier, 'crdate' => time()]);
    }
}
