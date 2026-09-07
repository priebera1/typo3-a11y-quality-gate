:navigation-title: Known limitations

..  _limitations:

=================
Known limitations
=================

..  _limitations-automated-vs-manual:

Automated testing does not replace a manual audit
=================================================

..  important::
    AQG does not guarantee, certify or prove conformance with WCAG, the European
    Accessibility Act (EAA), the German BFSG, BITV or any other accessibility
    legislation. It reports findings from automated checks.

Automated tools can only detect problems that are machine-detectable. Depending
on the site, automated checks typically surface a minority of the barriers that
a full audit finds. The following always require human judgement and cannot be
decided by AQG:

*   whether alternative text actually describes the image in its context,
*   whether link text is meaningful for the target,
*   reading order, focus order and keyboard operability,
*   visible focus indication and interaction states,
*   correct use of headings as a document outline rather than as styling,
*   whether colour is the only carrier of meaning,
*   whether captions, transcripts and audio descriptions exist and are correct,
*   error prevention, error identification and form usability in practice,
*   behaviour with screen readers, magnification and speech input,
*   plain language and comprehensibility.

Use AQG to remove the mechanically detectable problems early and continuously,
and plan a manual accessibility audit, including assistive technology testing,
for the conformance statement itself.

Some rules encode best practices and are not a hard WCAG failure in every
context. Rules with the severity *Needs review* are explicitly undecidable by
the automated check. Review every finding in its context.

..  _limitations-content-scan:

Content scan
============

*   Only fields that are discovered from TCA and enabled in the settings are
    analysed, see :ref:`configuration-scan-fields`. Run :guilabel:`Re-scan TCA`
    after TCA changes.
*   The content scan works on stored records. Problems that only appear when
    TYPO3 assembles the page — duplicate ``id`` values across content elements,
    a missing ``main`` landmark, a missing ``lang`` attribute — are found by the
    rendered page check, not by the content scan.
*   Content produced by extensions that do not store its text in scanned TCA
    fields is not covered.
*   The phrase lists of the text-based rules are English by default. Configure
    the dictionary settings for other languages, see
    :ref:`configuration-site-settings`.

..  _limitations-rendered:

Rendered page check
===================

The rendered page check analyses server-rendered HTML only. It does not execute
JavaScript, does not wait for AJAX or lazy-loaded content, does not interact
with cookie banners, does not take screenshots and does not run axe-core. It
evaluates one page at a time and never follows links: a site scan repeats the
check per page instead of crawling.

It is skipped for changed-only runs, for subtree CLI and Scheduler runs, for
page doktypes that do not deliver a frontend page, and when it is disabled in
the ruleset settings.

..  _limitations-remote:

Frontend scan
=============

*   The crawler requests the site from the public internet. Installations behind
    a VPN, an IP allowlist or a bot filter cannot be scanned.
*   Free Remote Preview is limited to a small daily allowance of single-page
    scans and does not include screenshots, TYPO3 record mapping, scan history
    or PDF export. The extension does not define the allowance itself: the
    scans and pages used, the remaining quota and the next reset time are
    reported by the AQG service and displayed in the module.
*   Licensed scans are limited by the page budget of the plan.
*   Only one remote scan per site can run at a time.
*   Mapping crawler findings back to TYPO3 records requires the AQG frontend
    markers, see :ref:`configuration-site-settings`. Without them, findings are
    reported per URL.

..  _limitations-gate:

Quality gate
============

*   The gate evaluates stored findings. A page that has not been scanned since
    its last change is evaluated against outdated data. Schedule regular scans,
    see :ref:`automation`.
*   Blocking mode requires an active licence. Without one, the gate can only
    warn.
*   The gate is an editorial safeguard. It is not a security control and not a
    statement about the accessibility of the published page.

..  _limitations-ai:

AI suggestions
==============

Suggestions are proposals that an editor must review. AQG rejects unsafe
outputs, but it cannot verify that a suggested text is factually correct for the
image or link it describes. Suggestions are never applied automatically and
never write to RTE bodytext.
