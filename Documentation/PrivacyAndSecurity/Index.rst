:navigation-title: Privacy and security

..  _privacy-security:

====================
Privacy and security
====================

This chapter documents which data leaves the installation, how secrets are
stored and which settings are security relevant. It is meant to support your own
data protection assessment; it is not legal advice.

..  _privacy-security-offline:

What works without external services
====================================

The content scan, the rendered page check, the CKEditor highlighting, the CLI
command, the Scheduler task, the ignore workflow, the quality gate in warn mode
and the local CSV export do not send scan data to AQG-hosted services or to any
other third party. Findings are computed and stored inside the TYPO3
installation.

One qualification: the rendered page check performs a real HTTP request, not an
in-process call. AQG sends that request only to the configured site's frontend
URL. Depending on the site's DNS and network architecture, the request may pass
through infrastructure such as a CDN, reverse proxy or load balancer before it
reaches TYPO3.

..  _privacy-security-outbound:

Outbound connections
====================

..  list-table::
    :header-rows: 1

    *   -   Feature
        -   Endpoint
        -   Transferred data

    *   -   Licence validation
        -   ``https://api.priebera.sk``
        -   Licence key and a site fingerprint derived from the domain.

    *   -   Frontend scan and Free Remote Preview
        -   ``https://api.priebera.sk``
        -   The scan target resolved on the server, plus the crawler access
            settings needed to reach it. The crawler then requests your public
            frontend and stores the findings and, for licensed plans,
            screenshots of the scanned pages (see
            :ref:`privacy-security-retention`).

    *   -   Update notice
        -   ``https://api.priebera.sk``
        -   Nothing beyond the HTTP request itself: a ``GET`` of
            ``/extension/releases/latest`` without licence key, installation
            id, site or user data. Sent only when an administrator opens an AQG
            module, at most once in 12 hours (one hour after a failure). The
            installed version is compared inside TYPO3.

    *   -   AI text suggestions
        -   OpenAI, with your own project key
        -   The content context of the individual finding. Requests are sent
            with ``store=false``. Disabled by default.

Both base URLs can be redirected with the environment variables described in
:ref:`configuration-licence-endpoint`. If you must not contact any external
service, do not enter a licence key, do not use the Free Remote Preview and do
not configure AI suggestions.

..  _privacy-security-secrets:

Stored secrets
==============

..  list-table::
    :header-rows: 1

    *   -   Secret
        -   Storage

    *   -   Licence key
        -   Extension configuration
            (``EXTENSIONS/a11y_quality_gate/licenceKey`` in
            :file:`config/system/settings.php`).

    *   -   Scanner token
        -   ``scanner_token`` in :sql:`tx_a11y_ruleset`, 64 hexadecimal
            characters.

    *   -   Remote basic authentication password
        -   ``http_auth_pass`` in :sql:`tx_a11y_ruleset`, encrypted with
            ``ext-sodium``.

    *   -   OpenAI project key
        -   ``encrypted_api_key`` in :sql:`tx_a11y_ai_configuration`, encrypted
            with ``ext-sodium``, displayed only as a masked hint. Alternatively
            the environment variable ``AQG_OPENAI_API_KEY``.

Treat database dumps that contain :sql:`tx_a11y_ruleset` or
:sql:`tx_a11y_ai_configuration` as secret material. Regenerate the scanner token
after restoring a production dump into a less protected environment.

..  _privacy-security-frontend:

Frontend exposure
=================

*   The scanner token grants access to hidden pages and hidden content elements
    through the ``X-AQG-Scanner-Token`` request header. It is the only mechanism
    by which AQG changes frontend visibility. See
    :ref:`configuration-remote-access-token`.
*   The AQG frontend markers used for record mapping are not rendered for normal
    visitors. They are emitted only for a request with a valid scanner token,
    for a rendered page check with a valid one-time nonce, or for a logged-in
    backend user calling the page with ``?aqgDebug=1``.
*   Screenshots taken during licensed frontend scans show the page as the
    crawler rendered it. If the crawler can authenticate into protected areas,
    the screenshots may contain data from those areas. Restrict what the crawler
    may reach with the excluded URL patterns.

..  _privacy-security-retention:

Where scan data is kept, and for how long
=========================================

*   **In this TYPO3 installation** (database tables ``tx_a11y_*``): content scan
    results and the stored results of frontend scans — pages, findings, HTML
    snippets of failing elements, counts and comparisons. They stay until you
    delete them. :guilabel:`Settings > Scan history` (administrators) lets you
    choose a period (1, 3 or 5 years) for the stored frontend scans; nothing is
    deleted when you save it. Older scans are deleted only after an
    administrator reviews and confirms them there, or when
    ``vendor/bin/typo3 a11y:history:prune --apply`` runs (without ``--apply``
    the command only reports). Periods are calendar years in UTC. The newest
    scan of each comparison, running scans, the scans of a Verify fix that is
    still running and the scans monitoring still compares with are always kept.
*   **On the AQG service**: its own copy of the frontend scan history (runs,
    pages, findings, counts) and the screenshots. Screenshots are never stored in
    TYPO3; the backend loads them through the AQG service. The service deletes a
    screenshot automatically after the retention period of the plan the scan
    ran under, as described in its privacy information; the period is fixed when
    the scan runs. The scan results, counts and comparisons stay available after
    a screenshot has been deleted: the page detail then says that the
    screenshot was deleted after its retention period. Free Remote Preview
    results have no screenshots and are kept on the AQG service only for a
    limited time.
*   **FREE Remote Preview acceptance**: the AQG service records which
    administrator accepted the FREE terms and DPA for which installation and
    domain (organisation, name, email address, TYPO3 user ID, document versions
    and hashes, its own UTC timestamp). TYPO3 keeps only the confirmation
    (acceptance ID, versions, timestamp, organisation and the administrator's
    name) in :sql:`sys_registry`. See
    :ref:`usage-remote-scans-free-activation`.
*   Neither period is a statutory retention period. Scan data can contain
    personal data (page addresses, texts and markup of the scanned pages), also
    after its screenshot is gone.

..  _privacy-security-permissions:

Backend permissions
===================

*   The module and all its AJAX routes share the identifier ``web_a11y``, so
    module access is the single access control point.
*   The :guilabel:`Licence`, :guilabel:`Remote scan access`, :guilabel:`AI` and
    :guilabel:`Scan history` tabs are restricted to administrators.
*   Image remediation requires the capability from
    :confval:`options.a11y_quality_gate.allowImageRemediation` **and** TYPO3
    edit permissions on the affected records. Writes are limited to the
    ``alternative`` and ``tx_a11y_is_decorative`` fields of
    :sql:`sys_file_reference`.
*   Remote scan targets are always resolved server-side from a page UID. The
    browser never supplies a scan URL, installation identifier, licence key or
    access token.

..  _privacy-security-reporting:

Reporting a vulnerability
=========================

Do not report security issues publicly. Use
`GitHub private vulnerability reporting <https://github.com/priebera1/typo3-a11y-quality-gate/security/advisories/new>`__
or write to ``support@priebera.sk``.
