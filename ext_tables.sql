#
# Table structure for table 'tx_a11y_scan'
#
CREATE TABLE tx_a11y_scan (
    uid              int(11)      NOT NULL AUTO_INCREMENT,
    pid              int(11)      NOT NULL DEFAULT 0,
    deleted          tinyint(1)   NOT NULL DEFAULT 0,
    site_identifier  varchar(255) NOT NULL DEFAULT '',
    root_pid         int(11)      NOT NULL DEFAULT 0,
    language_uid     int(11)      NOT NULL DEFAULT -1,
    scope            varchar(50)  NOT NULL DEFAULT 'subtree',
    status           tinyint(1)   NOT NULL DEFAULT 0,
    started_at       int(11)      NOT NULL DEFAULT 0,
    finished_at      int(11)      NOT NULL DEFAULT 0,
    pages_scanned    int(11)      NOT NULL DEFAULT 0,
    records_scanned  int(11)      NOT NULL DEFAULT 0,
    issues_new       int(11)      NOT NULL DEFAULT 0,
    issues_resolved  int(11)      NOT NULL DEFAULT 0,
    issues_ignored   int(11)      NOT NULL DEFAULT 0,
    rendered_checked int(11)      NOT NULL DEFAULT 0,
    rendered_skipped int(11)      NOT NULL DEFAULT 0,
    rendered_skipped_json mediumtext,
    pages_not_permitted int(11)   NOT NULL DEFAULT 0,
    not_permitted_json mediumtext,
    crdate           int(11)      NOT NULL DEFAULT 0,
    tstamp           int(11)      NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    KEY idx_site_status (site_identifier(50), status),
    KEY idx_site_started (site_identifier(50), started_at),
    KEY idx_deleted (deleted)
);

#
# Table structure for table 'tx_a11y_issue'
#
CREATE TABLE tx_a11y_issue (
    uid                  int(11)       NOT NULL AUTO_INCREMENT,
    pid                  int(11)       NOT NULL DEFAULT 0,
    deleted              tinyint(1)    NOT NULL DEFAULT 0,

    site_identifier      varchar(255)  NOT NULL DEFAULT '',
    page_uid             int(11)       NOT NULL DEFAULT 0,
    source_lang_uid      int(11)       NOT NULL DEFAULT 0,
    source_table         varchar(100)  NOT NULL DEFAULT '',
    source_uid           int(11)       NOT NULL DEFAULT 0,
    source_field         varchar(100)  NOT NULL DEFAULT '',
    source_type          varchar(20)   NOT NULL DEFAULT 'rte',
    frontend_url         varchar(2048) DEFAULT NULL,
    css_selector         varchar(512)  DEFAULT NULL,

    rule_id              varchar(100)  NOT NULL DEFAULT '',
    severity             tinyint(1)    NOT NULL DEFAULT 2,
    message              text          NOT NULL,
    hint                 text,

    context_snippet      text,
    context_path         varchar(500)  NOT NULL DEFAULT '',

    fingerprint          varchar(40)   NOT NULL DEFAULT '',

    status               tinyint(1)    NOT NULL DEFAULT 0,
    ignored_reason       text,
    ignored_by           int(11)       NOT NULL DEFAULT 0,
    ignored_by_name      varchar(255)  NOT NULL DEFAULT '',
    ignored_by_username  varchar(50)   NOT NULL DEFAULT '',
    ignored_at           int(11)       NOT NULL DEFAULT 0,
    ignored_until        int(11)       NOT NULL DEFAULT 0,
    ignored_reopened_at  int(11)       NOT NULL DEFAULT 0,
    resolved_by          int(11)       NOT NULL DEFAULT 0,
    resolved_by_name     varchar(255)  NOT NULL DEFAULT '',
    resolved_by_username varchar(50)   NOT NULL DEFAULT '',
    resolved_at          int(11)       NOT NULL DEFAULT 0,

    first_seen_scan_uid  int(11)       NOT NULL DEFAULT 0,
    last_seen_scan_uid   int(11)       NOT NULL DEFAULT 0,
    crdate               int(11)       NOT NULL DEFAULT 0,
    tstamp               int(11)       NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY uniq_site_fingerprint (site_identifier(50), fingerprint),
    KEY idx_page_status_sev (site_identifier(50), page_uid, status, severity),
    KEY idx_source (source_table(50), source_uid, source_field(50), source_lang_uid),
    KEY idx_source_type (source_type),
    KEY idx_rule_sev_status (rule_id(50), severity, status),
    KEY idx_ignored_until (status, ignored_until),
    KEY idx_deleted (deleted)
);



