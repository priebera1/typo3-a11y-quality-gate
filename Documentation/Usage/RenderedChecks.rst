:navigation-title: Rendered page checks

..  _usage-rendered-checks:

=====================
Rendered page checks
=====================

The rendered page check evaluates the final HTML that TYPO3 delivers for one
page. It is available in all editions and finds problems that do not exist in a
single content record, such as a missing ``lang`` attribute, a missing page
title, a missing ``main`` landmark or duplicate ``id`` values produced by
different content elements.

..  _usage-rendered-checks-run:

Running a rendered check
========================

Whether a scan includes the rendered page check depends on how it was started.

..  list-table::
    :header-rows: 1

    *   -   Scan
        -   Rendered page check

    *   -   :guilabel:`Scan this page` in the backend module
        -   yes, for the selected page

    *   -   :guilabel:`Scan site` in the backend module
        -   yes, for the supported pages of the scanned subtree

    *   -   CLI ``a11y:scan --page-uid=...``
        -   yes, for that page

    *   -   CLI ``a11y:scan --root-pid=...``
        -   no

    *   -   Scheduler task with a page UID
        -   yes, for that page

    *   -   Scheduler task with a root page (subtree)
        -   no

Independently of the entry point, the rendered page check is skipped when:

*   the scan runs in changed-only mode,
*   the rendered page check is disabled in the ruleset settings,
*   the page has a doktype that does not deliver a frontend page, for example a
    folder, a shortcut or a link to an external URL.

AQG requests each page from its own frontend with a one-time nonce, so that the
check runs against exactly the page and language that was scanned. If a scanner
token is configured, hidden pages and hidden content are included; see
:ref:`configuration-remote-access-token`.

..  _usage-rendered-checks-limits:

What the rendered check does not do
===================================

The rendered check evaluates static, server-rendered HTML. It does **not**:

*   execute JavaScript,
*   wait for AJAX requests or lazy-loaded content,
*   interact with cookie banners or other overlays,
*   take screenshots,
*   run axe-core,
*   follow links or crawl further pages — the checker evaluates one page at a
    time, and a subtree scan simply repeats it per page.

For checks that need a real browser, use the frontend scan described in
:ref:`usage-remote-scans`.
