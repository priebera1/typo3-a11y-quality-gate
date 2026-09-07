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
        -   ``mpdf/mpdf`` (PDF export)

    *   -   Optional PHP extension
        -   ``ext-sodium`` — required for encrypted remote-access passwords and
            per-site AI provider keys

    *   -   Outbound network access
        -   Only for licensed features and Free Remote Preview
            (``https://api.priebera.sk``). The content scan and the rendered
            page check work without internet access.

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
:sql:`tx_a11y_remote_*` tables and :sql:`tx_a11y_ai_configuration`) and adds the
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
    In Classic mode the ``mpdf/mpdf`` library must be provided by the
    installation. PDF export is unavailable if the library cannot be loaded.

..  _installation-setup:

First steps after installation
==============================

#.  Open the **Accessibility** backend module. On TYPO3 13 it is located below
    :guilabel:`Web`, on TYPO3 14 below :guilabel:`Content`. The backend route
    identifier stays ``web_a11y`` on both versions.

#.  Open :guilabel:`Settings` and run :guilabel:`Re-scan TCA` once. This
    discovers the RTE and file fields that AQG can analyse and writes them to
    :sql:`tx_a11y_field_config`. AQG does not run the discovery automatically,
    so until you do this the field configuration is empty and field-based rules
    produce no findings.

#.  Review the discovered fields on the :guilabel:`Scanned fields` tab. Newly
    discovered fields are enabled by default; disable the ones you do not want
    to check and press :guilabel:`Save settings`. Changes take effect only after
    saving.

#.  Optional: review the :guilabel:`Rules` tab and disable rules that do not
    apply to your project.

#.  Optional: configure the quality gate on the
    :guilabel:`Publishing rules` tab, see :ref:`configuration-quality-gate`.

#.  Run a first scan, either with :guilabel:`Scan site` in the module or with
    the :ref:`CLI command <automation-cli>`.

#.  Optional: activate a licence key, see :ref:`configuration-licence`.

..  _installation-access:

Backend user access
===================

The module is registered with ``access: user``, so it is available to
administrators and to backend users whose groups have the module enabled. Grant
access to the module ``web_a11y`` in the backend user group configuration.

All AJAX routes used by the module inherit their access from the same module
identifier, so no additional permission configuration is required.

Editors additionally need edit permissions on the records they are asked to fix.
The image remediation actions (applying alternative text, marking an image as
decorative) additionally require the User TSconfig option described in
:ref:`configuration-tsconfig`.
