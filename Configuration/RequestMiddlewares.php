<?php

declare(strict_types=1);

use Priebera\A11yQualityGate\Middleware\AqgFrontendDebugMiddleware;

return [
    'frontend' => [
        'priebera/a11y-quality-gate/frontend-debug-markers' => [
            'target' => AqgFrontendDebugMiddleware::class,
            'after' => [
                'typo3/cms-frontend/authentication',
            ],
            'before' => [
                'typo3/cms-frontend/prepare-tsfe-rendering',
            ],
        ],
    ],
];