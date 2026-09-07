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

..  note::
    The crawler requests your site from the public internet. Installations that
    are not reachable from outside, or that block unknown user agents, cannot be
    scanned remotely.
