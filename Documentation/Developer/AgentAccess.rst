..  _developer-agent-access:

=======================
Agent access (MCP)
=======================

AI agents such as Claude Code connect to AQG through the hosted **AQG MCP
Gateway** (Model Context Protocol, Streamable HTTP). Every agent acts as one
TYPO3 backend user and only gets that user's permissions; nothing an agent does
changes content. For AQG PRO and Agency.

..  important::
    **Availability in 3.0.0.** The extension ships the connector, the settings
    tab, the consent page and its tables, but agent access is **off by
    default**: nobody may connect agents until an administrator grants
    ``options.a11y_quality_gate.mcp.connect``, turns a site on and pairs the
    installation with the AQG MCP Gateway. The hosted Gateway is **not publicly
    available yet**; until AQG opens it, pairing is refused and no agent can
    connect. Opening it needs the AQG service's Gateway release (API migration
    097, Gateway configuration and the connector prerequisites below) and is
    announced separately. Nothing in this chapter is active on an installation
    that does not pair.

Tools (v1)
==========

See also :ref:`developer-agent-access-intelligence` for the read-only content tools.

==========================  ===============================  =========================================================
Tool                        Needs capability                 What it returns
==========================  ===============================  =========================================================
``aqg_whoami``              any                              TYPO3 user, agent, granted and effective capabilities
``aqg_list_sites``          any                              Sites with agent access on that the user can see
``aqg_list_scans``          ``history:read``                 Frontend scans of a site (paginated, readable scans only)
``aqg_get_scan_results``    ``findings:read``                Summary, top issue types and paginated findings of a completed scan
``aqg_start_scan``          ``scans:run-page``               Starts a frontend scan of one page (``pageUid``); optional ``idempotencyKey``
``aqg_get_scan_status``     ``history:read`` or scans        Status and progress; stores results once completed
==========================  ===============================  =========================================================

``aqg_start_scan`` takes the uid of a **default-language page** and the language (``languageUid``, default ``0``):
a translation is scanned as ``pageUid`` of its default page plus the translation's language, and needs the user's
access to that language and to the translation record. The uid of a page translation record is refused in every
language (``permission_denied``, or ``page_not_found`` when the user cannot see the record); "Scan this page" and the
Free Remote Preview apply the same rule.

Scans of pages visitors cannot open (hidden, scheduled, restricted) are preview scans (``pagePreview: true``). Like
the module's site views, ``aqg_list_scans`` lists them only with ``scope: "page"``; they never appear in the site
listing.

Findings follow the user's page and record rights, not only the scan's
(:ref:`developer-agent-access-results`). ``aqg_list_scans`` examines a bounded
number of the newest stored scans; when it stops before the oldest one it
answers ``searchComplete: false``, ``total: null`` and a ``nextCursor`` to
continue with older scans (never "no scans" while older ones may exist). The
cursor is opaque (sealed by TYPO3, bound to user, site and scope): pass it back
unchanged. A cursor TYPO3 cannot open — malformed, tampered, issued for another
user, site or scope, or sealed before an ``encryptionKey`` change — is the error
``cursor_invalid``; call again without ``cursor``.
``aqg_get_scan_status`` reports ``live: true`` only for an answer of the AQG
service; when the refresh fails (outage, limit, refusal, unexpected answer) the
stored status is returned with ``stale: true`` and ``refreshError``
(``code``, fixed ``message``, ``retryAfter`` for limits).

Errors are tool results with ``isError: true`` and ``{"error": {"code", "message", "retry_after"?}}``, for example
``site_not_available``, ``scan_not_found``, ``scan_not_finished``, ``capability_off``, ``permission_denied``,
``quota_exceeded``, ``installation_offline``, ``user_not_active``, ``connect_not_allowed``, ``module_access_denied``,
``results_too_large``, ``cursor_invalid``.

..  _developer-agent-access-intelligence:

Accessibility Intelligence tools (read-only)
============================================

Four further read-only tools work on AQG's **local** data in TYPO3: pages and the
stored results of AQG's own content checks (rich text, structured fields, the
rendered page HTML), never frontend scan results. They change nothing, start no
scan and call no AI provider.

================================  =====================================  ===============================================
Tool                              Needs capability                       What it returns
================================  =====================================  ===============================================
``aqg_find_pages``                ``findings:read`` or ``content:read``  Pages of a site by title, navigation title or
                                                                         URL path (``query``, ``match``, ``languageUid``);
                                                                         at most 20 per call, paginated. Published
                                                                         pages only, unless ``content:read``: then also
                                                                         unpublished ones, with open content finding
                                                                         counts and when AQG last checked the page
``aqg_list_content_findings``     ``content:read``                       Local content findings of a site or a page,
                                                                         filtered by language, status, severity,
                                                                         source type and rule; at most 50 per call
``aqg_get_finding_context``       ``content:read``                       One finding: record, field, content type,
                                                                         column, position, visibility, stored snippet
                                                                         and element path, what AQG knows and does not
                                                                         know, check times and a backend path
``aqg_get_remediation_guidance``  ``content:read`` or ``findings:read``  AQG rule guidance for a ``ruleId``, or for a
                                                                         content finding (``findingId``, needs
                                                                         ``content:read``) with one concrete step
                                                                         derived only from the stored finding
================================  =====================================  ===============================================

**content:read** is a capability of its own. Agents connected before it existed
keep their capabilities and never get it; a token gets it only when the agent
asks for it *and* the user ticks it on the consent page (it is never ticked in
advance), and an administrator must enable it per site in the Agent access
settings. Content findings can show content that is not published yet (hidden or
scheduled records and pages); workspace drafts are never included.

**Who sees what.** Every finding passes the same predicate as the module's own
lists (``LocalFindingSourceAccessService``): the source record exists, is live,
lies in a table the user may read (``tables_select``), in a language the user
may use, on a page the user may show within the web mounts; a page translation
needs its own permissions. Rules disabled for the site are left out, as in the
module. Pages found by ``aqg_find_pages`` need show access within the web mounts
(a translation also needs its own row); folders, spacers and the recycler are
not listed, and a breadcrumb shows ``…`` for pages the user cannot see. A page,
finding or language outside the user's rights is refused like a missing one
(``page_not_found``, ``finding_not_found``, ``language_not_available``).

**Current, stale or historical.** Every content finding carries
``evidence.state``: ``current`` (open, nothing indicates a change since the
check), ``stale`` (open, but the content changed after the check — content hash
of a rich-text field, otherwise the record's modification time —, the record is
gone, or a newer scan could not re-check the rendered page) or ``historical``
(resolved, ignored or muted). An ignore whose time ran out counts as open, as in
the module (reading never changes the stored status). ``coverage`` tells whether and when a local scan
covered the page and whether its rendered check ran; a page that was never
checked is not reported as clean.

**What is not known.** ``aqg_get_finding_context`` lists ``cause.known`` and
``cause.notKnown`` as fixed sentences per rule. For example, a heading jump
(``rte.heading_hierarchy_jump``) stores the heading that skips a level and both
levels, but not the text of the preceding heading, and headings in other content
elements or the page template are not compared.

**Backend path.** ``navigation.backendPath`` is TYPO3's shareable route path
(``/typo3/record/edit?…`` when the user may edit the field, otherwise the Page
module) without host, route token or session; the Gateway turns it into a link
on the host of the installation's consent URL. TYPO3 asks the user to sign in
and checks the permissions again when it is opened. Installations whose backend
lives in a subdirectory get the path only.

