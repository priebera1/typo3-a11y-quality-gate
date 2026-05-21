<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FormEngine\FieldWizard;

use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\StringUtility;

final class PlainHtmlA11yWizard extends AbstractNode
{
    public function render(): array
    {
        $result = $this->initializeResultArray();
        $table = (string)($this->data['tableName'] ?? '');
        $field = (string)($this->data['fieldName'] ?? '');
        $record = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        $cType = (string)$this->resolveFormValue($record['CType'] ?? '');
        $uid = (int)$this->resolveFormValue($record['uid'] ?? 0);

        if ($table !== 'tt_content' || $field !== 'bodytext' || $cType !== 'html' || $uid <= 0) {
            return $result;
        }

        $parameterArray = is_array($this->data['parameterArray'] ?? null) ? $this->data['parameterArray'] : [];
        $inputName = (string)$this->resolveFormValue($parameterArray['itemFormElName'] ?? '');

        if ($inputName === '') {
            return $result;
        }

        $containerId = StringUtility::getUniqueId('aqg-plain-html-a11y-');
        $attributes = [
            'id' => $containerId,
            'class' => 'aqg-plain-html-a11y js-aqg-plain-html-a11y',
            'data-record-uid' => (string)$uid,
            'data-field-name' => $field,
            'data-input-name' => $inputName,
        ];

        $result['html'] = implode(LF, [
            '<div ' . GeneralUtility::implodeAttributes($attributes, true) . '>',
            '    <div class="ck-a11y-summary ck-a11y-summary--outside ck-a11y-summary--loading" role="status" aria-live="polite">',
            '        <span class="ck-a11y-summary__left">',
            '            <span class="ck-a11y-summary__spin" aria-hidden="true"></span>',
            '            <span class="ck-a11y-summary__title">Checking HTML accessibility…</span>',
            '            <span class="ck-a11y-summary__help">Live validation for this HTML element.</span>',
            '        </span>',
            '    </div>',
            '    <div class="aqg-plain-html-a11y__issues" aria-live="polite"></div>',
            '</div>',
        ]);

        $result['javaScriptModules'][] = JavaScriptModuleInstruction::create('@priebera/a11y-quality-gate/plain-html-a11y.js');
        $result['stylesheetFiles'][] = 'EXT:a11y_quality_gate/Resources/Public/Css/ckeditor.css';

        return $result;
    }

    private function resolveFormValue(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        if (array_key_exists(0, $value)) {
            return $this->resolveFormValue($value[0]);
        }

        $firstValue = reset($value);
        if ($firstValue === false) {
            return '';
        }

        return $this->resolveFormValue($firstValue);
    }
}
