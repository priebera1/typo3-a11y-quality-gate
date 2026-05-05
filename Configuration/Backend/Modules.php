<?php

declare(strict_types=1);

return [
    'web_a11y' => [
        'parent' => 'web',
        'position' => ['after' => 'web_info'],
        'access' => 'user',
        'workspaces' => '*',
        'iconIdentifier' => 'a11y-quality-gate-module',
        'path' => '/module/web/a11y',
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