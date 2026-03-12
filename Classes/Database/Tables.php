<?php

declare(strict_types=1);

namespace Priebera\A11yQualityGate\Database;

final class Tables
{
    public const ISSUE = 'tx_a11y_issue';
    public const SCAN = 'tx_a11y_scan';
    public const RULESET = 'tx_a11y_ruleset';
    public const SOURCE_STATE = 'tx_a11y_source_state';
    public const FIELD_CONFIG = 'tx_a11y_field_config';

    public const PAGES = 'pages';
    public const TT_CONTENT = 'tt_content';
    public const SYS_FILE = 'sys_file';
    public const SYS_FILE_REFERENCE = 'sys_file_reference';
    public const SYS_FILE_METADATA = 'sys_file_metadata';

    private function __construct()
    {
    }
}