#
# Table structure for table 'tx_a11y_ruleset'
#
CREATE TABLE tx_a11y_ruleset (
    uid                 int(11)       NOT NULL AUTO_INCREMENT,
    pid                 int(11)       NOT NULL DEFAULT 0,
    deleted             tinyint(1)    NOT NULL DEFAULT 0,
    title               varchar(255)  NOT NULL DEFAULT '',
    site_identifier     varchar(255)  NOT NULL DEFAULT '',
    threshold_critical  int(11)       NOT NULL DEFAULT 0,
    threshold_warning   int(11)       NOT NULL DEFAULT -1,
    publish_mode        tinyint(1)    NOT NULL DEFAULT 0,
    rules_json          text,
    scanner_token       varchar(64)   NOT NULL DEFAULT '',
    http_auth_user      varchar(255)  NOT NULL DEFAULT '',
    http_auth_pass      text,
    excluded_patterns   text,
    cookie_accept_selectors text,
    is_global           tinyint(1)    NOT NULL DEFAULT 1,
    crawl_priority_urls text,
    is_default          tinyint(1)    NOT NULL DEFAULT 0,
    crdate              int(11)       NOT NULL DEFAULT 0,
    tstamp              int(11)       NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    KEY idx_site (site_identifier(50)),
    KEY idx_deleted (deleted)
);

#
# Table structure for table 'tx_a11y_source_state'
#
CREATE TABLE tx_a11y_source_state (
    uid              int(11)      NOT NULL AUTO_INCREMENT,
    site_identifier  varchar(255) NOT NULL DEFAULT '',
    page_uid         int(11)      NOT NULL DEFAULT 0,
    source_lang_uid  int(11)      NOT NULL DEFAULT 0,
    source_table     varchar(100) NOT NULL DEFAULT '',
    source_uid       int(11)      NOT NULL DEFAULT 0,
    source_field     varchar(100) NOT NULL DEFAULT '',
    content_hash     varchar(40)  NOT NULL DEFAULT '',
    last_scan_uid    int(11)      NOT NULL DEFAULT 0,
    crdate           int(11)      NOT NULL DEFAULT 0,
    tstamp           int(11)      NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY uniq_source (
    site_identifier(50),
    source_table(50),
    source_uid,
    source_field(50),
    source_lang_uid
    ),
    KEY idx_page (site_identifier(50), page_uid)
);

#
# Table structure for table 'tx_a11y_field_config'
#
CREATE TABLE tx_a11y_field_config (
    uid               int(11)      NOT NULL AUTO_INCREMENT,
    pid               int(11)      NOT NULL DEFAULT 0,
    deleted           tinyint(1)   NOT NULL DEFAULT 0,
    hidden            tinyint(1)   NOT NULL DEFAULT 0,

    table_name        varchar(255) NOT NULL DEFAULT '',
    field_name        varchar(255) NOT NULL DEFAULT '',
    field_type        varchar(50)  NOT NULL DEFAULT '',
    field_label       varchar(255) NOT NULL DEFAULT '',

    is_enabled        tinyint(1)   NOT NULL DEFAULT 1,
    is_auto_detected  tinyint(1)   NOT NULL DEFAULT 1,

    crdate            int(11)      NOT NULL DEFAULT 0,
    tstamp            int(11)      NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY uniq_table_field (table_name(100), field_name(100)),
    KEY idx_table (table_name(100)),
    KEY idx_enabled (is_enabled),
    KEY idx_deleted (deleted),
    KEY idx_hidden (hidden)
);

#
# Table structure for table 'tx_a11y_remote_scan'
#
CREATE TABLE tx_a11y_remote_scan (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    site_identifier varchar(255) DEFAULT '' NOT NULL,
    job_id varchar(255) DEFAULT '' NOT NULL,
    source_type varchar(50) DEFAULT '' NOT NULL,
    scan_scope varchar(20) DEFAULT 'site' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    language_uid int(11) DEFAULT '-1' NOT NULL,

    start_url varchar(2048) DEFAULT '' NOT NULL,
    sitemap_url varchar(2048) DEFAULT NULL,
    status varchar(50) DEFAULT '' NOT NULL,
    is_free_preview tinyint(4) unsigned DEFAULT '0' NOT NULL,
    # Preview scan (Atlas D9): a paid single-page scan of a page visitors could not open when it was submitted
    # (hidden, scheduled or limited to frontend user groups), rendered with the Scanner Token. Decided server-side at
    # submit time; never paired with a public scan and never counted as public coverage. 0 = public (legacy rows).
    is_page_preview tinyint(4) unsigned DEFAULT '0' NOT NULL,

    pages_scanned int(11) DEFAULT '0' NOT NULL,
    pages_total int(11) DEFAULT '0' NOT NULL,
    max_pages int(11) DEFAULT '0' NOT NULL,
    pages_failed int(11) DEFAULT '0' NOT NULL,
    issues_total int(11) DEFAULT '0' NOT NULL,
    issues_new int(11) DEFAULT '0' NOT NULL,
    issues_resolved int(11) DEFAULT '0' NOT NULL,

    started_at int(11) DEFAULT '0' NOT NULL,
    finished_at int(11) DEFAULT '0' NOT NULL,
    last_synced_at int(11) DEFAULT '0' NOT NULL,
    persisted_at int(11) DEFAULT '0' NOT NULL,
    persist_claim_until int(11) DEFAULT '0' NOT NULL,
    persist_claim_token varchar(64) DEFAULT '' NOT NULL,
    failure_origin varchar(16) DEFAULT '' NOT NULL,
    sync_error text,

    PRIMARY KEY (uid),
    KEY site_identifier (site_identifier),
    KEY job_id (job_id),
    KEY status (status),
    KEY is_free_preview (is_free_preview),
    KEY is_page_preview (is_page_preview),
    KEY scan_scope (scan_scope),
    KEY page_uid (page_uid),
    KEY language_uid (language_uid),
    KEY persisted_at (persisted_at)
);

