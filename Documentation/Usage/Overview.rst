:navigation-title: Overview module

..  _usage-overview:

==================
Overview module
==================

Open the **Accessibility** module in the TYPO3 backend. On TYPO3 13 it is below
:guilabel:`Web`, on TYPO3 14 below :guilabel:`Content`. Select a page of a TYPO3
site in the page tree; the Overview shows the site of that page.

..  figure:: /Images/overview.png
    :alt: The Accessibility Overview in TYPO3 14 with the tabs Overview and
          AI fixes, a summary "AI fixes: 4 waiting for review", the Content
          scan tab with "204 open issues on 10 pages" by severity, the buttons
          Scan this page, Scan site and Export, and a table of pages with open
          issues.

    The Overview summarises the open issues of a site and lists every page with
    open issues, sorted by severity.

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
:guilabel:`AQG 3.0.1 is available · You're using 3.0.0.`, with links to the
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
    the rendered page check (``rendered.*``): open issues by severity, the last
    scan, and the pages with open issues (:guilabel:`Page`, severities,
    :guilabel:`Total`). Available in all editions. Only pages the user may show
    are listed and counted.

:guilabel:`Frontend scan`
    Findings produced by the hosted browser crawler: occurrences and affected
    pages, :guilabel:`What to fix first` with the issue types ordered by impact
    and reach, affected pages, reports, a manual review checklist and the scan
    history. Shows the Free Remote Preview status in the Free edition. See
    :ref:`usage-remote-scans`.

..  figure:: /Images/overview-frontend-scan.png
    :alt: The Frontend scan tab: "Critical · 177 occurrences · 7 affected
          pages", 10 priority fixes, the note that automated checks cover part
          of WCAG and manual review is still required, the open Export menu
          with Export CSV and Export PDF, and the list What to fix first.

    The Frontend scan tab with What to fix first and the Export menu.

Counts follow AQG's terms: :guilabel:`Issue types` are distinct rules,
:guilabel:`Occurrences` are individual instances on pages. New findings
compared to the previous compatible scan are marked as *new*.

With AI set up, a line above the tabs summarises the :guilabel:`AI fixes` of the
site (waiting for review, saved this week, verified by re-check) and links to
:ref:`usage-ai-fixes`.

..  _usage-overview-actions:

Actions
========

:guilabel:`Scan site content`
    Scans the whole page tree of the selected site: the local rules for every
    page, plus the rendered page check for the supported frontend pages of that
    subtree. A scan started by a non-administrator processes only the pages
    that user may edit. Can be switched off for a group with
    :confval:`options.a11y_quality_gate.showScanAll`.

:guilabel:`Scan this page`
    Scans a single page with the local rules and the rendered page check, see
    :ref:`usage-rendered-checks`. Can be switched off for a group with
    :confval:`options.a11y_quality_gate.showScanNow`.

:guilabel:`Export`
    CSV of local findings in all editions. CSV of frontend scan results needs a
    licence that includes frontend scans; PDF needs PRO or Agency. See
    :ref:`usage-reports`.

:guilabel:`Settings`
    Opens the configuration described in :ref:`configuration`.

..  _usage-overview-toolbar:

Toolbar item and Page module
============================

The AQG item in the backend toolbar shows the state of the site you are
working on. When you open it, it shows, in this order:

#.  :guilabel:`Accessibility` and the site.
#.  :guilabel:`Ask AQG` for what you are working on (see
    :ref:`usage-overview-toolbar-assistant`). No findings are listed when it
    opens.
#.  :guilabel:`Content scan`, collapsed. Its header shows a summary, for
    example "2 new · 300 open", and the scan's status (Complete, Running,
    Failed, Out of date, Not scanned). Opened, it shows what changed in the
    last content scan, when it ran and its progress while it runs. For the
    page you have open, or the page of the content element you are editing,
    it offers :guilabel:`Check saved page now` if you may scan that page
    (:confval:`options.a11y_quality_gate.showScanNow` and edit access to the
    page). The result appears in the section; the saved content is checked,
    not unsaved changes in the form.
#.  :guilabel:`Frontend scan`, collapsed in the same way, with the latest
    frontend scan's occurrences, coverage and progress. Frontend scans are
    started in the Accessibility module and in the Page module panel.
#.  Two buttons: :guilabel:`Open Accessibility` opens the Accessibility
    module; :guilabel:`Open MCP` opens :guilabel:`My connected agents` (see
    :ref:`developer-agent-access`). Open MCP appears only when agent access is
    usable for you: :confval:`options.a11y_quality_gate.mcp.connect` is set for
    you, you have the Accessibility module and the installation is connected
    to the AQG MCP Gateway. Agent access is not publicly available in 3.0.0,
    so the button is normally not shown.

