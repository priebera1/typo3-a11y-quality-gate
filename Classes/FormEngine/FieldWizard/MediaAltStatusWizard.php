<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\FormEngine\FieldWizard;

use Priebera\A11yQualityGate\Database\Tables;
use TYPO3\CMS\Backend\Form\AbstractNode;
use TYPO3\CMS\Core\Page\JavaScriptModuleInstruction;

/**
 * Loads media-alt-status.js for the alternative text of an image use in a content element, with or without AI: it
 * keeps the ALT status chip on the image's header (MediaAltStatusChipListener, which carries the binding) current
 * while the form is edited. No HTML (FormEngine then renders no wizard row), no request, nothing written. FormEngine
 * also delivers the module with images added or expanded later by AJAX, which a PageRenderer call would miss.
 */
final class MediaAltStatusWizard extends AbstractNode
{
    private const IMAGE_FILE_TYPE = 2;

    public function render(): array
    {
        $result = $this->initializeResultArray();
        $record = is_array($this->data['databaseRow'] ?? null) ? $this->data['databaseRow'] : [];
        $file = $record['uid_local'][0]['row'] ?? null;
        if (($this->data['tableName'] ?? '') !== Tables::SYS_FILE_REFERENCE
            || ($this->data['fieldName'] ?? '') !== 'alternative'
            || ($this->data['inlineParentTableName'] ?? '') !== Tables::TT_CONTENT
            || !isset($GLOBALS['TCA'][Tables::SYS_FILE_REFERENCE]['columns']['tx_a11y_is_decorative'])
            || !is_array($file)
            || (int)($file['type'] ?? 0) !== self::IMAGE_FILE_TYPE) {
            return $result;
        }

        $result['javaScriptModules'][] = JavaScriptModuleInstruction::create('@priebera/a11y-quality-gate/media-ai/media-alt-status.js');

        return $result;
    }
}
