:navigation-title: Rules

..  _configuration-rules:

=================
Enabling rules
=================

The :guilabel:`Rules` tab lists all built-in rules with their category,
severity and WCAG reference, and lets you enable or disable each rule
individually. The enabled state is stored as JSON in the ``rules_json`` column
of the active ruleset.

Disabled rules are skipped during content scans, rendered page checks, CLI runs
and Scheduler runs. Findings that were produced by a rule before it was disabled
are removed on the next scan of the affected pages.

For the full list of rule identifiers see :ref:`rule-reference`.

..  _configuration-rules-when-to-disable:

When to disable a rule
======================

Typical reasons to disable a rule:

*   The rule targets a markup pattern that your site templates never produce.
*   A rule reports a best practice that conflicts with an agreed editorial
    convention, for example ``rte.link_to_document_missing_notice``.
*   A rule produces findings that are handled by another tool in your workflow.

If a rule is generally useful but wrong on a single page or for a single site,
prefer the ignore workflow instead of disabling the rule globally; see
:ref:`usage-page-detail-ignore`.