#
# Table structure for table 'tx_a11y_remote_scan_page'
#
CREATE TABLE tx_a11y_remote_scan_page (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    remote_scan int(11) DEFAULT '0' NOT NULL,
    source_type varchar(50) DEFAULT '' NOT NULL,
    url varchar(2048) DEFAULT '' NOT NULL,
    title varchar(1024) DEFAULT '' NOT NULL,
    http_status int(11) DEFAULT '0' NOT NULL,
    issues_count int(11) DEFAULT '0' NOT NULL,
    screenshot_path varchar(2048) DEFAULT '' NOT NULL,
    screenshot_url varchar(2048) DEFAULT '' NOT NULL,
    external_page_id varchar(36) DEFAULT '' NOT NULL,
    keyboard_summary_json mediumtext,
    remediation_summary_json mediumtext,
    page_recommendation_json mediumtext,
    screenshot_evidence_json text,
    # Screenshot retention (AQG service, 2026-10): the crawler's state at persist time (available, expired, deleted,
    # failed, not_captured) and when an available screenshot ends (0 = no period reported). The scan's findings, counts
    # and history have no such period; only the image does.
    screenshot_state varchar(20) DEFAULT '' NOT NULL,
    screenshot_expires_at int(11) DEFAULT '0' NOT NULL,
    failure_reason text,
    is_failed tinyint(1) DEFAULT '0' NOT NULL,
    # Provenance (Sol R01): the TYPO3 page (and translation) the URL belonged to when the results were stored, resolved
    # once by RemoteResultProvenanceService. 0 = stored before provenance existed, 1 = resolved, 2 = no owner found.
    # Reads check current rights on this owner; results without a resolved owner are shown to administrators only.
    owner_state tinyint(1) unsigned DEFAULT '0' NOT NULL,
    owner_page_uid int(11) DEFAULT '0' NOT NULL,
    owner_language_uid int(11) DEFAULT '-1' NOT NULL,
    owner_translation_uid int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY remote_scan (remote_scan),
    KEY issues_count (issues_count),
    KEY is_failed (is_failed)
);

#
# Table structure for table 'tx_a11y_remote_issue'
#
CREATE TABLE tx_a11y_remote_issue (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    remote_scan int(11) DEFAULT '0' NOT NULL,
    remote_scan_page int(11) DEFAULT '0' NOT NULL,

    rule_id varchar(255) DEFAULT '' NOT NULL,
    impact varchar(50) DEFAULT '' NOT NULL,
    help text,
    help_url varchar(2048) DEFAULT '' NOT NULL,
    plain_language_title varchar(255) DEFAULT '' NOT NULL,
    plain_language_description text,
    affected_users_json text,
    wcag_references_json text,
    standards_json text,
    rule_documentation_json text,
    technical_tags_json text,
    rule_metadata_json mediumtext,
    guidance_why_it_matters text,
    guidance_how_to_fix text,
    who_should_fix varchar(50) DEFAULT '' NOT NULL,
    fix_type varchar(50) DEFAULT '' NOT NULL,
    confidence varchar(50) DEFAULT '' NOT NULL,
    nodes_count int(11) DEFAULT '0' NOT NULL,
    fingerprint varchar(64) DEFAULT '' NOT NULL,
    status varchar(50) DEFAULT 'open' NOT NULL,

    PRIMARY KEY (uid),
    KEY remote_scan (remote_scan),
    KEY remote_scan_page (remote_scan_page),
    KEY rule_id (rule_id),
    KEY impact (impact),
    KEY fingerprint (fingerprint)
);

