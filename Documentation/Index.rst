..  _start:

==========================
Accessibility Quality Gate
==========================

:Extension key:
    a11y_quality_gate

:Package name:
    priebera/typo3-a11y-quality-gate

:Language:
    en

:Author:
    Patrik Priebera and contributors

:License:
    GPL-2.0-or-later, like the extension itself.

----

Accessibility Quality Gate (AQG) is a TYPO3 extension that brings accessibility
checks into the editorial workflow. It analyses RTE content, structured TCA
field values and server-rendered HTML, reports findings in a dedicated backend
module, highlights problems directly in CKEditor and can warn or block editors
when a page is published above a configured issue threshold.

AQG helps teams find common accessibility problems early. It does not certify
accessibility and does not replace a manual accessibility audit. See
:ref:`limitations` for the exact scope of the automated checks.

----

..  toctree::
    :titlesonly:
    :hidden:

    Introduction/Index
    Installation/Index
    Configuration/Index
    Usage/Index
    Automation/Index
    RuleReference/Index
    PrivacyAndSecurity/Index
    Limitations/Index
    Upgrade/Index
    Troubleshooting/Index
    GetHelp

..  card-grid::
    :columns: 1
    :columns-md: 2
    :gap: 4
    :class: pb-4
    :card-height: 100

    ..  card:: :ref:`Introduction <introduction>`

        What AQG checks, which parts are free, which parts require a licence
        and where automated testing stops.

    ..  card:: :ref:`Installation <installation>`

        System requirements, Composer installation and the first-run steps in
        the TYPO3 backend.

    ..  card:: :ref:`Configuration <configuration>`

        Scanned fields, rules, quality gate thresholds, licence activation,
        remote scan access, AI suggestions and TSconfig.

    ..  card:: :ref:`Usage <usage>`

        CKEditor highlighting, the Accessibility backend module, rendered page
        checks, remote scans and report exports.

    ..  card:: :ref:`Automation <automation>`

        Running scans from the command line and with the TYPO3 Scheduler.

    ..  card:: :ref:`Rule reference <rule-reference>`

        All built-in RTE, structured and rendered HTML rules with their
        severities and WCAG references.

    ..  card:: :ref:`Privacy and security <privacy-security>`

        Which data leaves the installation, how secrets are stored and which
        settings are security relevant.

    ..  card:: :ref:`Known limitations <limitations>`

        Automated versus manual accessibility testing and the documented
        boundaries of every scan type.

    ..  card:: :ref:`Upgrade <upgrade>`

        Version scheme, database updates and things to check after an update.

    ..  card:: :ref:`Troubleshooting <troubleshooting>`

        Common problems with scans, licences, remote access and the quality
        gate.

    ..  card:: :ref:`How to get help <help>`

        Support channels, issue templates and security contact.
