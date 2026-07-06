<?php

declare(strict_types=1);

defined('TYPO3') || die();

return [
    'ctrl' => [
        'title' => 'AQG AI configuration',
        'label' => 'site_identifier',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'rootLevel' => 1,
        'adminOnly' => true,
        'hideTable' => true,
        'security' => ['ignorePageTypeRestriction' => true],
    ],
    'columns' => [
        'site_identifier' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'provider' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'encrypted_api_key' => ['config' => ['type' => 'passthrough']],
        'key_hint' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'enabled' => ['config' => ['type' => 'check', 'readOnly' => true]],
        'model' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'selected_model_id' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'discovered_models_cache' => ['config' => ['type' => 'passthrough']],
        'discovered_models_at' => ['config' => ['type' => 'datetime', 'readOnly' => true]],
        'verified_key_fingerprint' => ['config' => ['type' => 'passthrough']],
        'verified_model_id' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'verified_prompt_version' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'verified_connection_contract_version' => ['config' => ['type' => 'input', 'readOnly' => true]],
        'last_tested_at' => ['config' => ['type' => 'datetime', 'readOnly' => true]],
        'last_verified_at' => ['config' => ['type' => 'datetime', 'readOnly' => true]],
        'last_test_error_code' => ['config' => ['type' => 'input', 'readOnly' => true]],
    ],
    'types' => [
        '1' => [
            'showitem' => 'site_identifier,provider,key_hint,enabled,selected_model_id,discovered_models_at,verified_model_id,verified_prompt_version,verified_connection_contract_version,last_tested_at,last_verified_at,last_test_error_code',
        ],
    ],
];