#
# Table structure for table 'tx_a11y_remote_issue_node'
#
CREATE TABLE tx_a11y_remote_issue_node (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    deleted tinyint(4) unsigned DEFAULT '0' NOT NULL,

    remote_issue int(11) DEFAULT '0' NOT NULL,
    target_json mediumtext,
    html_snippet mediumtext,
    failure_summary text,
    screenshot_path varchar(2048) DEFAULT '' NOT NULL,
    screenshot_url varchar(2048) DEFAULT '' NOT NULL,
    contrast_details_json mediumtext,
    node_remediation_json mediumtext,

    mapped_table varchar(255) DEFAULT '' NOT NULL,
    mapped_uid int(11) DEFAULT '0' NOT NULL,
    mapped_cid varchar(255) DEFAULT '' NOT NULL,
    mapped_ctype varchar(100) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY remote_issue (remote_issue)
);

#
# Table structure for table 'sys_file_reference'
#
CREATE TABLE sys_file_reference (
    tx_a11y_is_decorative tinyint(1) unsigned DEFAULT '0' NOT NULL
);

#
# Per-site encrypted AI provider configuration
#
CREATE TABLE tx_a11y_ai_configuration (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    deleted tinyint(1) NOT NULL DEFAULT 0,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    provider varchar(32) NOT NULL DEFAULT 'openai',
    encrypted_api_key text,
    key_hint varchar(32) NOT NULL DEFAULT '',
    enabled tinyint(1) NOT NULL DEFAULT 1,
    model varchar(100) NOT NULL DEFAULT '',
    selected_model_id varchar(100) NOT NULL DEFAULT '',
    discovered_models_cache mediumtext,
    discovered_models_at int(11) NOT NULL DEFAULT 0,
    verified_key_fingerprint varchar(64) NOT NULL DEFAULT '',
    verified_model_id varchar(100) NOT NULL DEFAULT '',
    verified_prompt_version varchar(64) NOT NULL DEFAULT '',
    verified_connection_contract_version varchar(64) NOT NULL DEFAULT '',
    last_tested_at int(11) NOT NULL DEFAULT 0,
    last_verified_at int(11) NOT NULL DEFAULT 0,
    last_test_error_code varchar(64) NOT NULL DEFAULT '',
    link_text_suggestions_enabled tinyint(1) NOT NULL DEFAULT 0,
    revalidation_required tinyint(1) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY site_provider_active (site_identifier, provider, deleted),
    KEY deleted (deleted)
);


#
# Per-site AI provider choice and privacy settings (AQG 3.0). A site without a row keeps its 2.x behaviour: OpenAI,
# images and page titles may be sent, and the link-text switch is read from the site's OpenAI configuration row.
#
CREATE TABLE tx_a11y_ai_site_setting (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    active_provider varchar(32) NOT NULL DEFAULT '',
    allow_image_input tinyint(1) NOT NULL DEFAULT 1,
    allow_page_title tinyint(1) NOT NULL DEFAULT 1,
    link_text_suggestions_enabled tinyint(1) NOT NULL DEFAULT 0,
    provider_changed_at int(11) NOT NULL DEFAULT 0,
    provider_changed_by int(11) NOT NULL DEFAULT 0,
    budget_micro_usd bigint(20) NOT NULL DEFAULT 0,
    budget_warning_percent int(11) NOT NULL DEFAULT 0,
    budget_hard_stop tinyint(1) NOT NULL DEFAULT 0,
    budget_changed_at int(11) NOT NULL DEFAULT 0,
    budget_changed_by int(11) NOT NULL DEFAULT 0,
    budget_alert_email varchar(255) NOT NULL DEFAULT '',
    processing_ack_provider varchar(32) NOT NULL DEFAULT '',
    processing_ack_by int(11) NOT NULL DEFAULT 0,
    processing_ack_at int(11) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY site_identifier (site_identifier)
);

