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

    Whether the backend module shows hints about features that require a
    licence. Can be overridden per ruleset from the Settings view.

There are two ways to set the key:

*   In the :guilabel:`Licence` tab of the AQG Settings view. The tab is only
    available to administrators and writes into the extension configuration.
*   In :guilabel:`Admin Tools > Settings > Extension Configuration >
    a11y_quality_gate`, or directly in
    :file:`config/system/settings.php` under
    ``EXTENSIONS/a11y_quality_gate/licenceKey``.

After saving, press :guilabel:`Validate` in the :guilabel:`Licence` tab. AQG
contacts the licence service, shows the resolved plan, the bound domains, the
expiry date and the limits of the plan.

..  _configuration-licence-validation:

How validation works
====================

*   The extension calls ``https://api.priebera.sk`` with the licence key and a
    site fingerprint derived from the domain of the installation.
*   The result is cached. Valid results are cached for one hour, invalid results
    for five minutes, trial results for fifteen minutes.
*   Licences are bound to domains. A key that was activated for another domain
    is reported as ``domain_mismatch``; a key that has used all its domain slots
    is reported as ``domain_limit_reached``.
*   Trial keys do not start their runtime when they are issued. The trial window
    starts on the first successful validation from a production domain;
    validating from a development host such as ``localhost`` or a
    ``*.ddev.site`` domain does not start it. The :guilabel:`Licence` tab shows
    the start time and the remaining trial time once the window is running.

If the licence service cannot be reached, AQG reports ``api_unreachable`` and
falls back to the Free feature set until the next successful validation. Local
content scans, rendered page checks, CLI and Scheduler runs are not affected by
licence service outages.

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
