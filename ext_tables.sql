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