**Untrusted data and minimisation.** Titles, slugs, record titles, stored rule
messages (some quote editor values such as file names or alt texts), element
paths, snippets and selectors are returned under ``untrusted`` and truncated
(snippets 300 characters). Ignore reasons, user names and full field values are never
returned. ``metadataLanguage`` (``en``, ``de``) chooses the language of AQG's
rule titles and guidance; the agent answers in the user's language.

**Capability negotiation.** The connector announces the tools it runs on every
poll; the Gateway lists and relays a tool only when the connector runs it and the
token's capabilities allow it. A connector without the announcement (older
extension) gets the six v1 tools. The announcement grants nothing: the TYPO3
user's rights decide every call.

Results of the new tools are typed (``structuredContent`` with an output schema
declared by the Gateway) and also returned as the same JSON text.

..  _developer-agent-access-results:

What an agent may read of a scan
================================

Reading a scan (its page, or the site root for a site crawl) does not open
every finding in it. For every call, with the user's rights at that moment:

*   A result page is included only when the TYPO3 page its URL belongs to is
    readable (page show permission within the web mounts) in a language the
    user may access — the default language 0 included; a translation record
    has its own permissions. For a result in a translation, the page's
    current live translation in that language must be readable; a
    translation recorded with the result only identifies its owner and must
    still be a live translation of that page in that language (not deleted,
    moved or replaced) and readable itself, otherwise the result is withheld
    from everyone but administrators. The page
    is recorded when AQG stores the results (``owner_*`` columns of
    :sql:`tx_a11y_remote_scan_page`) and is not inferred again from today's
    slugs. A page scan's own URL belongs to its page. A site crawl's URL
    is matched by the site language base and the **exact** page slug; a URL
    whose page cannot be established exactly — unknown or changed slug, another
    host, a route-enhancer path, a deleted page — is withheld, never mapped to a
    parent page. A TYPO3 page id in the query (``?id=3``) decides the page, as
    TYPO3 routes it; a non-numeric one is withheld. A page scan's result URL
    that resolves to another page (a shortcut or redirect the crawler
    followed) also needs that page.
*   An element the crawler mapped to a record is included only when the record
    still exists, its table is readable (``tables_select``), its page is
    readable and its current language is allowed. **Elements without a record mapping are withheld** for every
    user other than an administrator. A finding whose stored elements are all
    withheld is withheld.
*   **Results stored before AQG 3.0 recorded page ownership, and results whose
    page could not be established, are administrators only** until the next
    scan stores them with their page. They are not back-filled from today's
    slugs. Page screenshots are administrators only.
*   Totals, the impact breakdown, top issue types, ``total``/``hasMore`` and
    filters are computed from the same filtered set; a withheld finding is not
    counted anywhere. The crawler's own counters of a site crawl
    (``pagesScanned``, ``pagesTotal``, ``pagesFailed``, ``occurrencesReported``)
    are returned only to administrators (``countsWithheld: true`` otherwise).
*   Administrators read everything natively. A scan with more stored results
    than one call can check (10,000 result pages, 50,000 findings, 200,000
    mapped elements, 5,000 distinct pages) is refused with
    ``results_too_large`` instead of being checked partially.
*   ``aqg_list_scans`` only ever examines scans the user could read (site
    scans with a readable root in an allowed language or of all languages;
    page scans of readable pages of the site in allowed languages, without a
    translation the user may not read — language 0 counts like any other
    language, and a page scan without a recorded language only without a
    language restriction). Scans outside the user's rights change neither the list
    nor ``total``, ``hasMore``, ``searchComplete`` or the presence of
    ``nextCursor``.

Set up (administrator)
======================

#.  Grant the connect permission to the backend groups whose users may connect
    agents (default: nobody, administrators included):

    ..  code-block:: typoscript

        options.a11y_quality_gate.mcp.connect = 1

#.  :guilabel:`Accessibility > Settings > Agent access (MCP)` →
    :guilabel:`Connect to AQG Gateway`. Open the AQG account (the licence
    owner), enter the code TYPO3 shows and confirm. The tab then shows the
    **server URL for agents**.
