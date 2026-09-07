:navigation-title: Quality gate

..  _configuration-quality-gate:

============================
Quality gate (publishing)
============================

The quality gate evaluates the open findings of a page when an editor publishes
or unhides it. It is configured on the :guilabel:`Publishing rules` tab of the
Settings view and stored in the ruleset record :sql:`tx_a11y_ruleset`.

..  _configuration-quality-gate-fields:

Ruleset fields
==============

..  confval:: publish_mode
    :type: int
    :Default: 0

    Behaviour of the gate when a page is published or unhidden.

    ``0``
        Disabled. Nothing happens on publish.
    ``1``
        Warn. The editor sees a warning listing the blocking findings and can
        continue after reviewing it. Available in all editions.
    ``2``
        Block. The publish action is rejected while the thresholds are exceeded.
        Requires an active licence.

..  confval:: threshold_critical
    :type: int
    :Default: 0

    Maximum number of open findings with severity *critical* that a page may
    have. ``0`` means no critical finding is tolerated.

..  confval:: threshold_warning
    :type: int
    :Default: -1

    Maximum number of open findings with severity *warning*. ``-1`` disables the
    warning threshold, so warnings never trigger the gate.

..  confval:: site_identifier
    :type: string
    :Default: (empty)

    Restricts the ruleset to one TYPO3 site. Must match the identifier of the
    site configuration. An empty value marks the global default ruleset.

..  _configuration-quality-gate-behaviour:

How the gate behaves
====================

*   The gate only evaluates findings that are open. Ignored findings do not
    count towards the thresholds.
*   An ignore with an expiry date is temporary. Once the date has passed, AQG
    sets the finding back to the open status, and it counts towards the
    thresholds again exactly like any other open finding. Expect a page that
    passed the gate to start failing it when an ignore expires. See
    :ref:`usage-page-detail-ignore`.
*   The gate uses the findings that are currently stored. A page that has never
    been scanned has no findings and therefore always passes. Schedule regular
    scans so that the gate works on current data; see :ref:`automation`.
*   In warn mode the editor is informed but keeps control over the publication.
    In block mode the DataHandler operation is rejected.

..  warning::
    The quality gate is an editorial safeguard, not a security control, and not
    a conformance statement. A page that passes the gate can still contain
    accessibility problems that automated rules cannot detect.

..  _configuration-quality-gate-sites:

Per-site rulesets
=================

With an active licence, additional rulesets can be created and bound to a site
identifier. The Settings view then offers a site selector, and each site can
have its own thresholds, publish mode, enabled rules and remote scan access
settings. Installations without a licence use the single global default ruleset.
