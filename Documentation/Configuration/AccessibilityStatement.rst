:navigation-title: Accessibility statement

..  _configuration-statement:

========================
Accessibility statement
========================

The :guilabel:`Statement` tab generates a draft accessibility statement from a
completed remote scan and from the information you enter. It requires an active
licence.

..  _configuration-statement-input:

Input
=====

The generator collects, among others:

*   the referenced standard: WCAG 2.1 AA, WCAG 2.2 AA, EN 301 549 or a custom
    value,
*   the declared conformance status: not conformant, partially conformant or
    mostly conformant, with an explicit confirmation checkbox,
*   the applied measures, for example quality assurance, editor training,
    release checks, automated scans, manual reviews and a feedback channel,
*   known limitations and the planned remediation,
*   contact details for accessibility feedback: email, phone, address, expected
    response time and an optional note,
*   compatible and incompatible assistive technologies,
*   the technical specifications used, for example HTML, WAI-ARIA, CSS,
    JavaScript, PDF, media and third-party content,
*   the assessment method: AQG scans, axe-core results, manual review or an
    external audit, with an optional URL to the evaluation report,
*   the approval metadata: organisation, person, role and date,
*   the enforcement procedure: none, generic, Germany, Austria or custom.

..  _configuration-statement-output:

Output
======

The result can be copied as HTML, downloaded as plain text or exported as PDF,
and is meant to be published on a dedicated accessibility statement page of your
site. AQG does not publish the statement for you.

..  warning::
    The generated text is a draft that you must review, complete and approve.
    AQG cannot determine your legal obligations, cannot confirm the conformance
    status of your site and does not produce a legally binding document. The
    conformance status is the one you declare, based on your own assessment —
    including the manual testing that automated scans cannot replace. See
    :ref:`limitations`.
