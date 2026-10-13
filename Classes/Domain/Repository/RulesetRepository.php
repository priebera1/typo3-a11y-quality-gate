<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class RulesetRepository extends AbstractRepository
{
    /**
     * A site row also stores the site's Remote Scan Access settings (Scanner Token, HTTP auth, crawl lists). With this
     * mode it carries only those: the site has no Quality Gate override and uses the global configuration.
     */
    public const PUBLISH_MODE_INHERIT = -1;

    /**
     * @return array<string, mixed>|null
     */
    public function findBySiteIdentifier(string $siteIdentifier): ?array
    {
        if ($siteIdentifier === '') {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::RULESET);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $queryBuilder->expr()->eq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter($siteIdentifier)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }


    /**
     * @return array<string, mixed>|null
     */
    public function findByScannerToken(string $scannerToken): ?array
    {
        $scannerToken = trim($scannerToken);
        if ($scannerToken === '') {
            return null;
        }

        $queryBuilder = $this->getQueryBuilder(Tables::RULESET);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $queryBuilder->expr()->eq(
                    'scanner_token',
                    $queryBuilder->createNamedParameter($scannerToken)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByScannerToken(string $scannerToken): array
    {
        $scannerToken = trim($scannerToken);
        if ($scannerToken === '') {
            return [];
        }

        $queryBuilder = $this->getQueryBuilder(Tables::RULESET);

        $rows = $queryBuilder
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $queryBuilder->expr()->eq(
                    'scanner_token',
                    $queryBuilder->createNamedParameter($scannerToken)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'DESC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findDefault(): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::RULESET);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $queryBuilder->expr()->eq(
                    'is_default',
                    $queryBuilder->createNamedParameter(1, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUid(int $uid): ?array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::RULESET);

        $row = $queryBuilder
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $queryBuilder->expr()->eq(
                    'uid',
                    $queryBuilder->createNamedParameter($uid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findForSiteOrDefault(string $siteIdentifier): ?array
    {
        $globalRuleset = $this->findDefault();
        if ($globalRuleset !== null && (int)($globalRuleset['is_global'] ?? 1) === 1) {
            return $globalRuleset;
        }

        $siteRuleset = $this->findBySiteIdentifier($siteIdentifier);
        if ($siteRuleset !== null && (int)($siteRuleset['publish_mode'] ?? 0) !== self::PUBLISH_MODE_INHERIT) {
            return $siteRuleset;
        }

        return $globalRuleset;
    }

    /**
     * Rule selection, dictionary and rendered-check settings are edited once for all sites (Settings > Rules writes
     * the default ruleset). A site row is used only when it carries a rules configuration of its own; a Quality Gate
     * override or a site's Remote Scan Access settings do not replace them.
     *
     * @return array<string, mixed>|null
     */
    public function findRulesConfigurationForSite(string $siteIdentifier): ?array
    {
        $siteRuleset = $this->findBySiteIdentifier($siteIdentifier);
        if ($siteRuleset !== null && trim((string)($siteRuleset['rules_json'] ?? '')) !== '') {
            return $siteRuleset;
        }

        return $this->findDefault();
    }

    /**
     * The sites with a Quality Gate override.
     *
     * @return list<array<string, mixed>>
     */
    public function findSiteSpecificRulesets(): array
    {
        $queryBuilder = $this->getQueryBuilder(Tables::RULESET);

        $rows = $queryBuilder
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $queryBuilder->expr()->neq(
                    'site_identifier',
                    $queryBuilder->createNamedParameter('')
                ),
                $queryBuilder->expr()->neq(
                    'publish_mode',
                    $queryBuilder->createNamedParameter(self::PUBLISH_MODE_INHERIT, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->eq(
                    'deleted',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('site_identifier', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_values(array_filter($rows, 'is_array'));
    }

    /**
     * Removes the Quality Gate overrides of all other sites. A site that also has Remote Scan Access settings keeps
     * them: only its override is dropped.
     *
     * @param list<string> $siteIdentifiersToKeep
     */
    public function deleteSiteSpecificExcept(array $siteIdentifiersToKeep): void
    {
        $siteIdentifiersToKeep = array_values(array_unique(array_filter(array_map(
            static fn (string $identifier): string => trim($identifier),
            $siteIdentifiersToKeep
        ), static fn (string $identifier): bool => $identifier !== '')));

        $connection = $this->getConnection(Tables::RULESET);
        $now = time();

        foreach ($this->findSiteSpecificRulesets() as $ruleset) {
            $siteIdentifier = (string)($ruleset['site_identifier'] ?? '');
            if ($siteIdentifier === '' || in_array($siteIdentifier, $siteIdentifiersToKeep, true)) {
                continue;
            }

            $connection->update(
                Tables::RULESET,
                $this->hasRemoteScanAccessSettings($ruleset)
                    ? ['publish_mode' => self::PUBLISH_MODE_INHERIT, 'tstamp' => $now]
                    : ['deleted' => 1, 'tstamp' => $now],
                [
                    'uid' => (int)$ruleset['uid'],
                ]
            );
        }
    }

    /**
     * @param array<string, mixed> $ruleset
     */
    private function hasRemoteScanAccessSettings(array $ruleset): bool
    {
        foreach (['scanner_token', 'http_auth_user', 'http_auth_pass'] as $field) {
            if (trim((string)($ruleset[$field] ?? '')) !== '') {
                return true;
            }
        }

        foreach (['excluded_patterns', 'cookie_accept_selectors', 'crawl_priority_urls'] as $field) {
            if ($this->normalizeJsonList((string)($ruleset[$field] ?? '')) !== '[]') {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findOrCreateDefault(): ?array
    {
        $existing = $this->findDefault();
        if ($existing !== null) {
            return $existing;
        }

        $connection = $this->getConnection(Tables::RULESET);
        $now = time();

        $connection->insert(Tables::RULESET, [
            'pid' => 0,
            'title' => 'Default Quality Gate',
            'site_identifier' => '',
            'threshold_critical' => 0,
            'threshold_warning' => -1,
            'publish_mode' => 1,
            'rules_json' => '',
            'scanner_token' => '',
            'http_auth_user' => '',
            'http_auth_pass' => '',
            'excluded_patterns' => '[]',
            'cookie_accept_selectors' => '[]',
            'is_global' => 1,
            'crawl_priority_urls' => '[]',
            'is_default' => 1,
            'crdate' => $now,
            'tstamp' => $now,
        ]);

        $insertedUid = (int)$connection->lastInsertId();

        if ($insertedUid <= 0) {
            return null;
        }

        return $this->findByUid($insertedUid);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function saveForSiteOrDefault(
        string $siteIdentifier,
        int $publishMode,
        int $thresholdCritical,
        int $thresholdWarning,
        bool $isGlobal = true,
    ): ?array {
        $siteIdentifier = trim($siteIdentifier);
        $publishMode = max($siteIdentifier !== '' ? self::PUBLISH_MODE_INHERIT : 0, min(2, $publishMode));
        $thresholdCritical = max(0, $thresholdCritical);
        $thresholdWarning = max(-1, $thresholdWarning);

        $existing = $siteIdentifier !== ''
            ? $this->findBySiteIdentifier($siteIdentifier)
            : $this->findDefault();

        $connection = $this->getConnection(Tables::RULESET);
        $now = time();

        $data = [
            'title' => $siteIdentifier !== ''
                ? sprintf('Quality Gate: %s', $siteIdentifier)
                : 'Default Quality Gate',
            'site_identifier' => $siteIdentifier,
            'threshold_critical' => $thresholdCritical,
            'threshold_warning' => $thresholdWarning,
            'publish_mode' => $publishMode,
            'is_default' => $siteIdentifier === '' ? 1 : 0,
            'is_global' => $siteIdentifier === '' && $isGlobal ? 1 : 0,
            'tstamp' => $now,
        ];

        if (is_array($existing)) {
            $connection->update(
                Tables::RULESET,
                $data,
                [
                    'uid' => (int)$existing['uid'],
                ]
            );

            return $this->findByUid((int)$existing['uid']);
        }

        $connection->insert(
            Tables::RULESET,
            $data + [
                'pid' => 0,
                'rules_json' => '',
                'scanner_token' => '',
                'http_auth_user' => '',
                'http_auth_pass' => '',
                'excluded_patterns' => '[]',
                'cookie_accept_selectors' => '[]',
                'crawl_priority_urls' => '[]',
                'crdate' => $now,
            ]
        );

        $insertedUid = (int)$connection->lastInsertId();

        if ($insertedUid <= 0) {
            return null;
        }

        return $this->findByUid($insertedUid);
    }


    /**
     * @return array<string, mixed>|null
     */
    public function saveScannerTokenForDefault(string $scannerToken): ?array
    {
        $existing = $this->findOrCreateDefault();
        if (!is_array($existing)) {
            return null;
        }

        $this->getConnection(Tables::RULESET)->update(
            Tables::RULESET,
            [
                'scanner_token' => trim($scannerToken),
                'tstamp' => time(),
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );

        return $this->findByUid((int)$existing['uid']);
    }


    /**
     * @return array<string, mixed>|null
     */
    public function saveScannerTokenForSiteOrDefault(string $siteIdentifier, string $scannerToken): ?array
    {
        $siteIdentifier = trim($siteIdentifier);
        if ($siteIdentifier === '') {
            return $this->saveScannerTokenForDefault($scannerToken);
        }

        $existing = $this->findBySiteIdentifier($siteIdentifier);
        if (!is_array($existing)) {
            // A Scanner Token is no Quality Gate override: the site keeps the global configuration.
            $existing = $this->saveForSiteOrDefault(
                siteIdentifier: $siteIdentifier,
                publishMode: self::PUBLISH_MODE_INHERIT,
                thresholdCritical: 0,
                thresholdWarning: -1,
                isGlobal: false,
            );
        }

        if (!is_array($existing)) {
            return null;
        }

        $this->getConnection(Tables::RULESET)->update(
            Tables::RULESET,
            [
                'scanner_token' => trim($scannerToken),
                'tstamp' => time(),
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );

        return $this->findByUid((int)$existing['uid']);
    }


    /**
     * @return array<string, mixed>|null
     */
    public function saveRemoteScanAccessForSiteOrDefault(
        string $siteIdentifier,
        string $scannerToken,
        string $httpAuthUser,
        ?string $encryptedHttpAuthPass,
        string $excludedPatterns,
        string $cookieAcceptSelectors,
        string $crawlPriorityUrls,
    ): ?array {
        $siteIdentifier = trim($siteIdentifier);
        $existing = $siteIdentifier !== ''
            ? $this->findBySiteIdentifier($siteIdentifier)
            : $this->findDefault();

        if (!is_array($existing)) {
            // Remote Scan Access settings are no Quality Gate override: a new site row keeps the global configuration.
            $existing = $this->saveForSiteOrDefault(
                siteIdentifier: $siteIdentifier,
                publishMode: $siteIdentifier !== '' ? self::PUBLISH_MODE_INHERIT : 1,
                thresholdCritical: 0,
                thresholdWarning: -1,
            );
        }

        if (!is_array($existing)) {
            return null;
        }

        $scannerToken = trim($scannerToken);
        if ($scannerToken === '') {
            $scannerToken = trim((string)($existing['scanner_token'] ?? ''));
        }

        $data = [
            'scanner_token' => $scannerToken,
            'http_auth_user' => trim($httpAuthUser),
            'excluded_patterns' => $this->normalizeJsonList($excludedPatterns),
            'cookie_accept_selectors' => $this->normalizeJsonList($cookieAcceptSelectors),
            'crawl_priority_urls' => $this->normalizeJsonList($crawlPriorityUrls),
            'tstamp' => time(),
        ];

        if ($encryptedHttpAuthPass !== null) {
            $data['http_auth_pass'] = trim($encryptedHttpAuthPass);
        }

        $this->getConnection(Tables::RULESET)->update(
            Tables::RULESET,
            $data,
            [
                'uid' => (int)$existing['uid'],
            ]
        );

        return $this->findByUid((int)$existing['uid']);
    }


    public function saveRulesJsonForDefault(string $rulesJson): ?array
    {
        $existing = $this->findOrCreateDefault();
        if (!is_array($existing)) {
            return null;
        }

        $this->getConnection(Tables::RULESET)->update(
            Tables::RULESET,
            [
                'rules_json' => trim($rulesJson),
                'tstamp' => time(),
            ],
            [
                'uid' => (int)$existing['uid'],
            ]
        );

        return $this->findByUid((int)$existing['uid']);
    }

    private function normalizeJsonList(string $value): string
    {
        $value = trim($value);
        if ($value === '') {
            return '[]';
        }

        try {
            $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable) {
            return '[]';
        }

        if (!is_array($decoded)) {
            return '[]';
        }

        $items = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string)$item),
            $decoded
        ), static fn (string $item): bool => $item !== ''));

        return json_encode($items, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
    }
}
