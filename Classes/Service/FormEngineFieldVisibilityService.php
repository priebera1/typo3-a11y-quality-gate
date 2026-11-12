<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Database\Tables;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Backend\Form\FormDataCompiler;
use TYPO3\CMS\Backend\Form\FormDataGroup\TcaDatabaseRecord;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Core\SystemEnvironmentBuilder;
use TYPO3\CMS\Core\Http\NormalizedParams;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Localization\LanguageServiceFactory;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Whether TYPO3's own editing form shows a field of a stored record as editable, decided by TYPO3's FormEngine data
 * pipeline (`FormDataCompiler` with the `TcaDatabaseRecord` group) instead of a re-implementation: record type and
 * `columnsOverrides`, the form layout (`showitem`, palettes), **`displayCond`** (FIELD, REC, VERSION, USER, HIDE_FOR_NON_ADMINS
 * … against the current record values), page/user TSconfig the pipeline merges, and the record permission check.
 *
 * A file reference is evaluated the way FormEngine shows it: as an inline child of its parent record's file field, so
 * the parent field's own `displayCond`, its `overrideChildTca` and its read-only state apply to the reference.
 *
 * Fails closed: a record, parent or reference the pipeline cannot compile (missing, refused, invalid configuration, an
 * exception in a condition) counts as not editable.
 */
final class FormEngineFieldVisibilityService
{
    public function isFieldEditable(BackendUserAuthentication $backendUser, string $table, int $uid, string $field): bool
    {
        if ($table === '' || $uid <= 0 || $field === '') {
            return false;
        }

        if ($table === Tables::SYS_FILE_REFERENCE) {
            return $this->isReferenceFieldEditable($backendUser, $uid, $field);
        }

        $compiled = $this->compile($backendUser, $table, $uid);

        return $compiled !== null && self::columnIsEditable($compiled, $field);
    }

    private function isReferenceFieldEditable(BackendUserAuthentication $backendUser, int $referenceUid, string $field): bool
    {
        $reference = \TYPO3\CMS\Backend\Utility\BackendUtility::getRecord(Tables::SYS_FILE_REFERENCE, $referenceUid, 'uid,tablenames,uid_foreign,fieldname');
        $parentTable = (string)($reference['tablenames'] ?? '');
        $parentUid = (int)($reference['uid_foreign'] ?? 0);
        $parentField = (string)($reference['fieldname'] ?? '');
        if ($parentTable === '' || $parentUid <= 0 || $parentField === '') {
            return false;
        }

        $parent = $this->compile($backendUser, $parentTable, $parentUid);
        // The parent's file field must itself be in the form (layout, displayCond) for its references to be.
        if ($parent === null || !is_array($parent['processedTca']['columns'][$parentField] ?? null)) {
            return false;
        }
        $listed = false;
        foreach ($parent['processedTca']['columns'][$parentField]['children'] ?? [] as $child) {
            if (is_array($child) && (int)($child['databaseRow']['uid'] ?? $child['vanillaUid'] ?? 0) === $referenceUid) {
                $listed = true;
                break;
            }
        }
        // The form does not list this reference (another workspace, another field, hidden): not editable there.
        if (!$listed) {
            return false;
        }

        // A listed child is compiled collapsed (label fields only). Compile it the way FormEngine does when the editor
        // opens it (inline "details" request): as the expanded inline child of exactly this parent field, so the
        // parent's overrideChildTca, read-only state and the child's own displayCond apply.
        $topMost = $parent['inlineStructure']['stable'][0] ?? [];
        $input = [
            'command' => 'edit',
            'tableName' => Tables::SYS_FILE_REFERENCE,
            'vanillaUid' => $referenceUid,
            'returnUrl' => $parent['returnUrl'] ?? '',
            'isInlineChild' => true,
            'isInlineAjaxOpeningContext' => true,
            'inlineStructure' => $parent['inlineStructure'] ?? [],
            'inlineExpandCollapseStateArray' => $parent['inlineExpandCollapseStateArray'] ?? [],
            'inlineFirstPid' => $parent['inlineFirstPid'] ?? null,
            'inlineParentConfig' => $parent['processedTca']['columns'][$parentField]['config'] ?? [],
            'inlineParentUid' => $parent['databaseRow']['uid'] ?? $parentUid,
            'inlineParentTableName' => $parentTable,
            'inlineParentFieldName' => $parentField,
            'inlineTopMostParentUid' => ($parent['inlineTopMostParentUid'] ?? '') ?: ($topMost['uid'] ?? $parentUid),
            'inlineTopMostParentTableName' => ($parent['inlineTopMostParentTableName'] ?? '') ?: ($topMost['table'] ?? $parentTable),
            'inlineTopMostParentFieldName' => ($parent['inlineTopMostParentFieldName'] ?? '') ?: ($topMost['field'] ?? $parentField),
        ];
        // TYPO3 14 passes its immutable schema data on to children; TYPO3 13 has no such keys.
        foreach (['tcaSchemata', 'fullTca'] as $immutable) {
            if (array_key_exists($immutable, $parent)) {
                $input[$immutable] = $parent[$immutable];
            }
        }
        $compiledReference = $this->compileInput($backendUser, $input);

        return $compiledReference !== null && self::columnIsEditable($compiledReference, $field);
    }