#.  Turn agent access on per site and choose its capabilities.
#.  Run the connector as a supervised service (systemd, Supervisor, container):

    ..  code-block:: bash

        vendor/bin/typo3 a11y:mcp:connector

    ``--once`` polls once (diagnostics); ``--max-runtime=<s>`` exits after that
    many seconds (default 3600) so the supervisor restarts it with fresh code.
    The runtime also bounds Gateway outages and connector conflicts: retry
    waits never outlast it.

    ``--workers=<n>`` (1–8, default 4) runs agent calls in parallel, each in a
    worker process (``a11y:mcp:connector:worker``, started by the connector;
    never run it by hand). Each poll asks only for as many calls as workers are
    idle; a call that cannot start before the agent stops waiting is answered
    ``installation_busy`` and nothing runs (the agent retries). Workers run
    under the connector's own process identity, so the one-connector rule is
    unchanged. ``--workers=1``, or a host without ``proc_open``, runs calls one
    at a time in the connector process. Each worker is a TYPO3 CLI process:
    plan its memory like the connector's.

Connect Claude Code (user)
==========================

..  code-block:: bash

    claude mcp add --transport http aqg https://<gateway>/mcp/i/<installation-slug>

Then run ``/mcp`` in Claude Code and choose :guilabel:`Authenticate`. The
browser opens the TYPO3 login and the AQG consent page: choose capabilities,
sites and duration (7/30/90 days) and allow. Revoke at any time under
:guilabel:`My connected agents` (module route ``/typo3/module/<web|content>/a11y/mcp/my-agents``)
or ask an administrator (Settings tab, :guilabel:`Revoke`).

A project-scoped ``.mcp.json`` works the same way:

..  code-block:: json

    {
        "mcpServers": {
            "aqg": { "type": "http", "url": "https://<gateway>/mcp/i/<installation-slug>" }
        }
    }

The server URL is not a secret; no token is ever pasted into a configuration.

Supported agents
----------------

