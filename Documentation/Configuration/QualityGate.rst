:navigation-title: Quality gate

..  _configuration-quality-gate:

============================
Quality gate (publishing)
============================

The quality gate evaluates the open findings of a page when an editor publishes
or unhides it, that is, when a hidden page record is made visible. It is configured on the :guilabel:`Quality Gate` tab of the
Settings view (administrators) and stored in the ruleset record :sql:`tx_a11y_ruleset`.

..  figure:: /Images/settings-quality-gate.png
    :alt: Settings, tab Quality Gate: the scope choice between Global
          configuration and Per-site configuration, and a site override for
          the site main with the mode "Block publish", 0 critical issues
          allowed and warnings ignored.

    The Quality Gate tab with a per-site override in blocking mode.

..  _configuration-quality-gate-fields:

Ruleset fields
==============

..  confval:: publish_mode
    :type: int
    :Default: 1

    Behaviour of the gate when a page is published or unhidden. A new
    installation warns (``1``) from the start, with the thresholds below, also
    before an administrator has opened or saved :guilabel:`Settings`.

    ``0``
        Disabled. Nothing happens on publish.
    ``1``
        Warn. The page is published (or unhidden) as requested; right after
        that the editor sees a warning with the thresholds that failed, the
        most severe rules on the page and where to fix them. Nothing is asked
        or confirmed beforehand. Available in all editions.
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
*   In warn mode the page is published and the editor is informed afterwards.
    In block mode the DataHandler operation is rejected.
*   The gate runs when a hidden page is made visible: unhiding it in the page
    tree or list, or saving its page properties with the page set to visible.
    Saving a page that is already visible, for example after changing its title,
    does not run the gate again, so a routine edit never takes an online page
    offline. A translated page is judged in its own language when the
    translation is made visible. Changes to content elements never run the
    gate; they give the editor feedback on the changed element.
*   The gate also decides when a workspace is published (a record, a
    selection, a stage or the whole workspace, including auto-publish) and the
    request makes a page visible. AQG checks the content this request puts
    live on that page before anything is written. In blocking mode with an
    active licence nothing of the request is published; in warning mode, or
    without a licence, it is published with a warning. Saving inside a
    workspace runs neither the content feedback nor the gate.
*   The gate applies to everyone who publishes or unhides a page, including
    backend users without access to the Accessibility module. It never grants
    access to the module.

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
