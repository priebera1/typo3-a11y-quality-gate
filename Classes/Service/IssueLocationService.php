<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Service;

use Priebera\A11yQualityGate\Utility\BackendLabelUtility;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\View\BackendLayoutView;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Where a content finding is, in the words an editor uses in the page module: the record's title, its content type and
 * column, and the field label. Read-only display data for the Content page view; the stored table, uid and field stay
 * authoritative and remain visible in the technical details.
 */
final class IssueLocationService
{
    private const RENDERED_HTML_FIELD = '__rendered_html';

    /** @var array<string, array<string, mixed>|null> */
    private array $records = [];

    /** @var array<int, array<int|string, string>> */
    private array $columnLabels = [];

    /**
     * @return array{
     *   title: string,
     *   context: string,
     *   fieldLabel: string,
     *   isContentElement: bool,
     *   isPageLevel: bool,
     *   exists: bool
     * }
     */
    public function describe(string $table, int $uid, string $field): array
    {
        $fieldLabel = $this->fieldLabel($table, $field);

        if ($table === 'pages') {
            return [
                'title' => BackendLabelUtility::translate('pageDetail.location.outsideContent', 'Outside content elements'),
                'context' => BackendLabelUtility::translate('pageDetail.sourceHint.rendered', 'Likely: template, layout or plugin output'),
                'fieldLabel' => $fieldLabel,
                'isContentElement' => false,
                'isPageLevel' => true,
                'exists' => true,
            ];
        }

        $record = $uid > 0 && $table !== '' ? $this->record($table, $uid) : null;

        if ($table === 'tt_content') {
            $header = trim((string)($record['header'] ?? ''));
            $typeLabel = $record !== null ? $this->contentTypeLabel($record) : '';
            $title = $header !== ''
                ? $header
                : sprintf(
                    BackendLabelUtility::translate('pageDetail.contentElement', 'Content element #%s'),
                    (string)$uid
                );
            $context = array_filter([
                $typeLabel,
                $record !== null ? $this->columnLabel($record) : '',
                $header === '' && $record !== null
                    ? BackendLabelUtility::translate('pageDetail.location.noHeader', 'no header')
                    : '',
            ], static fn(string $part): bool => $part !== '');

            return [
                'title' => $title,
                'context' => implode(' · ', $context),
                'fieldLabel' => $fieldLabel,
                'isContentElement' => true,
                'isPageLevel' => false,
                'exists' => $record !== null,
            ];
        }

        $recordTitle = $record !== null ? trim(strip_tags((string)BackendUtility::getRecordTitle($table, $record, false, false))) : '';
        $tableLabel = $this->tableLabel($table);

        return [
            'title' => $recordTitle !== '' ? $recordTitle : trim($tableLabel . ' #' . $uid),
            'context' => $recordTitle !== '' ? $tableLabel : '',
            'fieldLabel' => $fieldLabel,
            'isContentElement' => false,
            'isPageLevel' => false,
            'exists' => $record !== null,
        ];
    }

    private function fieldLabel(string $table, string $field): string
    {
        if ($field === self::RENDERED_HTML_FIELD) {
            return BackendLabelUtility::translate('pageDetail.location.renderedHtml', 'Rendered page HTML');
        }

        $label = (string)($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? '');
        $translated = $label !== '' ? $this->sL($label) : '';

        return $translated !== '' ? $translated : $field;
    }

    private function tableLabel(string $table): string
    {
        $label = (string)($GLOBALS['TCA'][$table]['ctrl']['title'] ?? '');
        $translated = $label !== '' ? $this->sL($label) : '';

        return $translated !== '' ? $translated : $table;
    }

    /**
     * @param array<string, mixed> $record
     */
    private function contentTypeLabel(array $record): string
    {
        $cType = (string)($record['CType'] ?? '');
        if ($cType === '') {
            return '';
        }

        try {
            $label = BackendUtility::getProcessedValue(
                'tt_content',
                'CType',
                $cType,
                0,
                false,
                false,
                (int)($record['uid'] ?? 0),
                true,
                (int)($record['pid'] ?? 0),
                $record
            );
        } catch (\Throwable) {
            return '';
        }

        $label = trim((string)$label);

        return $label !== $cType ? $label : '';
    }

    /**
     * The column name of the page's backend layout. Unknown when the page has no layout or the column is not in it.
     *
     * @param array<string, mixed> $record
     */
    private function columnLabel(array $record): string
    {
        $pid = (int)($record['pid'] ?? 0);
        if ($pid <= 0 || !isset($record['colPos'])) {
            return '';
        }

        if (!array_key_exists($pid, $this->columnLabels)) {
            $columns = [];
            try {
                $layout = GeneralUtility::makeInstance(BackendLayoutView::class)->getBackendLayoutForPage($pid);
                $columns = $layout !== null ? $layout->getUsedColumns() : [];
            } catch (\Throwable) {
                $columns = [];
            }
            $this->columnLabels[$pid] = is_array($columns) ? $columns : [];
        }

        $label = (string)($this->columnLabels[$pid][(int)$record['colPos']] ?? '');

        return $label !== '' ? $this->sL($label) : '';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function record(string $table, int $uid): ?array
    {
        $key = $table . ':' . $uid;
        if (!array_key_exists($key, $this->records)) {
            if (!isset($GLOBALS['TCA'][$table])) {
                $this->records[$key] = null;
            } else {
                try {
                    $record = BackendUtility::getRecord($table, $uid);
                } catch (\Throwable) {
                    $record = null;
                }
                $this->records[$key] = is_array($record) ? $record : null;
            }
        }

        return $this->records[$key];
    }

    private function sL(string $label): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return str_starts_with($label, 'LLL:') ? '' : $label;
        }

        return trim($languageService->sL($label));
    }
}