The Gateway accepts OAuth public clients with PKCE (S256) whose redirect URI is
``https://…`` or ``http://`` on ``localhost``, ``127.0.0.1`` or ``[::1]`` (any
port), as CLI and desktop MCP clients use. A client that offers only a custom
URI scheme (``cursor://``, ``vscode://`` and the like) cannot register: the
Gateway answers ``invalid_redirect_uri`` with the reason, because any local
application can claim such a scheme. The client name on the consent page is the
one the agent registered itself; allow only a connection you started yourself.

Limits
======

*   Page scans per agent and day and requests per installation and day are set
    on the Gateway (current values are shown in the Settings tab); agent scans
    also count against the frontend-scan allowance of "Scan now", and only one
    frontend scan runs per site at a time.
*   Site crawls, suggestion preparation and applying changes are not part of v1.
*   When the connector is not running, every agent call is refused with
    ``installation_offline``; nothing is queued or replayed.
*   ``installation_busy``: the connector's workers were busy and the call did
    not start before the agent stopped waiting. Nothing ran and the scan quota
    is not used; retry. ``installation_timeout``: the call started in TYPO3 and
    did not answer in time; a started scan keeps running (repeat with the same
    ``idempotencyKey``).
*   ``result_too_large``: the answer exceeds what the Gateway relays (256 KiB);
    ask for a smaller ``pageSize`` or narrow the filter.

Delivery, pairing and clones
============================

*   The connector acknowledges each poll's calls and asks the Gateway to
    **begin** every call immediately before running it. The Gateway checks the
    token (revoked, expired), the installation, the licence, the tool's scope
    and the call deadline at that moment, and a call starts at most once.
    TYPO3 then checks the user, site, page and module rights itself. A call
    that began is never replayed; repeat a scan with the same
    ``idempotencyKey`` to find the scan that started.
*   **Scan idempotency.** An ``idempotencyKey`` is reserved in the database
    (``tx_a11y_mcp_scan_request``, per TYPO3 user and key, with the page and
    language) before the scan is submitted, so every connector process and a
    restarted connector see it. Every attempt for that reservation sends the
    crawler the same ``Idempotency-Key``, derived from the reservation's random
    nonce (keyed with this installation's encryption key), and the crawler
    keeps one run per key: a retry after a timeout or crash gets the run the
    first attempt started. A key reused after its reservation expired is a new
    scan. The same key for
    another page or language is refused (``idempotency_key_reused``).
    The user's current rights are checked first on every call, a repeated key
    included: the page (show and edit), the language and, in a translated
    language, the page's current live translation (show and edit). A user who
    lost any of them gets ``permission_denied`` for a repeated and a new key
    alike, without the earlier job ID; a replay names its scan only while the
    user may read it (otherwise ``scan_not_found``).
    Guarantee: at most one crawler run per user and key. It is not
    exactly-once: an attempt that never reaches the crawler starts nothing, and
    a run whose every answer was lost stays unknown to TYPO3 until the agent
    retries with its key. Without a key, every call is a new scan request
    (the site's one-scan-at-a-time rule still applies). Reservations are kept
    7 days; a page-scan quota may count an uncertain retry twice, never less.
*   A completed pairing is only usable after this installation **activates**
    it with the secret it stored for exactly that pairing. Cancelling in the
    Settings tab cancels at the Gateway first; a completion that arrives after
    a cancel is disconnected and never stored. An earlier connection ends only
    when the new one is activated. Pairing state changes run under a TYPO3
    lock; a TYPO3 cluster needs a shared locking strategy.
*   Every stored connection has its own credential id. An activation answer
    (success or refusal) or a disconnect answer only changes the stored state
    while the credential that sent the request is still stored: a late answer
    about an earlier connection never touches a newer one. Only the Gateway's
    own refusals end a connection (activation: ``connector_unauthorized``,
    ``installation_not_found``, ``pairing_state_changed``,
    ``activation_expired``; disconnect: ``connector_unauthorized``); a rate
    limit, a proxy's or firewall's answer or an outage keeps the secret so the
    step can be repeated.
*   When the Gateway cannot confirm a cancel, the Settings tab says so; the
    local wait ends anyway, and the code can no longer connect this
    installation.
*   **Copies of an installation** (staging refresh, cloned VM). The connector
    and pairing secrets are sealed with a host key in addition to TYPO3's
    ``encryptionKey``: ``AQG_MCP_CONNECTOR_KEY`` (32 bytes, base64) or the file
    ``var/a11y_quality_gate/mcp-connector.key`` (created with the first
    pairing, mode 0600). The key file is used only when TYPO3's ``var/`` path
    is outside the web root (Composer mode). **Classic mode** keeps ``var/``
    under ``typo3temp/`` in the web root, so AQG never writes a key file there
    and pairing is refused until ``AQG_MCP_CONNECTOR_KEY`` is set: generate it
    once (for example ``openssl rand -base64 32``) and set the same value in
    the environment of every PHP process that serves the TYPO3 backend (web
    server or PHP-FPM pool) and of the connector's supervisor. Keep it out of
    any file below the document root and out of version control. Every backend
    web host and the connector host need the same key — set the variable, or
    share the file, on multi-host setups. A
    database copy without that key cannot open the secrets: the Settings tab
    and the connector report the connection as unusable on this host and make
    no Gateway call. A lost key costs a new pairing, never data.
*   The connection also records the installation fingerprint. After
    ``a11y:installation:identity --new-installation`` the copied connection is
    forgotten locally (no Gateway call) and the copy pairs on its own.
*   Every consent this installation decides is recorded locally
    (``tx_a11y_mcp_consent``) before the Gateway issues the token. The
    connector runs a call only for a consent recorded here for that user; a
    token whose consent was decided on a copy is answered ``consent_unknown``,
    the Gateway revokes that one token and e-mails the account owner. A TYPO3
    restored from an older backup refuses tokens consented after the backup in
    the same way; those users consent again.
*   **Full copies** (database, configuration and ``var/`` or the same
    ``AQG_MCP_CONNECTOR_KEY``: a cloned VM or container image, a complete
    restore onto another host) carry everything the original has, including the
    host key. No software check can tell such a copy from the original without
    an identity the copy does not share. The supported procedure is therefore
    operational: exclude ``var/a11y_quality_gate/mcp-connector.key`` and
    ``AQG_MCP_CONNECTOR_KEY`` from clone images, or — on every copy that is not
    the original — stop its connector, run
    ``a11y:mcp:reset-local --confirm --new-host-key`` (drops the stored
    connection and the key file; a configured key must be given a new value by
    the operator). **Never** use ``--new-host-key`` where ``var/`` is shared
    with the original (multi-host setups, a copy that mounts the original's
    storage): it would delete the original's key too, give a separate installation a new identity
    (``a11y:installation:identity --new-installation``) and pair it on its own.
    The one-connector lease refuses a copy's connector while the original polls;
    it cannot stop a copy that runs while the original is offline.
*   Restoring the **same** installation from its own backup keeps its
    connection (same host key and identity); tokens consented after the backup
    are refused as ``consent_unknown`` and revoked, those users consent again.
*   Only one connector process may poll per installation (a random instance
    id per process; ``connector_conflict`` for a second one). If the second one
    runs on a **copy**, stop it there and run ``a11y:mcp:reset-local
    --confirm`` on the copy. **Never disconnect a copy** in its Settings tab:
    it holds the original's credential, and a disconnect ends the original's
    connection and every agent token.

Retention
=========

Decided by the owner on 2026-10-29:

*   **Agent access audit trail** (``tx_a11y_mcp_audit``): 90 days.
    ``mcpAuditRetentionDays`` in the extension configuration changes it
    (1–3650 days; empty means 90). An invalid value deletes nothing and the
    Settings tab reports it. The connector prunes when it starts and every hour
    while it runs, the Settings tab when it shows the trail, and writing
    entries prunes now and then, so the period also holds without a running
    connector; ``a11y:mcp:audit-prune`` serves system cron. Scan history
    keeps its own retention.
*   **Gateway**: revoked or expired token records 30 days after they ended,
    disconnected installations 30 days after the disconnect (see the API's
    ``docs/mcp-gateway.md``). Kept records are history, never access.
*   Scan-request reservations (``tx_a11y_mcp_scan_request``) are technical
    state and expire after 7 days.
*   Consent ledger (``tx_a11y_mcp_consent``): 100 days, longer than the
    longest agent token (90 days); pruned by the connector.

Provisional decisions (not product defaults)
============================================

v1 implements the AQG UX Atlas design (screens 13/14, decision D10) with these
open owner decisions still unsettled. The values below exist so the feature is
safe to test; none of them is an approved product default.

*   **Quotas** (atlas open decisions 9/13): 5000 agent requests per
    installation and day, 20 page scans per agent and day, 60 requests per
    agent and minute. Gateway placeholders, configurable by environment.
*   **Gateway URL and hosting region** (open decision 9): not chosen.
*   **Administrator permission**: administrators need
    ``options.a11y_quality_gate.mcp.connect = 1`` explicitly, like every other
    user; whether admins get it implicitly is open.
*   **"My connected agents"** (open decision 8): a module route, not a User
    settings tab.
*   **Not built from the designs yet**: public (inbound) connection mode, quota
    meters and per-agent caps, CSV export of the audit trail, the token detail
    drawer, the agent activity/approval screens (14 · D) and the in-tab
    sub-navigation.
*   **Postponed capabilities** (open decision 10): site crawls, suggestion
    preparation and applying changes. Not offered to agents in v1.
