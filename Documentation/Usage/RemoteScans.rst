:navigation-title: Frontend scans

..  _usage-remote-scans:

===============================
Frontend scans (remote crawler)
===============================

Frontend scans are executed by a hosted crawler that renders pages with
Chromium, executes JavaScript and runs axe-core in addition to the AQG rules.
They are managed on the :guilabel:`Frontend scan` tab of the overview.

..  _usage-remote-scans-free:

Free Remote Preview
===================

Installations without a licence key can run a limited number of remote
single-page scans without a licence key, registration or email address. The tab
shows the scans and pages used today, the remaining scans and the time of the
next reset.

The allowance is not defined by the extension. AQG reads the current quota,
usage and reset time from the AQG service and displays them; treat the values
shown in the module as authoritative.

The preview scans the TYPO3 page that is currently selected in the page tree.
Results are stored separately from licensed scan results. Features that are not
part of the preview — page screenshots, TYPO3 record mapping, scan history,
diff tracking and PDF export — are shown as locked.

The crawler runs on the AQG service, outside the TYPO3 installation, and only
scans sites on the public internet. When the base URL of the selected page's
Site is a local, development or internal address — ``localhost``,
``*.ddev.site``, ``.test``, ``.local``, a private IP address and the like — the
tab explains this instead of offering a scan: refusing such addresses is a
security safeguard of the crawler, not a licence limit, and the content scan
keeps working. A site that the crawler refuses during a scan, for example a
host that only resolves in a VPN or internal DNS, gets the same explanation.
Run the preview in an installation whose Site is publicly reachable, such as
staging or production, or select a page of a Site with a public base URL that
this installation serves. There is no way to scan local or private addresses
with the AQG crawler.

Below a Free Remote Preview result, the tab names what a 5-day trial adds —
full-site frontend scans with screenshots, record mapping, scan history and
comparison — and what PRO and Agency add on top: PDF export, acceptance
evidence, monitoring and :guilabel:`Verify fix`. The offer follows the result
and never covers it; :confval:`showProHints` hides it.

..  _usage-remote-scans-licensed:

Licensed frontend scans
=======================

With an active Trial, PRO or Agency licence both scan scopes are available: a
site scan that crawls the site within the page budget of the plan, and a
single-page scan of one selected TYPO3 page. The scope of each run is recorded
and shown in the scan history. Additional features become available:

*   screenshots of the scanned pages,
*   mapping of findings back to TYPO3 records, if the AQG frontend markers are
    active (see :ref:`configuration-site-settings`),
*   scan history with comparison between scans,
*   new and resolved findings per scan,
*   a remediation plan grouped by rule,
*   remote CSV and PDF export.

Before the first licensed scan, configure the crawler access as described in
:ref:`configuration-remote-access`: scanner token for hidden content, basic
authentication for protected environments, excluded URL patterns and priority
URLs.

..  _usage-remote-scans-submit:

How a scan is started
=====================

Start a scan with the scan button on the :guilabel:`Frontend scan` tab. The
target is always resolved on the server from the selected TYPO3 page; the
browser never supplies a scan URL, installation identifier, licence key or
access token.

Only one remote scan per site can run at a time. A second submit while a scan is
active is rejected with a conflict message; wait for the running scan to finish.

A single-page scan needs edit access to the page (for a URL without a TYPO3 page:
to the site root) and the ``showScanNow`` permission; a site scan needs edit
access to the site root and ``showScanAll``. Results follow the same page
permissions: page scans are visible to users who can read the page, site scans
to users who can read the site root.

Comparisons, the regression signal, fix verification and acceptance evidence
only pair compatible scans: same site, scope, scan type, language and start URL,
and the same kind of scan (a Free Remote Preview is never compared with a
licensed scan). A Free Remote Preview result keeps that label after an upgrade:
it has no screenshot and no record mapping.

..  _usage-remote-scans-verify-fix:

Verify fix (PRO, Agency)
========================

On the frontend page detail, each finding offers :guilabel:`Verify fix`. AQG
scans that page again and reports the outcome next to the finding:

*   **Resolved** — the fresh scan of the same language completed, the page
    loaded, every issue type the scanner reported for it was stored, and the
    rule is not among them.
*   **Still present** — the rule is still reported, with the number of
    occurrences.
*   **Not verified** — the scan failed or was cancelled, it checked another
    language, the page did not load or is missing from the result, or fewer
    issue types were stored for the page than the scanner reported. A finding
    that is merely absent from incomplete results is never marked resolved.

The verification records the scan that decided it. It uses the same scan
permission, submit lock and one-scan-per-site rule as :guilabel:`Scan this page`.

..  _usage-remote-scans-acceptance:

Acceptance evidence (PRO, Agency)
=================================

When two compatible scans are compared, the comparison offers the acceptance
evidence as PDF and CSV: baseline and current scan dates and coverage, fixed,
new, worse and still open findings, the pages that could not be compared, and
the limits of automated testing. It is evidence of automated results, not a
statement of WCAG conformance. The PDF is not tagged for screen readers; the CSV
contains the same evidence, see :ref:`limitations-pdf`.

..  note::
    The crawler requests your site from the public internet. Installations that
    are not reachable from outside, or that block unknown user agents, cannot be
    scanned remotely.
