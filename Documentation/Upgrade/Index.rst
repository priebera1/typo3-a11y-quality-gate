:navigation-title: Upgrade

..  _upgrade:

========
Upgrade
========

..  _upgrade-versioning:

Versioning
==========

AQG follows semantic versioning (``MAJOR.MINOR.PATCH``):

MAJOR
    Incompatible or breaking changes.

MINOR
    Backwards-compatible new functionality.

PATCH
    Backwards-compatible bug fixes.

The changelog of every release is maintained in :file:`CHANGELOG.md` in the
repository.

..  _upgrade-procedure:

Updating the extension
======================

..  code-block:: bash

    composer update priebera/typo3-a11y-quality-gate
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

In Classic installations, update the extension in the Extension Manager and run
the database analyser.

AQG 3.0 adds the tables :sql:`tx_a11y_ai_site_setting`,
:sql:`tx_a11y_ai_usage`, :sql:`tx_a11y_ai_price`, :sql:`tx_a11y_ai_model_price`,
:sql:`tx_a11y_ai_price_catalogue`, :sql:`tx_a11y_ai_budget_lock`,
:sql:`tx_a11y_ai_budget_reservation` and :sql:`tx_a11y_ai_budget_alert` and the
column ``revalidation_required`` to :sql:`tx_a11y_ai_configuration`, the
remediation queue tables :sql:`tx_a11y_remediation_item`,
:sql:`tx_a11y_remediation_event` and :sql:`tx_a11y_remediation_owner`, the
column ``is_page_preview`` to :sql:`tx_a11y_remote_scan` (stored scans stay
public scans), the result ownership columns ``owner_state``,
``owner_page_uid``, ``owner_language_uid`` and ``owner_translation_uid`` to
:sql:`tx_a11y_remote_scan_page`, and the agent access tables
:sql:`tx_a11y_mcp_site`, :sql:`tx_a11y_mcp_audit`,
:sql:`tx_a11y_mcp_scan_request` and :sql:`tx_a11y_mcp_consent` (empty and
unused until agent access is set up), and the column ``conversation_ref`` with
the index ``conversation`` to :sql:`tx_a11y_ai_usage` (Ask AQG counts its AI
questions per conversation; an opaque id, no content) together with
``reserved_cost_micro_usd`` and ``budget_reservation`` (an AI request is
recorded before it is sent); ``extension:setup``
creates them. All changes add tables, columns and indexes; nothing is renamed
or removed. Apply
them before the new code serves requests. Existing AI configurations are not migrated or
changed: a site without a site setting keeps OpenAI, sends images and page
titles as before, and reads its link-text switch from its OpenAI configuration.
Usage recorded before keeps its cost; it is never repriced. Prices entered in a
3.0 release candidate stay stored and are replaced by the automatic prices.

..  important::
    **AI pauses for editors after the upgrade until an administrator acts.**
    AQG 3.0 sends content to a provider only when every item of the readiness
    line on the :guilabel:`AI suggestions` tab holds (see :ref:`configuration-ai-readiness`).
    Two items are new and are not set on an upgraded site:

    *   the **processing acknowledgement** for the site's provider: open
        :guilabel:`Accessibility > Settings > AI suggestions` for each site, tick the confirmation under
        :guilabel:`Data processing and provider terms` and save the privacy
        settings;
    *   a **verified model**: AQG 2.x did not require a passing connection test.
        A site whose last test does not match the current key, model, prompt
        and connection contract must press :guilabel:`Test connection` once.

    Until then editors see no AI buttons and the quiet "AI is paused" line;
    nothing else changes. No budget is set after the upgrade. Prices need no
    setup and no Scheduler task: AQG prices every supported model with its
    shipped list and keeps the prices current from the AQG service's price
    catalogue (see :ref:`configuration-ai-cost`). An installation that cannot
    reach the catalogue yet keeps using the shipped list.

Administrators learn about a new stable release from the update notice in the
AQG modules, see :ref:`usage-overview-update-notice`. The notice only links to
the release notes and these instructions; updating stays a deliberate step in
Composer or the Extension Manager. A development checkout without a release
version, such as ``dev-main``, shows no notice.

..  _upgrade-3-0:

Upgrading to AQG 3.0
====================

AQG 3.0 adds optional AI help for fixing findings (editor AI, image AI and the
:guilabel:`AI fixes` view) and preview scans of hidden pages. Scanning,
findings, the Quality Gate, history and reporting work as in 2.1.

Requirements and service
    Unchanged: TYPO3 13.4 LTS or 14.3+, PHP 8.2+. ``ext-sodium`` is needed to
    store AI provider keys. Scans, licences, AI and reports use the same AQG
    service endpoints as 2.1; no service change is needed for them. Agent
    access (MCP) is the exception: it is off by default and needs the hosted
    AQG MCP Gateway, which is not publicly available yet
    (:ref:`developer-agent-access`).

Database
    Run ``vendor/bin/typo3 extension:setup`` (or the database analyser) for the
    new tables and columns listed in :ref:`upgrade-procedure`. Existing
    settings, scans and findings are kept.

AI
    AI is paused for editors on every site until an administrator confirms the
    data processing acknowledgement and, where needed, runs
    :guilabel:`Test connection` once (see the note in
    :ref:`upgrade-procedure`). Sites that used OpenAI keep it.

Image fixes for editors
    Without :confval:`options.a11y_quality_gate.allowImageRemediation`, editors
    who may edit the content element and the ``alternative`` and
    ``tx_a11y_is_decorative`` fields of :sql:`sys_file_reference` can now
    apply reviewed image texts and mark images decorative. To keep the 2.x
    behaviour, set ``options.a11y_quality_gate.allowImageRemediation = 0`` for
    those groups.

