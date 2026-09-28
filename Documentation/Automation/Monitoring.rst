:navigation-title: Monitoring

..  _automation-monitoring:

=========================================
Scheduled frontend monitoring (PRO, Agency)
=========================================

``a11y:monitor`` scans a site with the AQG frontend scanner and notifies by
e-mail when accessibility gets worse. It needs a PRO or Agency licence for the
site; monitoring several sites in one run needs Agency.

..  code-block:: bash

    vendor/bin/typo3 a11y:monitor --site=main --notify=web@example.org \
        --backend-url=https://cms.example.org

Scheduler task
==============

In the TYPO3 Scheduler, add the task :guilabel:`Accessibility monitoring (AQG
PRO/Agency)` (group *Accessibility Quality Gate*) and run it at the cadence you
want, for example daily or weekly. The task form offers:

:guilabel:`Site`
    The configured TYPO3 sites, as site title, identifier and root page.
:guilabel:`Language`
    The languages enabled for the selected site, by title and locale; the
    default language is marked. Changing the site offers that site's languages
    and selects its default language.
:guilabel:`Notification recipients`
    At least one e-mail address; separate several with commas.
:guilabel:`Maximum pages per scan`, :guilabel:`Wait for the scan (seconds)`
    As ``--max-pages`` and ``--max-wait`` below (defaults 500 and 1200).
:guilabel:`Backend address for links in e-mails`
    As ``--backend-url`` below; leave it empty to use the site's address.

One task monitors one site language; with Agency, create a task per site. Each
run checks the chosen site and language against the site configuration again:
if the site or the language was removed, the run fails with a message in the
Scheduler list and scans nothing.

Options
=======

``--site``
    Site identifier; repeat it for several sites, or use ``all`` (Agency). An
    identifier that names no configured site stops the command.
``--language``
    ``sys_language_uid`` of the site language to scan (default ``0``). It must
    be an enabled language of each site; a site without it is reported and not
    scanned.
``--notify``
    Comma-separated recipients. Without recipients the results are only
    recorded.
``--max-pages``
    Page budget of the monitoring scan (default 500, at most 1000).
``--max-wait``
    Seconds to wait for the scan (default 1200). A scan that is still running
    is evaluated by the next run.
``--backend-url``
    Scheme and host of the TYPO3 backend, for the link in the e-mail. Defaults
    to the site's host.

What is compared and notified
=============================

Each run compares the new scan with its baseline: the scan of the last
monitoring run whose coverage was complete — for the first run, the newest
earlier scan of the same site, language, scan type and start URL whose results
are complete. Only pages that both scans loaded, with all their findings
stored, are compared.

*   **New or worse issues** — an issue type appears on a page, or has more
    occurrences than before: notified, with the site, the scan time, the changes
    and a link to AQG.
*   **Incomplete scan** — the new scan did not check every page the baseline had
    checked (a page is missing, failed to load or its findings were stored only
    in part), or it checked no page at all: notified as incomplete, listing
    those pages. It is never reported as "no change", and its scan does not
    become the next baseline. A page that failed in both scans is a known
    broken URL and does not make a run incomplete.
*   **Failed scan** — notified as a failed scan; nothing was compared.
*   **No change for the worse** on a complete scan, or the first complete scan
    (which becomes the baseline): no e-mail.

The same regression state is not notified twice in a row. The e-mail is in
English, like the PDF exports. The scan uses the site's remote access settings
and the same submit lock as scans started in the backend; while another
frontend scan of the site runs, the monitoring run starts nothing.
