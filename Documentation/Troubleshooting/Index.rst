:navigation-title: Troubleshooting

..  _troubleshooting:

===============
Troubleshooting
===============

..  _troubleshooting-no-findings:

A scan finds nothing
====================

*   Open :guilabel:`Settings` and check which fields are enabled on the
    :guilabel:`Scan fields` tab. AQG discovers the fields automatically the
    first time the module opens or a scan runs; if the tab is still empty,
    press :guilabel:`Refresh fields`. After changing the selection press
    :guilabel:`Save changes`.
*   Check the :guilabel:`Rules` tab for rules that were disabled earlier.
*   Verify that the scanned page really is inside the site you selected in the
    module filter.

..  _troubleshooting-fields-missing:

A field is not offered for scanning
===================================

Press :guilabel:`Refresh fields` on the :guilabel:`Scan fields` tab. AQG
discovers RTE and file fields from TCA, so a field that was added by an
extension or by a TCA override appears only after a new discovery run. Fields whose TCA type AQG does not support are not listed.

..  _troubleshooting-ckeditor:

CKEditor does not highlight anything
====================================

*   Highlighting is registered for :sql:`tt_content` RTE fields of saved
    records. It is not active for a record that has never been saved.
*   Clear the TYPO3 caches and reload the backend after an update, so that the
    JavaScript modules are re-read.
*   Check the browser console for module loading errors.

..  _troubleshooting-rendered:

The rendered page check fails
=============================

*   The check requests the page from your own frontend. Make sure the TYPO3
    frontend is reachable from the web server itself, including any HTTP basic
    authentication or IP restriction in front of it.
*   Pages that return an error page instead of the expected content are detected
    and reported as failed instead of being analysed.
*   Hidden pages are only rendered when a scanner token is configured, see
    :ref:`configuration-remote-access-token`.
*   Page types that do not deliver HTML, for example feeds or downloads, are
    skipped.

..  _troubleshooting-licence:

The licence is not accepted
===========================

The :guilabel:`Licence` tab explains the problem, offers the matching next step
(for example renew, manage domains or validate again) and shows the
machine-readable reason code. Administrators also see the notice in the
overview.

``invalid_key``
    The key does not exist. Check for copy and paste errors, or copy the key
    again from the customer portal.

``expired``
    The licence has ended. Renew it in the customer portal.

``trial_expired``
    The trial has ended. Choose a plan on the pricing page to keep the licensed
    features.

``inactive``
    The licence is not active. Open the customer portal to reactivate it.

``trial_revoked``
    The trial was revoked. Contact support.

``trial_not_verified``
    The trial could not be verified yet. Validate again from the production
    domain.

``domain_mismatch`` / ``trial_domain_mismatch``
    The key is bound to a different domain. Assign the current domain in the
    customer portal; for a trial key, choose a plan for this domain or contact
    support.

``domain_limit_reached``
    All domain slots of the plan are used. Release a domain in the customer
    portal, or switch to Agency for more domains.

``licence_project_mismatch`` / ``trial_project_mismatch``
    The key is registered to a different TYPO3 project. Use the key of this
    project; if the installation was moved or rebuilt, contact support.

``api_unreachable`` / ``rate_limited``
    The licence service could not be reached, or asked AQG to slow down.
    Validate again later, and check outbound HTTPS access to
    ``https://api.priebera.sk`` and whether an
    :ref:`endpoint override <configuration-licence-endpoint>` is set by mistake.
    AQG keeps the last validation result for up to 48 hours; without one it
    falls back to the Free feature set until the next successful validation.

Validation results are cached: valid results for one hour, invalid results for
five minutes, trial results for fifteen minutes. After fixing a problem it can
take a moment until the new state is visible; pressing :guilabel:`Validate`
re-checks immediately.

..  _troubleshooting-remote:

Remote scans do not start or find nothing
=========================================

*   Only one remote scan per site can run at a time. A parallel submit is
    rejected with a conflict; wait for the running scan.
*   Hidden pages need a scanner token, see
    :ref:`configuration-remote-access-token`.
*   A protected environment needs the basic authentication credentials, see
    :ref:`configuration-remote-access-basicauth`.
*   Check the excluded URL patterns. Too broad a pattern removes most of the
    site from the scan.
*   Verify that the site is reachable from the internet. The crawler is a hosted
    service and cannot reach installations that are only available internally.
*   Findings that are not mapped to TYPO3 records usually mean the AQG frontend
    markers are missing, see :ref:`configuration-site-settings`.

..  _troubleshooting-ai:

AI suggestions are not offered
==============================

*   AI requires an active licence and a configured provider key.
*   Link text and iframe title suggestions have their own toggle, which is off
    by default.
*   The connection must be verified with :guilabel:`Test connection` for the
    current key, model and prompt version.
*   Suggestions are only offered for the supported rules listed in
    :ref:`configuration-ai-scope`.
*   ``unsupported_context`` means AQG could not identify exactly one supported
    element from the stored finding, so it refuses instead of guessing.

..  _troubleshooting-gate:

The quality gate does not react
===============================

*   Check ``publish_mode``. ``0`` disables the gate.
*   Blocking mode requires an active licence; without one the gate can only
    warn.
*   The gate uses stored findings. Scan the page before testing.
*   Ignored findings do not count towards the thresholds.
*   With ``threshold_warning = -1`` warnings never trigger the gate.

..  _troubleshooting-pdf:

PDF export is unavailable
=========================

PDF export requires an active licence and the ``mpdf/mpdf`` library. In Classic
installations the library must be provided by the installation.

..  _troubleshooting-sodium:

Encrypted values cannot be saved
================================

The remote basic authentication password and the AI provider key are encrypted
with the PHP ``sodium`` extension. Install and enable ``ext-sodium`` on the web
server and on the CLI.
