<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Rule\Structured;

use Priebera\A11yQualityGate\Database\Tables;
use Priebera\A11yQualityGate\Domain\Enum\Severity;
use Priebera\A11yQualityGate\Rule\CheckContext;
use Priebera\A11yQualityGate\Rule\RuleInterface;
use Priebera\A11yQualityGate\Rule\RuleViolation;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

final class UploadsFileMissingDescriptionRule implements RuleInterface
{
    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.uploads_file_missing_description';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Warning;
    }

    public function getMessage(): string
    {
        return 'Uploaded file link is missing a description.';
    }

    public function getHint(): string
    {
        return 'Add a meaningful file description instead of relying only on the file name.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && $context->cType === 'uploads'
            && in_array($context->sourceField, ['media', 'assets'], true);
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $references = $this->fetchFileReferences($context->sourceUid, $context->sourceField);
        $violations = [];

        foreach ($references as $reference) {
            $description = trim((string)($reference['description'] ?? ''));
            $uid = (int)($reference['uid'] ?? 0);
            $fileName = basename((string)($reference['identifier'] ?? 'unknown'));

            if ($description !== '') {
                continue;
            }

            $violations[] = new RuleViolation(
                ruleId: $this->getRuleId(),
                severity: $this->getDefaultSeverity(),
                message: sprintf('Uploaded file "%s" has no description (file reference uid:%d).', $fileName, $uid),
                hint: $this->getHint(),
                contextSnippet: sprintf('sys_file_reference uid:%d, file: %s', $uid, $fileName),
                contextPath: sprintf(
                    '%s:%d > %s > ref:%d',
                    $context->sourceTable,
                    $context->sourceUid,
                    $context->sourceField,
                    $uid
                ),
            );
        }

        return $violations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchFileReferences(int $contentUid, string $fieldName): array
    {
        $qb = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE_REFERENCE);

        $references = $qb
            ->select('uid', 'uid_local', 'description')
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where(
                $qb->expr()->eq(
                    'uid_foreign',
                    $qb->createNamedParameter($contentUid, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'tablenames',
                    $qb->createNamedParameter(Tables::TT_CONTENT)
                ),
                $qb->expr()->eq(
                    'fieldname',
                    $qb->createNamedParameter($fieldName)
                ),
                $qb->expr()->eq(
                    'hidden',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $qb->expr()->eq(
                    'deleted',
                    $qb->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($references === []) {
            return [];
        }

        $fileUids = array_values(array_unique(array_map(
            static fn(array $reference): int => (int)($reference['uid_local'] ?? 0),
            $references
        )));

        $fileUids = array_filter($fileUids, static fn(int $uid): bool => $uid > 0);

        if ($fileUids === []) {
            return $references;
        }

        $fileQb = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE);

        $fileRows = $fileQb
            ->select('uid', 'identifier')
            ->from(Tables::SYS_FILE)
            ->where(
                $fileQb->expr()->in(
                    'uid',
                    $fileQb->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $identifiersByUid = [];
        foreach ($fileRows as $fileRow) {
            $identifiersByUid[(int)$fileRow['uid']] = (string)($fileRow['identifier'] ?? '');
        }

        foreach ($references as &$reference) {
            $uidLocal = (int)($reference['uid_local'] ?? 0);
            $reference['identifier'] = $identifiersByUid[$uidLocal] ?? '';
        }
        unset($reference);

        return $references;
    }
}
