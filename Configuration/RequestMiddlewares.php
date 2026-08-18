<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Middleware\AqgFrontendDebugMiddleware;
use Priebera\A11yQualityGate\Middleware\FreePreviewProofMiddleware;
use Priebera\A11yQualityGate\Middleware\ScannerPreviewMiddleware;

return [
    'frontend' => [
        'priebera/a11y-quality-gate/free-preview-proof' => [
            'target' => FreePreviewProofMiddleware::class,
            'after' => [
                'typo3/cms-frontend/site',
            ],
            'before' => [
                'typo3/cms-frontend/base-redirect-resolver',
                'priebera/a11y-quality-gate/scanner-preview-access',
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'priebera/a11y-quality-gate/scanner-preview-access' => [
            'target' => ScannerPreviewMiddleware::class,
            'before' => [
                'typo3/cms-frontend/page-resolver',
            ],
        ],
        'priebera/a11y-quality-gate/frontend-debug-markers' => [
            'target' => AqgFrontendDebugMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
                'priebera/a11y-quality-gate/scanner-preview-access',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];
