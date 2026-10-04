:navigation-title: Licence

..  _configuration-licence:

==================
Licence activation
==================

The Free feature set works without any licence key. A licence key unlocks the
features listed in :ref:`introduction-editions`.

..  _configuration-licence-key:

Entering the licence key
========================

The licence key is stored in the extension configuration of
``a11y_quality_gate``.

..  confval:: licenceKey
    :type: string
    :Default: (empty)

    The AQG licence key, for example
    ``aqg_live_xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx``. Trial keys use the
    ``aqg_trial_`` prefix.

..  confval:: showProHints
    :type: boolean
    :Default: 1

    Whether a Free Remote Preview result is followed by the upgrade offer with
    trial and pricing links. Plan badges and notes on locked features stay
    visible. Can be overridden per ruleset from the Settings view.

There are two ways to set the key:

*   In the :guilabel:`Licence` tab of the AQG Settings view. The tab is only
    available to administrators and writes into the extension configuration.
*   In :guilabel:`Admin Tools > Settings > Extension Configuration >
    a11y_quality_gate`, or directly in
    :file:`config/system/settings.php` under
    ``EXTENSIONS/a11y_quality_gate/licenceKey``.

In the :guilabel:`Licence` tab, paste the key and press
:guilabel:`Save and validate`. AQG stores the key, contacts the licence service
and shows the resolved plan, the bound domains, the expiry date and the limits
of the plan. To check a saved key again, for example after activating a domain
in the customer portal, press :guilabel:`Revalidate` next to the key. Below the
key, :guilabel:`Licence domains` lists the domains of this installation's sites
and which of them the licence covers, see :ref:`configuration-licence-domains`.

The tab never shows the saved key in full: only its beginning and last
characters are displayed, so it does not appear in screen shares or
screenshots. To use another key, enter it in the empty key field and save; an
empty field keeps the saved key. To remove the key, select
:guilabel:`Remove the saved key when saving` and save; AQG then runs as Free.

..  _configuration-licence-domains:

Licence domains
===============

The :guilabel:`Licence domains` table in the :guilabel:`Licence` tab (for
administrators, once a key is saved) and the customer portal show the same
list, kept by the AQG service. A change in either place applies at once; the
other shows it after a reload or :guilabel:`Refresh`. Frontend scans and
scanner access are always checked by the service, so a page that was not
reloaded cannot grant access.

The table lists every public domain of the installation's sites and the
activated domains of the licence:

*   :guilabel:`Active` — the licence covers the domain.
*   :guilabel:`Available` — a site of this installation uses the domain, but it
    is not activated. Activate it here or in the portal.
*   :guilabel:`Active, not detected` — activated, but no site of the
    installation uses it any more. It keeps its slot until you deactivate it:
    removing a site never frees a slot.
*   :guilabel:`Unavailable` — the plan has no free slot, or the trial already
    covers its one domain.

Only a domain that a site of the licensed installation uses can be activated,
in the Licence tab and in the portal alike; the portal does not accept typed-in
domains. The table has a summary, a search, filters for each state and 10
domains per page; several selected domains of a page can be activated or
deactivated at once.

..  list-table::
    :header-rows: 1
    :widths: 15 85

    *   -   Plan
        -   Domains
    *   -   Trial
        -   One domain: the first public domain the trial is used on. It cannot
            be changed; other sites need PRO.
    *   -   PRO
        -   Up to three domains. The first is activated automatically, the
            others when you activate them. An activated domain keeps its slot
            for 90 days before it can be deactivated, so slots cannot be
            rotated, and the last active domain cannot be deactivated.
    *   -   Agency
        -   No domain limit. The first domain of each project is activated
            automatically. :guilabel:`Activate all detected` activates every
            domain the installation reports at once.

Domains activated before AQG 1.9.8 stay activated. Versions 1.9.3 to 1.9.7
report only the hosts of their site bases and have no :guilabel:`Licence
domains` table: activate further domains of those installations in the
customer portal.

..  _configuration-licence-validation:

How validation works
====================

*   The extension calls ``https://api.priebera.sk`` with the licence key and a
    site fingerprint derived from the domain of the installation.
*   The result is cached. Valid results are cached for one hour, invalid results
    for five minutes, trial results for fifteen minutes — never beyond the end
    of the licence or trial that the service reported.
*   Licences cover activated domains. Every licensed request reports all
    TYPO3 sites of the installation with their hosts (site base and language
    bases), and the AQG service covers a domain only when it is activated for
    the licence and a site of the reporting installation uses it. A domain that
    a site uses but that is not activated is reported as
    ``domain_not_activated``; a domain that no site of the installation uses as
    ``domain_not_detected``; a domain beyond the plan's limit as
    ``domain_limit_reached``. Each is refused on its own: the activated domains
    keep working. Development hosts such as ``localhost``, ``*.ddev.site`` or
    ``*.test`` need no activation and take no slot; the hosted scanner still
    cannot reach them, see :ref:`troubleshooting-remote`.
*   PRO and trial licences cover one TYPO3 installation (project). Another
    installation using the same key is reported as ``project_mismatch``.
    With AQG 1.9.7 or later, PRO recognises its installation by the
    installation's own id, so the licensed installation can add or remove
    TYPO3 sites and activate their domains within its domain limit; another
    installation is refused even
    if it is configured with the same domains. Older versions are recognised
    by the domains of their sites. If the licensed installation was moved or
    set up again, contact support to reset its registration. A trial covers
    one site domain.
    An Agency licence covers several client installations: each one is enrolled
    as its own project on its first validation, up to the licence's project
    limit (``project_limit_reached``), and keeps its own frontend scans, history
    and evidence. A project belongs to its TYPO3 installation, not to its site
    list, so no other installation can take it over. Additional projects need
    AQG 1.9.7 or later on that installation; older versions keep using the
    licence's first project. The :guilabel:`Licence` tab shows the projects in
    use; free a slot in the customer portal. A removed project is reported as
    ``project_removed`` and stays removed until you restore it in the portal,
    with its previous scan history.
*   Trial keys do not start their runtime when they are issued. The trial window
    starts on the first successful validation from a production domain;
    validating from a development host such as ``localhost`` or a
    ``*.ddev.site`` domain does not start it. The :guilabel:`Licence` tab shows
    the start time and the remaining trial time once the window is running.

If the licence service cannot be reached, limits requests or answers without a
verdict, AQG keeps the last valid result for up to 48 hours, and never past the
end of the licence. Without one it reports ``api_unreachable`` (or
``rate_limited``) and falls back to the Free feature set until the next
successful validation. A definitive answer — invalid, expired, revoked, another
domain or project — replaces the cached licence state and the cached scanner
tokens at once, including after :guilabel:`Revalidate`. Local
content scans, rendered page checks, CLI and Scheduler runs are not affected by
licence service outages.

When a saved key is not valid, the :guilabel:`Licence` tab names the problem and
offers the matching next step, see :ref:`troubleshooting-licence`.

..  _configuration-licence-endpoint:

Overriding the service endpoint
===============================

For staging or isolated test environments the endpoints can be overridden with
environment variables:

..  code-block:: bash

    A11Y_QUALITY_GATE_PRO_API_BASE_URL="https://api.example.org"
    A11Y_QUALITY_GATE_PRO_CRAWLER_BASE_URL="https://api.example.org"

Both default to ``https://api.priebera.sk``. Only change them if you were
explicitly told to do so; a wrong value disables all licensed features.

Licences, invoices and domain assignments are managed in the customer portal at
`typo3.priebera.sk/portal <https://typo3.priebera.sk/portal>`__.
