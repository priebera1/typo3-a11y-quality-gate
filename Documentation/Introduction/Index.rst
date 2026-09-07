:navigation-title: Introduction

..  _introduction:

============
Introduction
============

Accessibility Quality Gate (AQG) is a TYPO3-native accessibility checker. It
runs inside the TYPO3 backend and stores its findings in TYPO3 tables. Findings
from the content scan carry the source record and field that produced them.
Rendered and crawler findings are mapped back to a TYPO3 record where enough
mapping information is available, and otherwise fall back to the page or URL
they were found on.

..  _introduction-what-it-does:

What AQG checks
===============

AQG runs three different kinds of checks.

Content scan (local)
    Rules are applied to data that is already stored in TYPO3: RTE bodytext
    (:php:`rte.*` rules) and structured TCA field values such as file reference
    metadata, content element headers and form field configuration
    (:php:`structured.*` rules). No frontend request is required.

Rendered page check
    AQG requests the final server-rendered HTML from the TYPO3 frontend and
    applies the :php:`rendered.*` rules to it. This finds problems that only
    exist in the assembled page, for example a missing ``lang`` attribute or
    duplicate ``id`` values across content elements. The checker evaluates one
    page at a time; a site scan repeats it for the supported pages of the
    subtree. See :ref:`usage-rendered-checks` for which scans include it.

Frontend scan (remote)
    A hosted browser-based crawler renders pages with Chromium, executes
    JavaScript and runs axe-core. This is the only check type that sees
    client-side rendered content.

Findings from all three check types appear in the **Accessibility** backend
module, share the same ignore workflow and use stable fingerprints so that a
finding keeps its state across rescans.

..  _introduction-editions:

Free and licensed features
==========================

The extension is licensed under GPL-2.0-or-later and installs without a licence
key. Some features contact the AQG service at ``https://api.priebera.sk`` and
require an active licence key.

..  list-table:: Feature availability
    :header-rows: 1

    *   -   Feature
        -   Free
        -   Trial / PRO / Agency

    *   -   CKEditor inline highlighting
        -   yes
        -   yes

    *   -   Content scan (RTE and structured rules)
        -   yes
        -   yes

    *   -   Rendered page check
        -   yes
        -   yes

    *   -   Backend overview, page detail, ignore workflow
        -   yes
        -   yes

    *   -   CLI and Scheduler scans
        -   yes
        -   yes

    *   -   CSV export of local findings
        -   yes
        -   yes

    *   -   CSV export of frontend scan results
        -   no
        -   yes

    *   -   Quality gate, warning mode
        -   yes
        -   yes

    *   -   Quality gate, blocking mode
        -   no
        -   yes

    *   -   Free Remote Preview (limited daily browser scans)
        -   yes
        -   not applicable

    *   -   Full frontend crawler scans with axe-core
        -   no
        -   yes

    *   -   Remote screenshots and TYPO3 record mapping
        -   no
        -   yes

    *   -   Scan history and diff tracking
        -   no
        -   yes

    *   -   PDF export
        -   no
        -   yes

    *   -   Accessibility statement generator
        -   no
        -   yes

    *   -   AI-assisted text suggestions (bring your own OpenAI key)
        -   no
        -   yes

    *   -   Per-site rulesets and multi-site support
        -   no
        -   yes

Plan details, trial access and pricing are documented on the product website:

*   `Product page <https://typo3.priebera.sk/products/accessibility-quality-gate>`__
*   `Pricing <https://typo3.priebera.sk/pricing>`__
*   `Free trial <https://typo3.priebera.sk/trial>`__

..  _introduction-scope:

Scope and non-goals
===================

..  important::
    AQG identifies common accessibility problems. It does not guarantee, certify
    or prove conformance with WCAG, the European Accessibility Act (EAA), the
    German BFSG, BITV or any other accessibility legislation.

Automated checks can only detect machine-detectable problems. Colour meaning,
reading order, focus order, keyboard operability, plain language, correct
alternative text content and assistive technology behaviour still require human
review. Some rules encode best practices and may not be a hard failure in every
context, so every finding needs to be reviewed in its context.

See :ref:`limitations` for the detailed boundaries of each check type.

..  _introduction-terminology:

Terminology
===========

Finding / issue
    A single rule violation, bound to a rule ID, a page, a source record and a
    field. Stored in :sql:`tx_a11y_issue` for local and rendered checks, and in
    :sql:`tx_a11y_remote_issue` for frontend scans.

Ruleset
    A configuration record (:sql:`tx_a11y_ruleset`) holding quality gate
    thresholds, enabled rules and remote scan access settings. One default
    ruleset is created automatically; further rulesets can be bound to a site
    identifier.

Quality gate
    The check that runs when an editor publishes or unhides a page. Depending on
    the configured ``publish_mode`` it does nothing, warns, or blocks the action.

Fingerprint
    A stable identifier derived from the rule, the source record and the matched
    context. It lets AQG recognise the same finding across rescans and keep its
    ignore state.
