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
    ignored_at           int(11)       NOT NULL DEFAULT 0,
    resolved_by          int(11)       NOT NULL DEFAULT 0,
    resolved_at          int(11)       NOT NULL DEFAULT 0,

    first_seen_scan_uid  int(11)       NOT NULL DEFAULT 0,
    last_seen_scan_uid   int(11)       NOT NULL DEFAULT 0,
    crdate               int(11)       NOT NULL DEFAULT 0,
    tstamp               int(11)       NOT NULL DEFAULT 0,

    PRIMARY KEY (uid),
    UNIQUE KEY uniq_site_fingerprint (site_identifier(50), fingerprint),
    KEY idx_page_status_sev (site_identifier(50), page_uid, status, severity),
    KEY idx_source (source_table(50), source_uid, source_field(50), source_lang_uid),
    KEY idx_rule_sev_status (rule_id(50), severity, status),
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

    start_url varchar(2048) DEFAULT '' NOT NULL,
    sitemap_url varchar(2048) DEFAULT NULL,
    status varchar(50) DEFAULT '' NOT NULL,

    pages_scanned int(11) DEFAULT '0' NOT NULL,
    pages_total int(11) DEFAULT '0' NOT NULL,
    pages_failed int(11) DEFAULT '0' NOT NULL,
    issues_total int(11) DEFAULT '0' NOT NULL,
    issues_new int(11) DEFAULT '0' NOT NULL,
    issues_resolved int(11) DEFAULT '0' NOT NULL,

    started_at int(11) DEFAULT '0' NOT NULL,
    finished_at int(11) DEFAULT '0' NOT NULL,
    last_synced_at int(11) DEFAULT '0' NOT NULL,
    persisted_at int(11) DEFAULT '0' NOT NULL,
    sync_error text,

    PRIMARY KEY (uid),
    KEY site_identifier (site_identifier),
    KEY job_id (job_id),
    KEY status (status),
    KEY scan_scope (scan_scope),
    KEY page_uid (page_uid),
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
    failure_reason text,
    is_failed tinyint(1) DEFAULT '0' NOT NULL,

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