The item refreshes while it is open. It can be hidden for a group with
:confval:`options.a11y_quality_gate.showToolbarItem`.

..  figure:: /Images/toolbar-status.png
    :alt: The opened AQG toolbar item on the page "Problems page": Ask AQG
          with the choice This page or This website, the question field and
          the Ask button; below it the collapsed sections Content scan
          "8 new · 208 open, Complete" and Frontend scan "177 occurrences,
          Complete"; at the bottom the buttons Open Accessibility and Open MCP.

    The toolbar item opens with Ask AQG; the scans are collapsed below it.
    Open MCP appears only where agent access is usable for you.

..  _usage-overview-toolbar-assistant:

Ask AQG
-------

:guilabel:`Ask AQG` names what you are working on — the page shown in the
Page module, the content element open in the editing form or a file in the
Filelist, with its language — and notes unsaved changes or a workspace (whose
findings describe the live site). Type a question and choose
:guilabel:`Ask`; the answer appears below the field.

Answers from your findings
    Questions such as "What should I fix first?", "Why does this matter?" or
    "Where can I fix the missing alt text?" are answered from your stored AQG
    findings and AQG's guidance, without AI and at no cost, also without a
    licence. Identical findings are grouped with their number of occurrences,
    and each group links to :guilabel:`Details` in the page details and, where
    you may edit it, :guilabel:`Edit`. With access to the site root you can
    ask about the whole website instead of this page.

Answers with AI
    Where AI is set up for you (see :ref:`configuration-ai`), other questions
    make one structured request with the site's provider, key, budget, usage
    recording and privacy settings. Below the field AQG shows how many AI
    questions and how much estimated cost the conversation has used, and each
    AI answer lists :guilabel:`What was sent`. AI answers explain and point to
    findings; they never change
    content. To change content, use :guilabel:`Fix with AI` in the page
    details, which prepares a change for review in :ref:`usage-ai-fixes`.

The conversation stays in your browser: AQG stores no question or answer, and
only your last three AI questions and answers are sent back with a new
question. It belongs to your login and to the page, record or file it was
asked about: after a logout another user in the same browser tab does not see
it, and a page without its own conversation starts empty. AQG accepts earlier
answers only as it signed them for that conversation. AQG enforces these limits on the server: 400 characters per
question, one AI request of at most 45 seconds per question, 10 AI questions
and $0.05 per conversation (the amount is shown only when the model has a
price), 60 AI questions per user, site and day, and one AI question per user
at a time. Every AI request counts from the moment it is sent; if AQG cannot
record it, it is not sent. Answers from your findings are not limited.

Ask AQG uses only what you may read now: a page, record, file or translation
you may not open, a record of another workspace or a language you may not use
gives the same neutral "not available" answer as a record that does not exist,
and every question checks your access again. It never falls back to another
language you may not use.

..  _usage-overview-restricted-results:

Results limited to your access
------------------------------

Every native view, export and notification shows a finding only while you may
read its source: content findings while their record exists, is live and lies
in a table, language and page you may read (the page a finding was stored with
is not enough); frontend findings while the TYPO3 page and record they were
recorded for are readable. Frontend results without such a page or record,
including results stored before AQG recorded page ownership (AQG 3.0), page
screenshots and crawler-wide summaries are shown to administrators only.

When a frontend scan's results are limited this way, the Overview, toolbar,
page details, PDF reports and the scan notification say
:guilabel:`Results limited to your access` and count only what you may read.
They never describe such a scan as clean or as a scan that checked no page,
and the wording is the same whatever was withheld. A scan that really loaded
no page still says so.

In the :guilabel:`Page` module, an :guilabel:`Accessibility` panel above the
content shows the page's content scan and frontend scan separately, each with
its own counts and scan time, who fixes what (owners), how many issues AI can
prepare a fix for, and the actions :guilabel:`Open issues` and :guilabel:`Scan
again`. While a scan of the page is running, also one started in the
Accessibility module, the panel shows its progress and updates itself with the
result. A hidden page is marked :guilabel:`Not public`.

..  figure:: /Images/page-module-status.png
    :alt: The Page module of the page "Visit the library" with the
          Accessibility panel: accessibility issues found, content scan
          "2 critical, 3 warnings, 7 needs review", frontend scan
          "11 occurrences", owners "4 Editor, 8 Developer", "3 can be fixed
          with AI" and the buttons Open issues and Scan again.

    The Accessibility panel in the Page module keeps content and frontend
    results apart.
