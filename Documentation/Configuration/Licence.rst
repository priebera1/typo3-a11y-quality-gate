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

    Whether the Free Remote Preview shows the upgrade offer with trial and
    pricing links. Plan badges and notes on locked features stay visible. Can be
    overridden per ruleset from the Settings view.

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
of the plan. To check a saved key again, for example after registering a domain
in the customer portal, press :guilabel:`Revalidate` next to the key.

..  _configuration-licence-validation:

How validation works
====================

*   The extension calls ``https://api.priebera.sk`` with the licence key and a
    site fingerprint derived from the domain of the installation.
*   The result is cached. Valid results are cached for one hour, invalid results
    for five minutes, trial results for fifteen minutes — never beyond the end
    of the licence or trial that the service reported.
*   Licences are bound to domains. A key that was activated for another domain
    is reported as ``domain_mismatch``; a key that has used all its domain slots
    is reported as ``domain_limit_reached``.
*   PRO and trial licences cover one TYPO3 installation (project). Another
    installation using the same key is reported as ``project_mismatch``.
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
