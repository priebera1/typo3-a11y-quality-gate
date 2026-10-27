:navigation-title: FREE acceptance API

..  _developer-free-acceptance-api:

===========================================
FREE Remote Preview terms acceptance (API)
===========================================

Since 2.1.0 the extension activates the FREE Remote Preview only with an
acceptance of the FREE terms and DPA that the AQG service has recorded
(:ref:`usage-remote-scans-free-activation`). This page is the contract the
extension implements; the AQG service must implement and enforce the same. The
extension fails closed: while the endpoints answer ``404`` or are unreachable,
activation is impossible and FREE scans stay off.

All requests go to the AQG API base URL (``A11Y_QUALITY_GATE_PRO_API_BASE_URL``
or the default), ``Accept: application/json``, JSON bodies. Errors use the
service's usual body ``{"success": false, "error": {"code": "…", "message":
"…", "status": 409, "details": {…}}}``.

..  _developer-free-acceptance-api-terms:

GET /free-preview/terms?language=en|de
======================================

The terms in force. Public, cacheable (the extension caches a complete answer
for one hour).

..  code-block:: json

    {
      "success": true,
      "terms": {
        "version": "free-2026-11",
        "effectiveAt": "2026-11-01T00:00:00Z",
        "documents": [
          {"type": "free_terms", "language": "en", "title": "AQG FREE Remote Preview Terms",
           "version": "1.0", "url": "https://typo3.priebera.sk/en/legal/archive/<sha256>", "sha256": "<64 hex>"},
          {"type": "dpa", "language": "en", "title": "AQG FREE Remote Preview Data Processing Terms",
           "version": "1.0", "url": "https://typo3.priebera.sk/en/legal/archive/<sha256>", "sha256": "<64 hex>"}
        ],
        "statements": {
          "en": {"sha256": "<sha256 of the English declaration>"},
          "de": {"sha256": "<sha256 of the German declaration>"}
        }
      }
    }

*   ``version`` and document ``version``: ``[A-Za-z0-9._:-]{1,64}``.
*   Documents: ``type`` ``free_terms`` and ``dpa`` are both required, ``language``
    ``en``/``de``, ``url`` must be ``https``, ``sha256`` is the hash of the
    published file. Anything else makes the extension treat the terms as not
    published.
*   ``statements``: the SHA-256 (UTF-8, exact text) of the declaration the
    administrator ticks. The extension ships it verbatim in
    ``Classes/FreePreview/FreePreviewStatement.php`` (``VERSION`` =
    ``free-statement-2026-10-v2``: one combined confirmation that names the
    FREE Remote Preview Terms and the FREE Remote Preview Data Processing
    Terms with their versions and the authority to act for the organisation)
    and does not offer activation when its hash is not listed.
*   ``404`` = not published yet.

..  _developer-free-acceptance-api-accept:

POST /free-preview/acceptances
==============================

Records an acceptance. Request:

..  code-block:: json

    {
      "installationId": "<anonymous installation id>",
      "siteUrl": "https://www.example.org/",
      "siteIdentifier": "main",
      "domain": "example.org",
      "termsVersion": "free-2026-11",
      "documents": [
        {"type": "free_terms", "language": "en", "version": "1.0", "sha256": "<64 hex>"},
        {"type": "dpa", "language": "en", "version": "1.0", "sha256": "<64 hex>"}
      ],
      "statement": {"version": "free-statement-2026-10-v2", "language": "en",
                    "text": "I accept the AQG FREE Remote Preview Terms, version 1.0, …", "sha256": "<64 hex>"},
      "organisation": {"name": "Example GmbH"},
      "acceptedBy": {"name": "Ada Admin", "email": "ada@example.org", "typo3UserId": 7,
                     "role": "typo3_administrator", "authorityConfirmed": true},
      "idempotencyKey": "<64 hex>",
      "clientTimestamp": "2026-10-20T10:15:29Z",
      "extension": {"version": "2.1.0", "typo3Version": "14.3.9"}
    }

Response ``201`` (``200`` for a replay of the same ``idempotencyKey``):

..  code-block:: json

    {
      "success": true,
      "acceptance": {
        "id": "acc_01J…",
        "status": "valid",
        "termsVersion": "free-2026-11",
        "installationId": "<as sent>",
        "domain": "example.org",
        "acceptedAt": "2026-10-20T10:15:30.000Z",
        "validUntil": null,
        "replayed": false
      }
    }

The extension stores the acceptance only when ``id`` matches
``[A-Za-z0-9._:-]{8,128}``, ``status`` is ``valid``, ``termsVersion``,
``installationId`` and ``domain`` equal what was sent and ``acceptedAt`` (and
``validUntil`` when set) is ISO 8601 with a time zone.

