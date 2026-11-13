<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Scan;

use Priebera\A11yQualityGate\Rule\RuleViolation;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * N-02 (final customer acceptance): in a translated page the frontend renders the language overlay of a content
 * element, but AQG's record marker ({field:uid}) carries the default record's uid. A rendered finding of a language
 * above 0 is moved to that language's own visible translation record, so it is deduplicated against the translation's
 * rich-text findings (RenderedRichTextDuplicates), counted once in My issues and opens the record the editor edits.
 *
 * Records without a visible translation in that language (free-mode records, hidden translations with fallback to the
 * default content) keep the record that was rendered. Only tt_content markers are mapped; page-level findings stay.
 */
final class RenderedTranslationRecords
{
    private const TABLE = 'tt_content';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    /**
     * @param list<RuleViolation> $violations
     * @return list<RuleViolation>
     */
    public function forLanguage(array $violations, int $languageUid): array
    {
        if ($languageUid <= 0 || $violations === []) {
            return $violations;
        }
        $parents = [];
        foreach ($violations as $violation) {
            if ($violation->sourceTable === self::TABLE && $violation->sourceUid > 0) {
                $parents[$violation->sourceUid] = true;
            }
        }
        if ($parents === []) {
            return $violations;
        }
        $translations = $this->translations(array_keys($parents), $languageUid);
        if ($translations === []) {
            return $violations;
        }

        return array_map(static function (RuleViolation $violation) use ($translations): RuleViolation {
            $translationUid = $violation->sourceTable === self::TABLE ? ($translations[$violation->sourceUid] ?? 0) : 0;
            if ($translationUid <= 0) {
                return $violation;
            }

            return new RuleViolation(
                ruleId: $violation->ruleId,
                severity: $violation->severity,
                message: $violation->message,
                hint: $violation->hint,
                contextSnippet: $violation->contextSnippet,
                contextPath: $violation->contextPath,
                sourceType: $violation->sourceType,
                frontendUrl: $violation->frontendUrl,
                cssSelector: $violation->cssSelector,
                sourceTable: $violation->sourceTable,
                sourceUid: $translationUid,
                sourceField: $violation->sourceField,
            );
        }, $violations);
    }

    /**
     * @param list<int> $parentUids
     * @return array<int, int> default uid => live, visible translation uid in the language
     */
    private function translations(array $parentUids, int $languageUid): array
    {
        // tt_content names its pointer l18n_parent; read it from TCA like the core does.
        $parentField = (string)($GLOBALS['TCA'][self::TABLE]['ctrl']['transOrigPointerField'] ?? 'l18n_parent');
        $qb = $this->connectionPool->getQueryBuilderForTable(self::TABLE);
        $qb->getRestrictions()->removeAll();
        $rows = $qb->select('uid', $parentField)
            ->from(self::TABLE)
            ->where(
                $qb->expr()->in($parentField, $qb->createNamedParameter($parentUids, Connection::PARAM_INT_ARRAY)),
                $qb->expr()->eq('sys_language_uid', $qb->createNamedParameter($languageUid, Connection::PARAM_INT)),
                $qb->expr()->eq('deleted', 0),
                $qb->expr()->eq('hidden', 0),
                $qb->expr()->eq('t3ver_wsid', 0),
            )
            ->orderBy('uid')
            ->executeQuery()
            ->fetchAllAssociative();
        $map = [];
        foreach ($rows as $row) {
            $map[(int)$row[$parentField]] ??= (int)$row['uid'];
        }

        return $map;
    }
}
