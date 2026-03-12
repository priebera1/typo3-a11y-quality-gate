<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\QualityGate;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Repository\IssueRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class QualityGateChecker
{
    public function __construct(
        private readonly IssueRepository $issueRepository,
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function check(int $pageUid, string $siteIdentifier): QualityGateVerdict
    {
        $ruleset = $this->findOrCreateRuleset($siteIdentifier);

        if ($ruleset === null) {
            return QualityGateVerdict::pass();
        }

        $publishMode = (int)($ruleset['publish_mode'] ?? 0);

        if ($publishMode === 0) {
            return QualityGateVerdict::pass();
        }

        $counts = $this->issueRepository->countOpenBySeverity($pageUid, $siteIdentifier);
        $thresholdCritical = (int)($ruleset['threshold_critical'] ?? 0);
        $thresholdWarning = (int)($ruleset['threshold_warning'] ?? 0);

        $triggered = false;
        $reasons = [];

        if ($counts['critical'] > $thresholdCritical) {
            $triggered = true;
            $reasons[] = sprintf('%d critical issue(s)', $counts['critical']);
        }

        if ($thresholdWarning >= 0 && $counts['warning'] > $thresholdWarning) {
            $triggered = true;
            $reasons[] = sprintf('%d warning(s) (threshold: %d)', $counts['warning'], $thresholdWarning);
        }

        if (!$triggered) {
            return QualityGateVerdict::pass();
        }

        return QualityGateVerdict::fail(
            mode: $publishMode,
            counts: $counts,
            reasons: $reasons,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findOrCreateRuleset(string $siteIdentifier): ?array
    {
        $ruleset = $this->findRuleset($siteIdentifier);

        if ($ruleset !== null) {
            return $ruleset;
        }

        try {
            return $this->createDefaultRuleset();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findRuleset(string $siteIdentifier): ?array
    {
        if ($siteIdentifier !== '') {
            $qb = $this->connectionPool->getQueryBuilderForTable(Tables::RULESET);

            $row = $qb
                ->select('*')
                ->from(Tables::RULESET)
                ->where(
                    $qb->expr()->eq(
                        'site_identifier',
                        $qb->createNamedParameter($siteIdentifier)
                    )
                )
                ->orderBy('uid', 'DESC')
                ->setMaxResults(1)
                ->executeQuery()
                ->fetchAssociative();

            if ($row !== false) {
                return $row;
            }
        }

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::RULESET);

        $row = $qb
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $qb->expr()->eq(
                    'is_default',
                    $qb->createNamedParameter(1, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function createDefaultRuleset(): ?array
    {
        $existing = $this->findRuleset('');
        if ($existing !== null) {
            return $existing;
        }

        $connection = $this->connectionPool->getConnectionForTable(Tables::RULESET);
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

        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::RULESET);

        $row = $qb
            ->select('*')
            ->from(Tables::RULESET)
            ->where(
                $qb->expr()->eq(
                    'uid',
                    $qb->createNamedParameter($insertedUid, Connection::PARAM_INT)
                )
            )
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchAssociative();

        return $row !== false ? $row : null;
    }
}
