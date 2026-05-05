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
        $siteRuleset = $this->findBySiteIdentifier($siteIdentifier);
        if ($siteRuleset !== null) {
            return $siteRuleset;
        }

        return $this->findDefault();
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
        int $thresholdWarning
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
                'crdate' => $now,
            ]
        );

        $insertedUid = (int)$connection->lastInsertId();

        if ($insertedUid <= 0) {
            return null;
        }

        return $this->findByUid($insertedUid);
    }
}