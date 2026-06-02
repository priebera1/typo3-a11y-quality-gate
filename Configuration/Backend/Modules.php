<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Information\Typo3Version;

$typo3MajorVersion = (new Typo3Version())->getMajorVersion();
$mainModule = $typo3MajorVersion >= 14 ? 'content' : 'web';
$statusModule = $typo3MajorVersion >= 14 ? 'content_status' : 'web_info';
$modulePath = $typo3MajorVersion >= 14 ? '/module/content/a11y' : '/module/web/a11y';

// Keep the route identifier stable across TYPO3 13 and 14. The parent module/path
// changes from Web to Content in TYPO3 14, but controllers, Ajax routes and toolbar
// links keep using web_a11y / web_a11y.* as the backend route key.
$moduleIdentifier = 'web_a11y';

return [
    $moduleIdentifier => [
        'parent' => $mainModule,
        'position' => ['after' => $statusModule],
        'access' => 'user',
        'workspaces' => '*',
        'iconIdentifier' => 'a11y-quality-gate-module',
        'path' => $modulePath,
        'labels' => 'LLL:EXT:a11y_quality_gate/Resources/Private/Language/locallang_mod.xlf',
        'redirect' => [
            'enable' => true,
            'parameters' => [
                'id' => true,
                'pageUid' => true,
                'site' => true,
                'status' => true,
                'severity' => true,
                'page' => true,
                'remotePageUid' => true,
                'language' => true,
                'languageUid' => true,
                'localPage' => true,
                'remotePage' => true,
                'remoteFailedPage' => true,
                'localQuery' => true,
                'remoteQuery' => true,
                'remoteFailedQuery' => true,
                'tab' => true,
                'rulesetSite' => true,
            ],
        ],
        'routes' => [
            '_default' => [
                'target' => \Priebera\A11yQualityGate\Controller\OverviewController::class . '::indexAction',
            ],
            'pageDetail' => [
                'path' => '/page',
                'target' => \Priebera\A11yQualityGate\Controller\PageDetailController::class . '::showAction',
            ],
            'remotePageDetail' => [
                'path' => '/remote-page',
                'target' => \Priebera\A11yQualityGate\Controller\RemotePageDetailController::class . '::showAction',
            ],
            'settings' => [
                'path' => '/settings',
                'target' => \Priebera\A11yQualityGate\Controller\SettingsController::class . '::indexAction',
            ],
            'settingsSave' => [
                'path' => '/settings/save',
                'target' => \Priebera\A11yQualityGate\Controller\SettingsController::class . '::saveAction',
                'methods' => ['POST'],
            ],
            'settingsSaveExtConf' => [
                'path' => '/settings/save-ext-conf',
                'target' => \Priebera\A11yQualityGate\Controller\SettingsController::class . '::saveExtConfAction',
                'methods' => ['POST'],
            ],
            'settingsRefresh' => [
                'path' => '/settings/refresh',
                'target' => \Priebera\A11yQualityGate\Controller\SettingsController::class . '::refreshAction',
                'methods' => ['POST'],
            ],
            'ignore' => [
                'path' => '/ignore',
                'target' => \Priebera\A11yQualityGate\Controller\PageDetailController::class . '::ignoreAction',
                'methods' => ['POST'],
            ],
            'batchIgnore' => [
                'path' => '/batch-ignore',
                'target' => \Priebera\A11yQualityGate\Controller\PageDetailController::class . '::batchIgnoreAction',
                'methods' => ['POST'],
            ],
            'ignoreRuleOnPage' => [
                'path' => '/ignore-rule-on-page',
                'target' => \Priebera\A11yQualityGate\Controller\PageDetailController::class . '::ignoreRuleOnPageAction',
                'methods' => ['POST'],
            ],
            'ignoreRuleOnSite' => [
                'path' => '/ignore-rule-on-site',
                'target' => \Priebera\A11yQualityGate\Controller\PageDetailController::class . '::ignoreRuleOnSiteAction',
                'methods' => ['POST'],
            ],
            'unignore' => [
                'path' => '/unignore',
                'target' => \Priebera\A11yQualityGate\Controller\PageDetailController::class . '::unignoreAction',
                'methods' => ['POST'],
            ],
            'exportCsv' => [
                'path' => '/export/csv',
                'target' => \Priebera\A11yQualityGate\Controller\ExportController::class . '::csvAction',
            ],
            'exportPdf' => [
                'path' => '/export/pdf',
                'target' => \Priebera\A11yQualityGate\Controller\ExportController::class . '::pdfAction',
            ],
            'remoteScreenshot' => [
                'path' => '/remote-screenshot',
                'target' => \Priebera\A11yQualityGate\Controller\RemoteScreenshotController::class . '::showAction',
            ],
        ],
    ],
];
