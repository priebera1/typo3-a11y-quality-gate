:navigation-title: Remote scan access

..  _configuration-remote-access:

===================
Remote scan access
===================

The :guilabel:`Remote scan access` tab configures how the hosted crawler reaches
your site. The tab is only available to TYPO3 administrators and requires an
active licence. All values are stored in the ruleset record
:sql:`tx_a11y_ruleset` of the selected site.

..  _configuration-remote-access-token:

Scanner token
=============

The scanner token lets the crawler and the rendered page check see content that
is not publicly visible. A request that carries the header
``X-AQG-Scanner-Token`` with a valid token is served with hidden pages and
hidden content elements included.

Use :guilabel:`Generate token` to create a token, or
:guilabel:`Regenerate token` to replace an existing one. The token is a random
64-character hexadecimal string stored in ``scanner_token``.

If no token is configured, the module shows the notice
*Hidden pages will not be scanned* and remote scans only reach publicly visible
pages.

..  warning::
    Anyone who knows the token can request hidden pages and hidden content of
    your frontend. Treat it like a password: do not commit it, do not paste it
    into tickets, and regenerate it if it may have leaked. Regenerating
    invalidates the previous token immediately.

..  _configuration-remote-access-basicauth:

HTTP basic authentication
=========================

For password-protected staging environments, enter the credentials the crawler
should use. The username is stored in ``http_auth_user``, the password
encrypted in ``http_auth_pass``. Encryption requires the PHP ``sodium``
extension.

Use a dedicated account with read-only frontend access. Do not reuse backend or
production credentials.

..  _configuration-remote-access-cookies:

Cookie accept selectors
=======================

One CSS selector per line. The crawler uses them to dismiss a cookie banner
before evaluating the page, for example:

..  code-block:: none

    #cookie-accept-btn
    .cookie-consent__accept
    button[data-cookie-accept]

Leave the field empty unless remote scans are blocked by a consent layer. If a
scan returns results that look like the consent overlay rather than the page,
add the selector of the accept button here.

..  _configuration-remote-access-excluded:

Excluded URL patterns
=====================

One path pattern per line. Matching URLs are never requested by the crawler.
Use this for administration areas, file storages and internal tools:

..  code-block:: none

    /typo3/*
    /fileadmin/*
    /typo3temp/*
    /_assets/*

..  _configuration-remote-access-priority:

Priority URLs
=============

One path per line. These pages are crawled first, so that findings for the most
important pages appear early in the report even when the page budget of the
plan is reached:

..  code-block:: none

    /
    /contact
    /products

..  note::
    The crawler is a hosted service. It requests your site from the internet, so
    the site must be publicly reachable, or reachable with the configured basic
    authentication credentials. Installations that are only available inside a
    private network cannot be scanned remotely; use the content scan and the
    rendered page check instead.
