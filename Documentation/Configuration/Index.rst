:navigation-title: Configuration

..  _configuration:

=============
Configuration
=============

Almost all configuration is done in the :guilabel:`Settings` view of the
**Accessibility** backend module. The tabs of that view map to the chapters
below. Two settings live outside the module: the extension configuration
(licence key) and User TSconfig.

..  note::
    The Settings view always writes to a ruleset record. If no ruleset exists, a
    default ruleset is created on first use. Site-specific rulesets are a
    licensed feature; see :ref:`configuration-quality-gate-sites`.

..  toctree::
    :titlesonly:

    ScanFields
    Rules
    SiteSettings
    QualityGate
    Licence
    RemoteAccess
    AiSuggestions
    AccessibilityStatement
    Tsconfig
