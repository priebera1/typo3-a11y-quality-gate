:navigation-title: Overview module

..  _usage-overview:

==================
Overview module
==================

Open the **Accessibility** module in the TYPO3 backend. On TYPO3 13 it is below
:guilabel:`Web`, on TYPO3 14 below :guilabel:`Content`.

..  figure:: /Images/overview.png
    :alt: The AQG overview module showing issue counts by severity and a table
          of pages with their critical, warning and info findings.

    The overview lists every scanned page with its open findings.

..  _usage-overview-first-steps:

First steps
===========

Without a selected page, the module explains how AQG works: select a page of a
TYPO3 Site in the page tree, run a content scan, then check what visitors'
browsers render with a frontend scan. Until the first content scan of a site,
the :guilabel:`Content scan` tab shows the same steps next to the scan buttons.
The content scan runs inside the installation and needs no licence key.

Installations without a licence learn about the Free Remote Preview there: a
browser-based scan of single pages on the AQG service without licence key,
account or email, for sites that are reachable from the internet. After the
first content scan, a short pointer on the :guilabel:`Content scan` tab opens
it; the pointer disappears once the site has a Free Remote Preview result.
Licensed installations are not shown these Free steps.

..  _usage-overview-update-notice:

Update notice
=============

When a newer stable AQG release is available, administrators see one line at
the top of the Overview and the Settings view, for example
:guilabel:`AQG 1.9.9 is available · You're using 1.9.8.`, with links to the
release notes and the update instructions. The close button hides the notice of
that release for the administrator; the choice is stored in their backend user
settings, so other administrators still see it and a later release appears
again. AQG never downloads or installs an update itself, see
:ref:`upgrade-procedure`.

..  _usage-overview-tabs:

Content scan and Frontend scan
==============================

The overview has two tabs:

:guilabel:`Content scan`
    Findings produced by the local rules (``rte.*``, ``structured.*``) and by
    the rendered page check (``rendered.*``). Available in all editions.

:guilabel:`Frontend scan`
    Findings produced by the hosted browser crawler. Shows the Free Remote
    Preview status in the Free edition and the full scan history with an active
    licence. See :ref:`usage-remote-scans`.

Both tabs offer filtering by site, language, status and severity, a search over
pages, and paging. New findings compared to the previous scan are marked as
*new*.

..  _usage-overview-actions:

Actions
========

:guilabel:`Scan site`
    Scans the whole page tree of the selected site: the local rules for every
    page, plus the rendered page check for the supported frontend pages of that
    subtree. Can be hidden for non-administrators with the TSconfig option
    :confval:`options.a11y_quality_gate.showScanAll`.

:guilabel:`Scan this page`
    Scans a single page with the local rules and the rendered page check, see
    :ref:`usage-rendered-checks`. Can be hidden for non-administrators with
    :confval:`options.a11y_quality_gate.showScanNow`.

:guilabel:`Settings`
    Opens the configuration described in :ref:`configuration`.

Export buttons produce CSV of local findings in all editions. CSV of frontend
scan results and PDF of either require an active licence, see
:ref:`usage-reports`.

..  _usage-overview-toolbar:

Toolbar item and page module indicator
======================================

AQG also adds an item to the backend toolbar that shows the accessibility state
of the current page and offers a direct scan. It can be hidden for
non-administrators with
:confval:`options.a11y_quality_gate.showToolbarItem`.

In the :guilabel:`Page` module, an indicator on content elements links directly
to the findings of that element. While a scan of the page is running, also one
started in the Accessibility module, the page's indicator shows its progress
and updates itself with the result once the scan has ended.