#
# One row per AI provider request: provider-reported token counts and a safe outcome code. Never prompt, image,
# answer, key or other content. A row is admitted as outcome 'pending' before the request (reserved_cost_micro_usd =
# its worst case, budget_reservation = its monthly hard-stop reservation, if any) and settled afterwards; a row whose
# settlement failed stays pending and keeps counting (AiUsageRecorder).
#
CREATE TABLE tx_a11y_ai_usage (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    be_user int(11) NOT NULL DEFAULT 0,
    feature varchar(32) NOT NULL DEFAULT '',
    provider varchar(32) NOT NULL DEFAULT '',
    model varchar(100) NOT NULL DEFAULT '',
    outcome varchar(64) NOT NULL DEFAULT '',
    input_tokens int(11) NOT NULL DEFAULT 0,
    output_tokens int(11) NOT NULL DEFAULT 0,
    usage_reported tinyint(1) NOT NULL DEFAULT 0,
    cache_read_tokens int(11) NOT NULL DEFAULT 0,
    cache_write_tokens int(11) NOT NULL DEFAULT 0,
    estimated_cost_micro_usd bigint(20) NOT NULL DEFAULT 0,
    cost_known tinyint(1) NOT NULL DEFAULT 0,
    cost_basis varchar(16) NOT NULL DEFAULT '',
    price_origin varchar(16) NOT NULL DEFAULT '',
    price_verified_at int(11) NOT NULL DEFAULT 0,
    conversation_ref varchar(32) NOT NULL DEFAULT '',
    reserved_cost_micro_usd bigint(20) NOT NULL DEFAULT 0,
    budget_reservation int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    KEY site_period (site_identifier, crdate),
    KEY conversation (be_user, conversation_ref)
);

#
# Pre-3.0 administrator-entered AI prices. Read-only since 3.0: kept (never deleted or rewritten) as a fallback for
# models the automatic catalogue does not price. Money is integer micro-USD (1 USD = 1,000,000) per 1M tokens.
#
CREATE TABLE tx_a11y_ai_price (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    provider varchar(32) NOT NULL DEFAULT '',
    model varchar(100) NOT NULL DEFAULT '',
    input_micro_usd bigint(20) NOT NULL DEFAULT 0,
    output_micro_usd bigint(20) NOT NULL DEFAULT 0,
    price_as_of int(11) NOT NULL DEFAULT 0,
    source varchar(255) NOT NULL DEFAULT '',
    entered_by int(11) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY provider_model (provider, model)
);

#
# Local copy of the central AI price catalogue (GET /ai/pricing/v1/catalogue of the AQG service, read by
# AiPriceCatalogueService). One row per provider price id; rates is a JSON object of integer micro-USD per 1M tokens
# (standard rates and an optional prompt-length tier); verified_at is when the AQG service last confirmed the price on
# the provider's pricing page. A model the catalogue no longer lists keeps its last price with listed = 0.
#
CREATE TABLE tx_a11y_ai_model_price (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    provider varchar(32) NOT NULL DEFAULT '',
    model varchar(100) NOT NULL DEFAULT '',
    label varchar(100) NOT NULL DEFAULT '',
    rates text,
    source_url varchar(255) NOT NULL DEFAULT '',
    verified_at int(11) NOT NULL DEFAULT 0,
    listed tinyint(1) NOT NULL DEFAULT 1,
    crdate int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY provider_model (provider, model)
);

#
# State of the local catalogue copy (one row, identifier 'central'): the catalogue version and ETag it holds, when it was
# last refreshed and attempted, a safe failure code, and the claim that keeps concurrent refreshes apart.
#
CREATE TABLE tx_a11y_ai_price_catalogue (
    uid int(11) NOT NULL AUTO_INCREMENT,
    identifier varchar(32) NOT NULL DEFAULT '',
    catalogue_version int(11) NOT NULL DEFAULT 0,
    etag varchar(100) NOT NULL DEFAULT '',
    published_at int(11) NOT NULL DEFAULT 0,
    refreshed_at int(11) NOT NULL DEFAULT 0,
    last_attempt_at int(11) NOT NULL DEFAULT 0,
    consecutive_failures int(11) NOT NULL DEFAULT 0,
    error_reason varchar(32) NOT NULL DEFAULT '',
    claim_token varchar(32) NOT NULL DEFAULT '',
    claimed_at int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY identifier (identifier)
);

#
# Monthly AI budget enforcement: one lock row per site and calendar month (server time), locked with
# SELECT ... FOR UPDATE while a request is checked, and the open worst-case reservations of running requests.
#
CREATE TABLE tx_a11y_ai_budget_lock (
    uid int(11) NOT NULL AUTO_INCREMENT,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    period int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY site_period (site_identifier, period)
);

CREATE TABLE tx_a11y_ai_budget_reservation (
    uid int(11) NOT NULL AUTO_INCREMENT,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    period int(11) NOT NULL DEFAULT 0,
    cost_micro_usd bigint(20) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    KEY site_period (site_identifier, period)
);

#
# Budget warning e-mails (F-39): one row per site, calendar month (YYYY-MM, server time), warning threshold and budget.
# The unique key is claimed insert-first, so concurrent settlements cannot both send; status pending/sent/failed with
# bounded retries. Never content, prompts, keys, recipients or user names.
#
CREATE TABLE tx_a11y_ai_budget_alert (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    alert_month varchar(7) NOT NULL DEFAULT '',
    threshold_percent int(11) NOT NULL DEFAULT 0,
    budget_micro_usd bigint(20) NOT NULL DEFAULT 0,
    status varchar(16) NOT NULL DEFAULT 'pending',
    attempts int(11) NOT NULL DEFAULT 0,
    last_attempt_at int(11) NOT NULL DEFAULT 0,
    sent_at int(11) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY alert_key (site_identifier, alert_month, threshold_percent, budget_micro_usd)
);

