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

final class FileReferenceAltRule implements RuleInterface
{
    private const SUPPORTED_FIELDS = [
        'image',
        'assets',
        'media',
    ];

    private const SYS_FILE_TYPE_IMAGE = 2;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    public function getRuleId(): string
    {
        return 'structured.file_reference_alt';
    }

    public function getDefaultSeverity(): Severity
    {
        return Severity::Critical;
    }

    public function getMessage(): string
    {
        return 'Image file reference is missing alt text.';
    }

    public function getHint(): string
    {
        return 'Open the content element and add a description in the "Alternative text" field for each image.';
    }

    public function supports(CheckContext $context): bool
    {
        return $context->sourceTable === Tables::TT_CONTENT
            && in_array($context->sourceField, self::SUPPORTED_FIELDS, true);
    }

    /**
     * @return RuleViolation[]
     */
    public function check(CheckContext $context): array
    {
        $violations = [];

        $references = $this->fetchImageReferences(
            $context->sourceUid,
            $context->sourceField,
        );

        foreach ($references as $reference) {
            $alt = trim((string)($reference['alternative'] ?? ''));
            $title = trim((string)($reference['title'] ?? ''));
            $referenceUid = (int)($reference['uid'] ?? 0);
            $fileName = basename((string)($reference['identifier'] ?? 'unknown'));

            $contextPath = sprintf(
                '%s:%d > %s > ref:%d',
                $context->sourceTable,
                $context->sourceUid,
                $context->sourceField,
                $referenceUid
            );

            if ($alt === '' && $title === '') {
                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: Severity::Critical,
                    message: sprintf(
                        'Image "%s" has no alt text (file reference uid:%d).',
                        $fileName,
                        $referenceUid
                    ),
                    hint: $this->getHint(),
                    contextSnippet: sprintf(
                        'sys_file_reference uid:%d, file: %s',
                        $referenceUid,
                        $fileName
                    ),
                    contextPath: $contextPath,
                );

                continue;
            }

            if ($alt === '' && $title !== '') {
                $violations[] = new RuleViolation(
                    ruleId: $this->getRuleId(),
                    severity: Severity::Warning,
                    message: sprintf(
                        'Image "%s" has no alt text and only a title is set (file reference uid:%d).',
                        $fileName,
                        $referenceUid
                    ),
                    hint: 'Provide explicit alt text in the "Alternative text" field instead of relying on the title field.',
                    contextSnippet: sprintf(
                        'sys_file_reference uid:%d, title: "%s"',
                        $referenceUid,
                        $title
                    ),
                    contextPath: $contextPath,
                );
            }
        }

        return $violations;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchImageReferences(int $contentUid, string $fieldName): array
    {
        $referenceQueryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE_REFERENCE);

        $references = $referenceQueryBuilder
            ->select('uid', 'uid_local', 'alternative', 'title')
            ->from(Tables::SYS_FILE_REFERENCE)
            ->where(
                $referenceQueryBuilder->expr()->eq(
                    'uid_foreign',
                    $referenceQueryBuilder->createNamedParameter($contentUid, Connection::PARAM_INT)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'tablenames',
                    $referenceQueryBuilder->createNamedParameter(Tables::TT_CONTENT)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'fieldname',
                    $referenceQueryBuilder->createNamedParameter($fieldName)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'hidden',
                    $referenceQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                ),
                $referenceQueryBuilder->expr()->eq(
                    'deleted',
                    $referenceQueryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            )
            ->orderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        if ($references === []) {
            return [];
        }

        $fileUids = [];
        foreach ($references as $reference) {
            $uidLocal = (int)($reference['uid_local'] ?? 0);
            if ($uidLocal > 0) {
                $fileUids[] = $uidLocal;
            }
        }

        $fileUids = array_values(array_unique($fileUids));

        if ($fileUids === []) {
            return [];
        }

        $fileQueryBuilder = $this->connectionPool->getQueryBuilderForTable(Tables::SYS_FILE);

        $fileRows = $fileQueryBuilder
            ->select('uid', 'identifier', 'type')
            ->from(Tables::SYS_FILE)
            ->where(
                $fileQueryBuilder->expr()->in(
                    'uid',
                    $fileQueryBuilder->createNamedParameter($fileUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $filesByUid = [];
        foreach ($fileRows as $fileRow) {
            $filesByUid[(int)$fileRow['uid']] = [
                'identifier' => (string)($fileRow['identifier'] ?? ''),
                'type' => (int)($fileRow['type'] ?? 0),
            ];
        }

        $imageReferences = [];
        foreach ($references as $reference) {
            $uidLocal = (int)($reference['uid_local'] ?? 0);
            $file = $filesByUid[$uidLocal] ?? null;

            if ($file === null || $file['type'] !== self::SYS_FILE_TYPE_IMAGE) {
                continue;
            }

            $reference['identifier'] = $file['identifier'];
            $imageReferences[] = $reference;
        }

        return $imageReferences;
    }
}
