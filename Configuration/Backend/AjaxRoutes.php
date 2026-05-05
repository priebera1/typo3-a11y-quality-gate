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
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_ignore' => [
        'path' => '/a11y/ignore',
        'target' => IssueApiController::class . '::ignoreAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_scan_page' => [
        'path' => '/a11y/scan/page',
        'target' => ScanAjaxController::class . '::scanPageAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_scan_site' => [
        'path' => '/a11y/scan/site',
        'target' => ScanAjaxController::class . '::scanSiteAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_scan_status' => [
        'path' => '/a11y/scan/status',
        'target' => ScanAjaxController::class . '::scanStatusAction',
        'methods' => ['GET'],
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
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_pro_crawl_summary' => [
        'path' => '/a11y/pro/crawl/summary',
        'target' => ProCrawlerAjaxController::class . '::summaryAction',
        'methods' => ['GET'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
    'a11y_validate_licence' => [
        'path' => '/a11y/validate-licence',
        'target' => SettingsController::class . '::validateLicenceAction',
        'methods' => ['POST'],
        'inheritAccessFromModule' => 'web_a11y',
    ],
];