Errors the extension distinguishes:

=====================================  ======  ==============================================
Code                                   Status  Meaning
=====================================  ======  ==============================================
``validation_failed``                  400     Missing or malformed field
``invalid_installation_proof``,        403     The site does not serve this installation's
``installation_proof_*``                       proof (see below)
``free_domain_mismatch``               403     ``domain`` is not the host of ``siteUrl``
``free_terms_outdated``                409     ``termsVersion`` or a document hash is not the
                                               version in force (``details.currentVersion``)
``free_terms_statement_mismatch``      409     ``statement.sha256`` is not published for the
                                               version, or does not match ``statement.text``
``idempotency_key_reused``             409     Same key, different payload
``rate_limit_exceeded``                429     Throttled
=====================================  ======  ==============================================

..  _developer-free-acceptance-api-status:

POST /free-preview/acceptances/status
=====================================

Request ``{"installationId", "siteUrl", "siteIdentifier", "acceptanceId"}``.
Response ``{"success": true, "acceptance": {"id", "status": "valid|outdated|revoked|expired",
"validUntil"}, "currentTermsVersion": "free-2026-11"}``. The extension changes
its local state only for an answer with the same ``id``. ``404``
``free_terms_acceptance_not_found`` and ``403`` ``free_terms_acceptance_mismatch``
mark the local acceptance as no longer accepted.

..  _developer-free-acceptance-api-enforcement:

Enforcement on FREE token and submit
====================================

The extension sends ``freeTermsAcceptanceId`` with the FREE ``POST /auth/token``
(``installationId`` flavour) and with the FREE ``POST /crawl/submit``. The
service must refuse FREE access with ``403`` and one of these codes, which the
extension records and explains:

*   ``free_terms_acceptance_required`` — no acceptance id, or unknown;
*   ``free_terms_outdated`` — accepted terms are not the version in force;
*   ``free_terms_acceptance_revoked``, ``free_terms_acceptance_expired``;
*   ``free_terms_acceptance_mismatch`` — the acceptance belongs to another
    installation or domain.

..  _developer-free-acceptance-api-backend:

Requirements for the AQG service
================================

*   **Trusted time**: ``acceptedAt`` is generated by the service in UTC when the
    record is stored. ``clientTimestamp`` is stored for information only and
    never used as the acceptance time.
*   **Immutable records**: an acceptance is never updated in place. Revocation,
    expiry and supersession are separate events or status columns with their own
    timestamps; switching FREE off in TYPO3 does not touch the record.
*   **Identity**: verify that the site serves this installation, as for the FREE
    token (``GET <site base path>/_aqg/free-preview-proof`` →
    ``{"version": 1, "proof": base64url(sha256("aqg-free-preview:v1\n" +
    installationId + "\n" + normalizedHost))}``). Bind the record to
    ``installationId`` + normalised ``domain``; another installation or domain
    never inherits it.
*   **Versions**: accept only the ``termsVersion`` in force, the published
    document hashes for it and a published ``statement.sha256`` that equals the
    SHA-256 of ``statement.text``.
*   **Idempotency**: ``(installationId, domain, idempotencyKey)``; a replay with
    the same payload returns the existing record (``replayed: true``) while that
    record is still ``valid``, a different payload ``409
    idempotency_key_reused``. When the matching record has been revoked or has
    expired, a new submission records a new acceptance (with a new id and
    ``acceptedAt``) instead of replaying the old one. The extension derives the
    key from installation, domain, terms version, statement hash, organisation,
    administrator name and email, so retries and double clicks never create a
    second record.
*   **Evidence stored**: acceptance id, SHA-256 fingerprint of the installation id, domain, site URL, site
    identifier, terms version, document types/languages/versions/hashes,
    statement version/language/hash/text, organisation name, administrator name,
    email and TYPO3 user id, authority declaration, server ``acceptedAt``,
    request id, extension and TYPO3 version, ``clientTimestamp``. The client IP is kept apart, in the service's
    45-day request log for abuse handling. No passwords, tokens or page content.
*   **Enforcement**: check the acceptance on every FREE token and submit, as
    above; the TYPO3 UI is not a security boundary.
*   **Retention of FREE data** (owner direction, to be confirmed legally and
    implemented on the service, not in TYPO3): transient HTML/DOM discarded after
    processing (at most 24 hours where technically needed), FREE scan results at
    most 30 days, FREE technical and security logs at most 45 days, no FREE
    screenshots. Acceptance records follow the contractual evidence retention.
