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
    public const AI_CONFIGURATION = 'tx_a11y_ai_configuration';
    public const AI_RATE_LIMIT = 'tx_a11y_ai_rate_limit';
    public const AI_SITE_SETTING = 'tx_a11y_ai_site_setting';
    public const AI_USAGE = 'tx_a11y_ai_usage';
    public const AI_MODEL_PRICE = 'tx_a11y_ai_model_price';
    public const AI_PRICE_CATALOGUE = 'tx_a11y_ai_price_catalogue';
    public const REMEDIATION_ITEM = 'tx_a11y_remediation_item';
    public const REMEDIATION_EVENT = 'tx_a11y_remediation_event';
    public const REMEDIATION_OWNER = 'tx_a11y_remediation_owner';

    public const REMOTE_SCAN = 'tx_a11y_remote_scan';
    public const REMOTE_SCAN_PAGE = 'tx_a11y_remote_scan_page';
    public const REMOTE_ISSUE = 'tx_a11y_remote_issue';
    public const REMOTE_ISSUE_NODE = 'tx_a11y_remote_issue_node';
    public const FIX_VERIFICATION = 'tx_a11y_fix_verification';
    public const MONITORING_RUN = 'tx_a11y_monitoring_run';
    public const MCP_SITE = 'tx_a11y_mcp_site';
    public const MCP_AUDIT = 'tx_a11y_mcp_audit';
    public const MCP_SCAN_REQUEST = 'tx_a11y_mcp_scan_request';
    public const MCP_CONSENT = 'tx_a11y_mcp_consent';
    public const ASSIGNMENT = 'tx_a11y_assignment';

    public const PAGES = 'pages';
    public const TT_CONTENT = 'tt_content';
    public const SYS_FILE = 'sys_file';
    public const SYS_FILE_REFERENCE = 'sys_file_reference';
    public const SYS_FILE_METADATA = 'sys_file_metadata';

    private function __construct()
    {
    }
}
