:navigation-title: Installation

..  _installation:

============
Installation
============

..  _installation-requirements:

Requirements
============

..  list-table::
    :header-rows: 1

    *   -   Requirement
        -   Supported

    *   -   TYPO3
        -   13.4 LTS and 14.3 or later. TYPO3 14.0 to 14.2 are explicitly
            declared as conflicting and are not supported.

    *   -   PHP
        -   8.2 or later (``"php": "^8.2"``)

    *   -   Required TYPO3 system extensions
        -   ``typo3/cms-core``, ``typo3/cms-backend``, ``typo3/cms-frontend``,
            ``typo3/cms-rte-ckeditor``, ``typo3/cms-scheduler``

    *   -   Third-party libraries
        -   ``mpdf/mpdf`` (PDF export; installed by Composer, not part of the
            TER package)

    *   -   Optional PHP extension
        -   ``ext-sodium`` — required for encrypted remote-access passwords and
            per-site AI provider keys

    *   -   Outbound network access
        -   Only for licensed features and Free Remote Preview
            (``https://api.priebera.sk``), and — when an administrator sets up
            AI — for the chosen provider (``https://api.openai.com`` or
            ``https://api.anthropic.com``). The content scan, the rendered page
            check and the editor checks work without internet access.

..  _installation-composer:

Installation with Composer
==========================

Install the extension in a Composer-based TYPO3 installation:

..  code-block:: bash

    composer require priebera/typo3-a11y-quality-gate

Then run the extension setup and flush the caches:

..  code-block:: bash

    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

``extension:setup`` creates the AQG database tables
(:sql:`tx_a11y_issue`, :sql:`tx_a11y_scan`, :sql:`tx_a11y_ruleset`,
:sql:`tx_a11y_field_config`, :sql:`tx_a11y_source_state`, the
:sql:`tx_a11y_remote_*` tables, the AI tables :sql:`tx_a11y_ai_*` and the
AI fixes tables :sql:`tx_a11y_remediation_*`) and adds the
``tx_a11y_is_decorative`` column to :sql:`sys_file_reference`.

See also
`Installing extensions <https://docs.typo3.org/permalink/t3start:installing-extensions>`__.

..  _installation-classic:

Installation in Classic mode
============================

In a Classic (non-Composer) installation, download the extension from the
`TYPO3 Extension Repository <https://extensions.typo3.org/extension/a11y_quality_gate>`__
and install it in the Extension Manager. Run the database analyser afterwards so
that the AQG tables are created.

..  note::
    PDF export is available only in Composer installations. The TER package
    does not include the ``mpdf/mpdf`` library, and AQG does not support
    providing it in Classic mode. Every other feature, including CSV export,
    works in Classic mode. Where PDF would be offered, the backend shows
    CSV only and says that PDF export needs a Composer installation. To use
    PDF export, move the installation to Composer and install
    ``priebera/typo3-a11y-quality-gate`` with Composer.

..  note::
    Use the package from the TYPO3 Extension Repository. Its
    :file:`composer.json` is the Classic distribution of the release (without
    the Composer-only ``mpdf/mpdf`` requirement). A Git checkout or the Composer
    package copied into :file:`typo3conf/ext` requires ``mpdf/mpdf`` and cannot
    be activated in Classic mode on TYPO3 14.

..  _installation-setup:

First steps after installation
==============================

#.  Open the **Accessibility** backend module. On TYPO3 13 it is located below
    :guilabel:`Web`, on TYPO3 14 below :guilabel:`Content`. The backend route
    identifier stays ``web_a11y`` on both versions.

#.  AQG discovers the RTE and file fields it can analyse the first time the
    module opens or a scan runs, and writes them to
    :sql:`tx_a11y_field_config`. The overview confirms how many fields it
    registered. See :ref:`configuration-scan-fields`.

#.  Review the discovered fields in :guilabel:`Settings` on the
    :guilabel:`Scan fields` tab. Newly discovered fields are enabled by
    default; disable the ones you do not want to check and press
    :guilabel:`Save changes`. Changes take effect only after saving.

#.  Optional: review the :guilabel:`Rules` tab and disable rules that do not
    apply to your project.

#.  Optional: configure the quality gate on the
    :guilabel:`Quality Gate` tab, see :ref:`configuration-quality-gate`.

#.  Add the site set ``priebera/a11y-quality-gate`` to every site that frontend
    scans should map to TYPO3 records (:file:`config/sites/<site>/config.yaml`,
    ``dependencies``; see :ref:`configuration-site-settings`). Without it the
    scanner links findings only through fluid_styled_content's ``#c{uid}``
    frames; markup outside those frames stays unlinked.

#.  For frontend site scans, give the site an XML sitemap (for example the
    TYPO3 site set ``typo3/seo-sitemap``). Without one the scanner only follows
    links from the start page; the Overview then reports the scan as
    *Partial* and lists the pages it did not reach.

#.  Run a first content scan, either with :guilabel:`Scan site content` in the
    module or with the :ref:`CLI command <automation-cli>`.

#.  Optional: activate a licence key, see :ref:`configuration-licence`.

#.  Optional, PRO or Agency: set up AI per site on the :guilabel:`AI
    suggestions` tab — provider, key, verified model, data processing
    acknowledgement and budget. See :ref:`configuration-ai`.

#.  Give editor groups access to the module and the TYPO3 rights they need,
    see :ref:`configuration-permissions`. Editors then see the checks in
    CKEditor, plain HTML and image fields, see :ref:`usage-ckeditor`.

..  _installation-access:

Backend user access
===================

The module is registered with ``access: user``, so it is available to
administrators and to backend users whose groups have the module enabled. Grant
access to the module ``web_a11y`` in the backend user group configuration.

All AJAX routes used by the module inherit their access from the same module
identifier, so no additional permission configuration is required.

Editors additionally need edit permissions on the records and fields they are
asked to fix. :ref:`configuration-permissions` lists what each AQG action needs
and contains two example groups. AQG's global settings (rules, scan fields,
Quality Gate, licence, remote scan access, AI, scan history) can only be
changed by administrators.
