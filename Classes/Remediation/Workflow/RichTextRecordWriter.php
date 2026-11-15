<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Remediation\Workflow;

use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerAwareTrait;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores the reviewed rich-text field value through TYPO3's DataHandler, as the reviewer (AQG 3.0.0 direct apply).
 *
 * - Optimistic concurrency: the field must still hold exactly the value the change was computed from; any other save
 *   in between is a conflict and nothing is written. The live record row is locked while this is checked and written
 *   (also in a workspace, where the version is written: every direct apply of the record locks the same live row),
 *   so a second direct apply or a concurrent live TYPO3 save waits.
 * - TYPO3 decides: table, field, page, language and workspace permissions, record locking and workspace versioning
 *   are DataHandler's. In a workspace the change goes to the workspace version and is published only with it.
 * - Transformations are not run (dontProcessTransformations): the value is the stored field with one element changed
 *   (RichTextElementWriter); running the RTE transformation again would reformat the rest of the field.
 * - The result is read back: only the exact value counts as saved. Anything else rolls the transaction back, so a
 *   save that TYPO3 or a hook changed is never left in the record while the item says nothing was written.
 */
class RichTextRecordWriter implements LoggerAwareInterface
{
    use LoggerAwareTrait;

    public const SAVED = 'saved';
    public const ALREADY_SAVED = 'already_saved';
    public const CONFLICT = 'conflict';
    public const TARGET_MISSING = 'target_missing';
    public const PERMISSION_DENIED = 'permission_denied';
    public const WRITE_FAILED = 'write_failed';

    public function __construct(private readonly ConnectionPool $connectionPool)
    {
    }

    public function write(string $table, int $uid, string $field, string $expected, string $value): string
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if (!$backendUser instanceof BackendUserAuthentication || $uid <= 0 || !isset($GLOBALS['TCA'][$table]['columns'][$field])) {
            return self::PERMISSION_DENIED;
        }
        $connection = $this->connectionPool->getConnectionForTable($table);

        try {
            return $connection->transactional(fn (Connection $connection): string => $this->lockedWrite($connection, $backendUser, $table, $uid, $field, $expected, $value));
        } catch (RichTextWriteRolledBack $rolledBack) {
            $this->logger?->warning('AQG direct apply rolled back: the stored value is not the reviewed value.', [
                'table' => $table, 'uid' => $uid, 'field' => $field, 'outcome' => $rolledBack->outcome, 'dataHandlerErrors' => $rolledBack->errors,
            ]);

            return $rolledBack->outcome;
        }
    }

    private function lockedWrite(Connection $connection, BackendUserAuthentication $backendUser, string $table, int $uid, string $field, string $expected, string $value): string
    {
        $lock = str_contains(strtolower($connection->getDatabasePlatform()::class), 'sqlite') ? '' : ' FOR UPDATE';
        $connection->executeQuery(
            'SELECT ' . $connection->quoteIdentifier('uid') . ' FROM ' . $connection->quoteIdentifier($table)
                . ' WHERE ' . $connection->quoteIdentifier('uid') . ' = ?' . $lock,
            [$uid],
            [Connection::PARAM_INT],
        );
        $current = $this->storedValue($table, $uid, $field);
        if ($current === null) {
            return self::TARGET_MISSING;
        }
        if ($current !== $expected) {
            // The exact reviewed value is there already (a retry after an interrupted write): nothing to repeat.
            return $current === $value ? self::ALREADY_SAVED : self::CONFLICT;
        }

        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->dontProcessTransformations = true;
        $dataHandler->start([$table => [$uid => [$field => $value]]], [], $backendUser);
        $dataHandler->process_datamap();

        if ($this->storedValue($table, $uid, $field) === $value) {
            return self::SAVED;
        }

        throw new RichTextWriteRolledBack(
            $dataHandler->errorLog !== [] ? self::PERMISSION_DENIED : self::WRITE_FAILED,
            array_map('strval', array_slice($dataHandler->errorLog, 0, 5)),
        );
    }

    /** The field as the current user's workspace shows it (null: record missing or deleted). */
    public function storedValue(string $table, int $uid, string $field): ?string
    {
        $record = BackendUtility::getRecordWSOL($table, $uid);

        return is_array($record) && array_key_exists($field, $record) ? (string)$record[$field] : null;
    }
}
