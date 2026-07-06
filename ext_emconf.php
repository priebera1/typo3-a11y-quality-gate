<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Accessibility Quality Gate – TYPO3 Accessibility Checker',
    'description' => 'TYPO3 accessibility checker with CKEditor feedback, local content and rendered page checks, backend issue management, CLI and Scheduler scans, configurable quality gates, and PDF/CSV reporting. Detects common WCAG-related issues in headings, links, images, forms, tables and landmarks.',
    'category' => 'be',
    'author' => 'Patrik Priebera',
    'author_email' => 'patrik@priebera.sk',
    'author_company' => '',
    'state' => 'stable',
    'version' => '1.7.1',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-14.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [
            'typo3' => '14.0.0-14.2.99',
        ],
        'suggests' => [],
    ],
];
