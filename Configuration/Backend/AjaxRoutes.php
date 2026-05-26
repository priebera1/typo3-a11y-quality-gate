<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Controller\IssueApiController;
use Priebera\A11yQualityGate\Controller\ProCrawlerAjaxController;
use Priebera\A11yQualityGate\Controller\ScanAjaxController;
use Priebera\A11yQualityGate\Controller\SettingsController;
use Priebera\A11yQualityGate\Controller\ToolbarScanController;

return [
    'a11y_issues' => [
        'path' => '/a11y/issues',
        'target' => IssueApiController::class . '::issuesAction',
    ],
    'a11y_rte_validate' => [
        'path' => '/a11y/rte/validate',
        'target' => IssueApiController::class . '::validateRteAction',
        'methods' => ['POST'],
    ],
    'a11y_ignore' => [
        'path' => '/a11y/ignore',
        'target' => IssueApiController::class . '::ignoreAction',
        'methods' => ['POST'],
    ],
    'a11y_scan_page' => [
        'path' => '/a11y/scan/page',
        'target' => ScanAjaxController::class . '::scanPageAction',
        'methods' => ['POST'],
        'parameters' => [
            'skipSessionUpdate' => 1,
        ],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_scan_site' => [
        'path' => '/a11y/scan/site',
        'target' => ScanAjaxController::class . '::scanSiteAction',
        'methods' => ['POST'],
        'parameters' => [
            'skipSessionUpdate' => 1,
        ],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_scan_status' => [
        'path' => '/a11y/scan/status',
        'target' => ScanAjaxController::class . '::scanStatusAction',
        'methods' => ['GET'],
        'parameters' => [
            'skipSessionUpdate' => 1,
        ],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_scan_cancel' => [
        'path' => '/a11y/scan/cancel',
        'target' => ScanAjaxController::class . '::cancelScanAction',
        'methods' => ['POST'],
        'parameters' => [
            'skipSessionUpdate' => 1,
        ],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_toolbar_render' => [
        'path' => '/a11y/toolbar/render',
        'target' => ToolbarScanController::class . '::renderMenuAction',
        'parameters' => [
            'skipSessionUpdate' => 1,
        ],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_pro_crawl_submit' => [
        'path' => '/a11y/pro/crawl/submit',
        'target' => ProCrawlerAjaxController::class . '::submitSiteAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_pro_crawl_submit_page' => [
        'path' => '/a11y/pro/crawl/submit-page',
        'target' => ProCrawlerAjaxController::class . '::submitPageAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_pro_crawl_status' => [
        'path' => '/a11y/pro/crawl/status',
        'target' => ProCrawlerAjaxController::class . '::statusAction',
        'methods' => ['GET'],
        'parameters' => [
            'skipSessionUpdate' => 1,
        ],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_pro_crawl_summary' => [
        'path' => '/a11y/pro/crawl/summary',
        'target' => ProCrawlerAjaxController::class . '::summaryAction',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_pro_crawl_cancel' => [
        'path' => '/a11y/pro/crawl/cancel',
        'target' => ProCrawlerAjaxController::class . '::cancelSiteAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],

    'a11y_regenerate_scanner_token' => [
        'path' => '/a11y/settings/regenerate-scanner-token',
        'target' => SettingsController::class . '::regenerateScannerTokenAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_test_http_auth' => [
        'path' => '/a11y/settings/test-http-auth',
        'target' => SettingsController::class . '::testHttpAuthAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_validate_licence' => [
        'path' => '/a11y/validate-licence',
        'target' => SettingsController::class . '::validateLicenceAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
];
