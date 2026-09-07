:navigation-title: Troubleshooting

..  _troubleshooting:

===============
Troubleshooting
===============

..  _troubleshooting-no-findings:

A scan finds nothing
====================

*   Open :guilabel:`Settings` and check whether any fields are enabled on the
    :guilabel:`Scanned fields` tab. Directly after installation no fields are
    enabled. Run :guilabel:`Re-scan TCA`, enable the fields and press
    :guilabel:`Save settings`.
*   Check the :guilabel:`Rules` tab for rules that were disabled earlier.
*   Verify that the scanned page really is inside the site you selected in the
    module filter.

..  _troubleshooting-fields-missing:

A field is not offered for scanning
===================================

Run :guilabel:`Re-scan TCA`. AQG discovers RTE and file fields from TCA, so a
field that was added by an extension or by a TCA override appears only after a
new discovery run. Fields whose TCA type AQG does not support are not listed.

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

The :guilabel:`Licence` tab reports a machine-readable reason:

``invalid_key``
    The key does not exist. Check for copy and paste errors.

``expired`` / ``trial_expired``
    The licence or the trial has ended. Renew it in the customer portal.

``inactive`` / ``trial_revoked``
    The licence was deactivated. Contact support.

``domain_mismatch`` / ``trial_domain_mismatch``
    The key is bound to a different domain. Assign the current domain in the
    customer portal.

``domain_limit_reached``
    All domain slots of the plan are used. Release a domain or upgrade the plan.

``api_unreachable``
    The licence service could not be reached. Check outbound HTTPS access to
    ``https://api.priebera.sk``, and whether an
    :ref:`endpoint override <configuration-licence-endpoint>` is set by mistake.
    AQG falls back to the Free feature set until the next successful validation.

``licence_project_mismatch`` / ``trial_project_mismatch``
    The key belongs to a different product.

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