#
# Shared server-side AI rate-limit buckets
#
CREATE TABLE tx_a11y_ai_rate_limit (
    uid int(11) NOT NULL AUTO_INCREMENT,
    bucket_hash varchar(64) NOT NULL DEFAULT '',
    window_started int(11) NOT NULL DEFAULT 0,
    request_count int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY bucket_hash (bucket_hash)
);

#
# Native TYPO3 14 Scheduler task fields for EXT:a11y_quality_gate
#
CREATE TABLE tx_scheduler_task (
    tx_a11yqualitygate_page_uid int(11) DEFAULT '0' NOT NULL,
    tx_a11yqualitygate_root_pid int(11) DEFAULT '0' NOT NULL,
    tx_a11yqualitygate_depth int(11) DEFAULT '99' NOT NULL,
    tx_a11yqualitygate_language_uid int(11) DEFAULT '-1' NOT NULL,
    tx_a11yqualitygate_changed_only tinyint(1) DEFAULT '0' NOT NULL,
    tx_a11yqualitygate_monitor_site varchar(255) DEFAULT '' NOT NULL,
    tx_a11yqualitygate_monitor_language int(11) DEFAULT '0' NOT NULL,
    tx_a11yqualitygate_monitor_recipients varchar(1000) DEFAULT '' NOT NULL,
    tx_a11yqualitygate_monitor_max_pages int(11) DEFAULT '500' NOT NULL,
    tx_a11yqualitygate_monitor_max_wait int(11) DEFAULT '1200' NOT NULL,
    tx_a11yqualitygate_monitor_backend_url varchar(255) DEFAULT '' NOT NULL
);

#
# Table structure for table 'tx_a11y_fix_verification'
#
# One "Verify fix" request: which finding (rule on a frontend URL) was checked again, by which scan,
# and what that scan showed. The baseline scan and the verification scan stay referenced, so the
# outcome can always be traced to the scan that produced it.
#
CREATE TABLE tx_a11y_fix_verification (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site_identifier varchar(255) DEFAULT '' NOT NULL,
    rule_id varchar(255) DEFAULT '' NOT NULL,
    url varchar(2048) DEFAULT '' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    language_uid int(11) DEFAULT '-1' NOT NULL,
    baseline_issue int(11) DEFAULT '0' NOT NULL,
    baseline_scan int(11) DEFAULT '0' NOT NULL,
    baseline_occurrences int(11) DEFAULT '0' NOT NULL,
    verification_job_id varchar(255) DEFAULT '' NOT NULL,
    verification_scan int(11) DEFAULT '0' NOT NULL,
    outcome varchar(20) DEFAULT 'pending' NOT NULL,
    outcome_reason varchar(50) DEFAULT '' NOT NULL,
    remaining_occurrences int(11) DEFAULT '0' NOT NULL,
    requested_by int(11) DEFAULT '0' NOT NULL,
    evaluated_at int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY baseline_issue (baseline_issue),
    KEY verification_job_id (verification_job_id(64)),
    KEY site_rule (site_identifier(50), rule_id(100))
);

#
# Table structure for table 'tx_a11y_monitoring_run'
#
# Scheduled monitoring (a11y:monitor): one row per monitoring scan, with the compatible baseline it was
# compared with, whether its scan covered everything the baseline had checked (only then is it the next
# runs baseline), and the state that was (or was not) notified.
#
CREATE TABLE tx_a11y_monitoring_run (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site_identifier varchar(255) DEFAULT '' NOT NULL,
    language_uid int(11) DEFAULT '-1' NOT NULL,
    job_id varchar(255) DEFAULT '' NOT NULL,
    status varchar(20) DEFAULT 'submitted' NOT NULL,
    outcome varchar(30) DEFAULT '' NOT NULL,
    baseline_job_id varchar(255) DEFAULT '' NOT NULL,
    new_issue_types int(11) DEFAULT '0' NOT NULL,
    regressed_issue_types int(11) DEFAULT '0' NOT NULL,
    state_fingerprint varchar(100) DEFAULT '' NOT NULL,
    coverage_complete tinyint(1) unsigned DEFAULT '0' NOT NULL,
    delivery_pending tinyint(1) unsigned DEFAULT '0' NOT NULL,
    notified_at int(11) DEFAULT '0' NOT NULL,
    delivery_attempts int(11) unsigned DEFAULT '0' NOT NULL,
    delivery_error varchar(64) DEFAULT '' NOT NULL,
    delivery_superseded_by int(11) unsigned DEFAULT '0' NOT NULL,
    evaluated_at int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    KEY site_language (site_identifier(50), language_uid),
    KEY job_id (job_id(64))
);

