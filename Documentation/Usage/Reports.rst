:navigation-title: Reports and exports

..  _usage-reports:

===================
Reports and exports
===================

..  _usage-reports-csv:

CSV export
==========

CSV export of local findings — content scan and rendered page check — is
available in all editions, for the overview and for a single page. The export
contains the finding data as shown in the module: page, rule, severity, status,
source record and field.

Like the view it comes from, the export covers the content language selected in
the module (the PDF names it). Content scans read live records only; drafts in
a workspace are not scanned.

CSV export of frontend scan results requires an active licence that includes the
remote crawler. Free Remote Preview results cannot be exported.

..  _usage-reports-pdf:

PDF export
==========

PDF export requires an active PRO or Agency licence (a Trial does not include
it) and a Composer installation, which installs the ``mpdf/mpdf`` library. The
TER package for Classic mode does not include it: there the export menus offer
CSV and say that PDF needs Composer (see :ref:`installation-classic`). It is
available for the overview and for the page detail of local and remote results,
and produces a formatted accessibility findings report with summary figures and
a per-page breakdown.

The PDF is a visual report and is not tagged for screen readers. It declares
its language and title and has a bookmark outline of its sections, but its
headings, tables and reading order are not available to assistive technology.
The CSV export contains the same findings and is the accessible copy; the export
menu and the first page of the PDF say so. See :ref:`limitations-pdf`.

..  important::
    The PDF is a findings report of automated checks. It is not an audit report,
    not a conformance statement and not a legal compliance document. Use it as
    input for your accessibility work, not as evidence of conformance. See
    :ref:`limitations`.

..  _usage-reports-acceptance:

Acceptance evidence
===================

With PRO or Agency, a baseline and a current compatible frontend scan can be
exported as acceptance evidence (PDF or CSV): fixed, new, still open and not
compared findings. See :ref:`usage-remote-scans-acceptance`.

..  _usage-reports-statement:

Accessibility statement
=======================

The accessibility statement generator is described in
:ref:`configuration-statement`.