Global settings are administrator-only
    Rules, scan fields and the rendered-check settings apply to every site, so
    only administrators can change them; other users see the Rules and Scan
    fields tabs read-only.

Results follow page and record permissions
    The Overview, its totals and the content site exports leave out pages the
    user may not show, and a site or subtree scan started by a non-administrator
    processes only the pages that user may edit. A stored content finding is
    shown, counted and exported only while its source record exists, is live
    and is readable (table, page, language), whatever page it was stored with.

Frontend results stored before 3.0 are administrators only
    AQG 3.0 records the TYPO3 page of every frontend result when it stores it.
    Results stored by 2.x have no recorded page and are shown to
    administrators only until the next frontend scan; AQG does not guess their
    page from today's slugs. Elements without a record mapping, page
    screenshots and crawler-wide summaries are administrators only as well.
    Other users see :guilabel:`Results limited to your access` (see
    :ref:`usage-overview-restricted-results`). Run a new site scan after the
    upgrade so editors see current results.

Scheduler monitoring tasks report their failures
    A monitoring task without e-mail recipients now fails in the Scheduler
    list ("This monitoring task has no e-mail recipients …", code 1790000011)
    and scans nothing; in 2.1 it ran and its regressions reached nobody. A
    monitoring scan that cannot run (1790000012) and a result whose
    notification e-mail could not be sent (1790000013, the result itself is
    recorded) are failed runs too. After the upgrade, open
    :guilabel:`System > Scheduler`, edit every AQG monitoring task without
    recipients and enter at least one address — or disable the task if
    nobody should be notified.

Workspaces
    Content scans read live records only; the Quality Gate also decides when a
    workspace publish makes a page visible (see
    :ref:`configuration-quality-gate`). Exports cover the language selected in
    the view.

Rollback
    Keep a database backup from before the upgrade. To go back to 2.1, install
    the 2.1 package and restore that backup, or keep the database: 2.1 ignores
    the new tables and columns (do not run the database analyser's "remove"
    actions while 3.0 data should be kept). Frontend results stored by 3.0
    carry their recorded page; 2.1 ignores it and applies its own, less strict
    rules again. AI usage, budgets and the remediation queue are not used by
    2.1. An installation that paired agent access must disconnect it in the
    settings tab before the rollback, because 2.1 has no connector.

The complete list of changes is in :file:`CHANGELOG.md`.

..  _upgrade-2-0:

Upgrading to AQG 2.0
====================

AQG 2.0 redesigns the backend views, the rich-text feedback and the PDF reports.
It needs no configuration change:

*   The requirements are the same as for 1.9: TYPO3 13.4 LTS or 14.3 and later,
    PHP 8.2 and later.
*   The update adds database columns only. Run
    ``vendor/bin/typo3 extension:setup`` (or the database analyser in Classic
    installations) and flush the caches.
*   Rulesets, findings, ignore states, field configuration, the licence, the
    Scanner Token and the AI settings are kept.
*   The next content scan updates findings whose stored snippet format changed
    without reporting them as resolved and new; ignored findings stay ignored.
*   Some frontend scan features, such as the Highlighted / Original screenshot
    switch, need the matching AQG service release. AQG 1.9 installations keep
    working with that service release, so a service update does not require an
    extension update.

The changelog lists every change of the release.

..  _upgrade-after:

After an update
===============

#.  Run :guilabel:`Re-scan TCA` in the Settings view if the release adds rules
    or supports new field types.
#.  Check the :guilabel:`Rules` tab. New rules are available for the first time
    and may need to be reviewed for your project.
#.  Run a full scan so that existing pages are re-evaluated with the new rules.
    Expect additional findings after a release that adds rules; this does not
    mean the site got worse.
#.  If the quality gate runs in blocking mode, run the full scan before the next
    editorial cycle, so that editors are not blocked by findings they have not
    seen yet.

..  _upgrade-typo3:

TYPO3 version upgrades
======================

AQG supports TYPO3 13.4 LTS and TYPO3 14.3 or later. TYPO3 14.0 to 14.2 are
declared as conflicting.

When upgrading TYPO3 13 to 14:

*   The backend module moves from :guilabel:`Web` to :guilabel:`Content`. The
    route identifier stays ``web_a11y``, so backend user group permissions,
    bookmarks and AJAX routes keep working.
*   Scheduler task parameters are unchanged. On TYPO3 14 the fields are
    registered through TCA on :sql:`tx_scheduler_task`, on TYPO3 13 through the
    additional field provider of the task.
*   Rulesets, findings, ignore states and field configuration are kept.

..  _upgrade-fingerprints:

Findings and fingerprints
=========================

Findings are matched across scans by a stable fingerprint. Ignore states survive
rescans as long as the underlying content is unchanged. Editing the content that
produced a finding creates a new finding, which is open again — this is
intentional, because the changed content has to be re-evaluated.

..  _upgrade-2-1-0:

Upgrading to 2.1.0
==================

*   Run the database update (:guilabel:`Analyze Database Structure`, or
    ``vendor/bin/typo3 extension:setup -e a11y_quality_gate``). It adds the
    columns ``screenshot_state`` and ``screenshot_expires_at`` to
    ``tx_a11y_remote_scan_page``; nothing is changed or removed, and pages stored
    by earlier versions keep working ("no screenshot stored" when no image is
    known).
*   The stored frontend scan history is kept indefinitely, as before. Nothing is
    deleted by the update or by saving a period in
    :guilabel:`Settings > Scan history`.
*   Installations without a licence: the FREE Remote Preview must be activated
    once per domain by an administrator (:ref:`usage-remote-scans-free-activation`).
    Until then FREE scans are off. Activation needs the acceptance endpoints and
    published FREE terms on the AQG service.
*   Licensed installations (Trial, PRO, Agency) need no action.