#
# Remediation workflow (Package 4): one row per prepared change for one finding and one exact target, from the
# suggestion to the TYPO3 save and the verification by an AQG check. Stores the target's own value before and after
# and the AI's short reason — never prompts, other page content, or image data. Retention is an open owner decision.
#
CREATE TABLE tx_a11y_remediation_item (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    page_uid int(11) NOT NULL DEFAULT 0,
    language_uid int(11) NOT NULL DEFAULT 0,
    workspace_id int(11) NOT NULL DEFAULT 0,
    finding_uid int(11) NOT NULL DEFAULT 0,
    finding_fingerprint varchar(64) NOT NULL DEFAULT '',
    rule_id varchar(255) NOT NULL DEFAULT '',
    severity int(11) NOT NULL DEFAULT 0,
    target_kind varchar(32) NOT NULL DEFAULT '',
    target_table varchar(64) NOT NULL DEFAULT '',
    target_uid int(11) NOT NULL DEFAULT 0,
    target_field varchar(64) NOT NULL DEFAULT '',
    target_descriptor text,
    base_hash varchar(64) NOT NULL DEFAULT '',
    original_value text,
    proposed_value text,
    edited_value text,
    explanation varchar(500) NOT NULL DEFAULT '',
    owner varchar(16) NOT NULL DEFAULT 'unassigned',
    status varchar(32) NOT NULL DEFAULT 'preparing',
    status_reason varchar(64) NOT NULL DEFAULT '',
    origin varchar(32) NOT NULL DEFAULT '',
    provider varchar(32) NOT NULL DEFAULT '',
    model varchar(100) NOT NULL DEFAULT '',
    prompt_version varchar(32) NOT NULL DEFAULT '',
    input_tokens int(11) NOT NULL DEFAULT 0,
    output_tokens int(11) NOT NULL DEFAULT 0,
    proposal_version int(11) NOT NULL DEFAULT 0,
    row_version int(11) NOT NULL DEFAULT 0,
    batch_id varchar(32) NOT NULL DEFAULT '',
    attempts int(11) NOT NULL DEFAULT 0,
    created_by int(11) NOT NULL DEFAULT 0,
    reviewed_by int(11) NOT NULL DEFAULT 0,
    reviewed_at int(11) NOT NULL DEFAULT 0,
    applied_by int(11) NOT NULL DEFAULT 0,
    applied_at int(11) NOT NULL DEFAULT 0,
    staged_at int(11) NOT NULL DEFAULT 0,
    saved_at int(11) NOT NULL DEFAULT 0,
    checked_at int(11) NOT NULL DEFAULT 0,
    verified_at int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    KEY site_status (site_identifier(50), status),
    KEY page_status (page_uid, status),
    KEY finding (finding_uid),
    KEY batch (batch_id)
);

#
# Remediation history: append-only state transitions, with codes and value hashes only (no proposed text).
#
CREATE TABLE tx_a11y_remediation_event (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    crdate int(11) NOT NULL DEFAULT 0,
    item_uid int(11) NOT NULL DEFAULT 0,
    be_user int(11) NOT NULL DEFAULT 0,
    action varchar(32) NOT NULL DEFAULT '',
    from_status varchar(32) NOT NULL DEFAULT '',
    to_status varchar(32) NOT NULL DEFAULT '',
    detail varchar(64) NOT NULL DEFAULT '',
    proposal_version int(11) NOT NULL DEFAULT 0,
    value_hash varchar(64) NOT NULL DEFAULT '',
    PRIMARY KEY (uid),
    KEY item (item_uid, crdate)
);

