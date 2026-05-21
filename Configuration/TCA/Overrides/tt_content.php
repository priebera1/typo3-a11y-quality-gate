<?php

declare(strict_types=1);

defined('TYPO3') || die();

(static function (): void {
    $GLOBALS['TCA']['tt_content']['types']['html']['columnsOverrides']['bodytext']['config']['fieldWizard']['a11yPlainHtmlWizard'] = [
        'renderType' => 'a11yPlainHtmlWizard',
        'after' => [
            'defaultLanguageDifferences',
            'otherLanguageContent',
            'localizationStateSelector',
        ],
    ];
})();