    /** @param array<string, mixed> $compiled */
    private static function columnIsEditable(array $compiled, string $field): bool
    {
        $column = $compiled['processedTca']['columns'][$field] ?? null;

        return is_array($column) && !(bool)($column['config']['readOnly'] ?? false);
    }

    /** @return array<string, mixed>|null */
    private function compile(BackendUserAuthentication $backendUser, string $table, int $uid): ?array
    {
        if (!isset($GLOBALS['TCA'][$table])) {
            return null;
        }

        return $this->compileInput($backendUser, ['tableName' => $table, 'vanillaUid' => $uid, 'command' => 'edit']);
    }

    /**
     * @param array<string, mixed> $input FormDataCompiler input without the request
     * @return array<string, mixed>|null
     */
    private function compileInput(BackendUserAuthentication $backendUser, array $input): ?array
    {
        $table = (string)($input['tableName'] ?? '');
        $uid = (int)($input['vanillaUid'] ?? 0);
        // Not cached: TSconfig, TCA overrides and record values that conditions read may change between two checks.
        $request = $this->backendRequest();

        $previousUser = $GLOBALS['BE_USER'] ?? null;
        $previousLanguage = $GLOBALS['LANG'] ?? null;
        $result = null;
        try {
            // The pipeline reads the acting user and needs a language service for labels (absent on the CLI).
            $GLOBALS['BE_USER'] = $backendUser;
            if (!($GLOBALS['LANG'] ?? null) instanceof LanguageService) {
                $GLOBALS['LANG'] = GeneralUtility::makeInstance(LanguageServiceFactory::class)->createFromUserPreferences($backendUser);
            }
            $result = GeneralUtility::makeInstance(FormDataCompiler::class)->compile(
                ['request' => $request] + $input,
                GeneralUtility::makeInstance(TcaDatabaseRecord::class),
            );
        } catch (\Throwable $exception) {
            $this->logger()->info('AQG: FormEngine field visibility could not be evaluated; treated as not editable', [
                'table' => $table,
                'uid' => $uid,
                'exception' => get_class($exception),
                'code' => $exception->getCode(),
                'message' => mb_substr($exception->getMessage(), 0, 300),
            ]);
            $result = null;
        } finally {
            $GLOBALS['BE_USER'] = $previousUser;
            if ($previousLanguage === null) {
                unset($GLOBALS['LANG']);
            } else {
                $GLOBALS['LANG'] = $previousLanguage;
            }
        }

        return $result;
    }

    private function backendRequest(): ServerRequestInterface
    {
        $current = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($current instanceof ServerRequestInterface) {
            $request = $current;
        } else {
            $request = new ServerRequest('https://localhost/typo3/', 'GET');
            $request = $request->withAttribute('normalizedParams', NormalizedParams::createFromRequest($request));
        }

        return $request->withAttribute('applicationType', SystemEnvironmentBuilder::REQUESTTYPE_BE);
    }

    private function logger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(LogManager::class)->getLogger(self::class);
    }
}
