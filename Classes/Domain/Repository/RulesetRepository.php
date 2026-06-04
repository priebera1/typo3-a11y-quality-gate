<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Domain\Repository;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Core\Database\Connection;

final class RulesetRepository extends AbstractRepository
{
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
        if ($siteRuleset !== null) {
            return $siteRuleset;
        }

        return $globalRuleset;
    }

    /**
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
                [
                    'deleted' => 1,
                    'tstamp' => $now,
                ],
                [
                    'uid' => (int)$ruleset['uid'],
                ]
            );
        }
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
        $publishMode = max(0, min(2, $publishMode));
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
            $existing = $this->saveForSiteOrDefault(
                siteIdentifier: $siteIdentifier,
                publishMode: 1,
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
            $existing = $this->saveForSiteOrDefault(
                siteIdentifier: $siteIdentifier,
                publishMode: 1,
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
