<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'Accessibility Quality Gate (A11y)',
    'description' => 'Accessibility checks for TYPO3 editorial workflows with CKEditor integration, backend issue overview, CLI and Scheduler scans, and configurable quality gates.',
    'category' => 'be',
    'author' => 'Patrik Priebera',
    'author_email' => 'patrik@priebera.sk',
    'author_company' => '',
    'state' => 'stable',
    'version' => '1.1.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.99.99',
            'php' => '8.2.0-8.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