#
# Assigned owner of one rule on one page (editor / developer / mixed / unassigned). Without a row the owner is
# AQG's suggested owner of the rule.
#
CREATE TABLE tx_a11y_remediation_owner (
    uid int(11) NOT NULL AUTO_INCREMENT,
    pid int(11) NOT NULL DEFAULT 0,
    tstamp int(11) NOT NULL DEFAULT 0,
    site_identifier varchar(255) NOT NULL DEFAULT '',
    page_uid int(11) NOT NULL DEFAULT 0,
    rule_id varchar(255) NOT NULL DEFAULT '',
    owner varchar(16) NOT NULL DEFAULT '',
    assigned_by int(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (uid),
    UNIQUE KEY page_rule (site_identifier(50), page_uid, rule_id(150))
);

#
# Table structure for table 'tx_a11y_mcp_site'
#
# Agent access (MCP): whether agents may use a site at all and which capabilities they may request there. A capability
# is only a ceiling: a call also needs the user's consent (the token) and the user's own TYPO3 permissions.
#
CREATE TABLE tx_a11y_mcp_site (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    site_identifier varchar(255) DEFAULT '' NOT NULL,
    enabled tinyint(1) unsigned DEFAULT '0' NOT NULL,
    capabilities varchar(255) DEFAULT '' NOT NULL,
    changed_by int(11) DEFAULT '0' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY site_identifier (site_identifier(100))
);

#
# Table structure for table 'tx_a11y_mcp_audit'
#
# Agent access (MCP) audit trail: identifiers and outcomes of agent calls, consents, revocations and administrator
# changes. Never prompts, page content, finding text or tokens. Pruned after 90 days (mcpAuditRetentionDays) by the
# connector and a11y:mcp:audit-prune.
#
CREATE TABLE tx_a11y_mcp_audit (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    be_user_uid int(11) DEFAULT '0' NOT NULL,
    be_user_name varchar(255) DEFAULT '' NOT NULL,
    client_name varchar(255) DEFAULT '' NOT NULL,
    token_suffix varchar(10) DEFAULT '' NOT NULL,
    site_identifier varchar(255) DEFAULT '' NOT NULL,
    action varchar(64) DEFAULT '' NOT NULL,
    target varchar(255) DEFAULT '' NOT NULL,
    result varchar(32) DEFAULT '' NOT NULL,
    reason varchar(64) DEFAULT '' NOT NULL,
    source varchar(32) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    KEY crdate (crdate),
    KEY be_user (be_user_uid)
);

#
# Table structure for table 'tx_a11y_mcp_scan_request'
#
# Durable idempotency of agent scan starts (McpScanRequestStore): one row per TYPO3 user and agent idempotency key, with
# the request fingerprint and the crawler job it produced. Technical state, kept 7 days; no prompts or page content.
#
CREATE TABLE tx_a11y_mcp_scan_request (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,

    request_hash varchar(64) DEFAULT '' NOT NULL,
    nonce varchar(32) DEFAULT '' NOT NULL,
    fingerprint varchar(64) DEFAULT '' NOT NULL,
    be_user_uid int(11) DEFAULT '0' NOT NULL,
    status varchar(16) DEFAULT '' NOT NULL,
    job_id varchar(64) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY request_hash (request_hash),
    KEY crdate (crdate)
);

#
# Table structure for table 'tx_a11y_mcp_consent'
#
# Agent access (MCP) consent ledger (clone safety N2, McpConsentLedger): the authorization requests this installation's
# consent page allowed, as SHA-256 of the request reference (the Gateway's consent reference of the token), with the
# TYPO3 user and the granted scopes and sites. The connector runs an agent call only for a consent recorded here for that
# user and that grant. Kept 100 days (longer
# than the longest token). No token, code or prompt.
#
CREATE TABLE tx_a11y_mcp_consent (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,

    consent_ref_hash varchar(64) DEFAULT '' NOT NULL,
    be_user_uid int(11) DEFAULT '0' NOT NULL,
    grant_hash varchar(64) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY consent_user (consent_ref_hash, be_user_uid),
    KEY crdate (crdate)
);

#
# Table structure for table 'tx_a11y_assignment'
#
# Team assignment (My issues, 2026-11 company acceptance): one row per issue type on a page in one language — site, page,
# language and canonical rule (rendered rules fold into their rich-text equivalent). It does not reference finding uids or
# fingerprints, so it survives rescans and content changes; its state (open / done / reopened) is read live from the
# findings. assignee 0 = unassigned (the row keeps who unassigned it and when).
#
CREATE TABLE tx_a11y_assignment (
    uid int(11) NOT NULL auto_increment,
    pid int(11) DEFAULT '0' NOT NULL,
    tstamp int(11) unsigned DEFAULT '0' NOT NULL,
    crdate int(11) unsigned DEFAULT '0' NOT NULL,
    site_identifier varchar(255) DEFAULT '' NOT NULL,
    page_uid int(11) DEFAULT '0' NOT NULL,
    language_uid int(11) DEFAULT '0' NOT NULL,
    issue_key varchar(150) DEFAULT '' NOT NULL,
    assignee int(11) DEFAULT '0' NOT NULL,
    assigned_by int(11) DEFAULT '0' NOT NULL,
    assigned_at int(11) unsigned DEFAULT '0' NOT NULL,
    note varchar(280) DEFAULT '' NOT NULL,

    PRIMARY KEY (uid),
    UNIQUE KEY issue_on_page (site_identifier(50), page_uid, language_uid, issue_key(100)),
    KEY assignee (assignee)
);
