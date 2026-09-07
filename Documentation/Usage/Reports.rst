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

CSV export of frontend scan results requires an active licence that includes the
remote crawler. Free Remote Preview results cannot be exported.

..  _usage-reports-pdf:

PDF export
==========

PDF export requires an active licence and the ``mpdf/mpdf`` library. It is
available for the overview and for the page detail of local and remote results,
and produces a formatted accessibility findings report with summary figures and
a per-page breakdown.

..  important::
    The PDF is a findings report of automated checks. It is not an audit report,
    not a conformance statement and not a legal compliance document. Use it as
    input for your accessibility work, not as evidence of conformance. See
    :ref:`limitations`.

..  _usage-reports-statement:

Accessibility statement
=======================

The accessibility statement generator is described in
:ref:`configuration-statement`.
