:navigation-title: Scanned fields

..  _configuration-scan-fields:

==============
Scanned fields
==============

AQG does not hardcode a list of fields. It discovers them from TCA and stores
the result in :sql:`tx_a11y_field_config`.

..  _configuration-scan-fields-discovery:

Field discovery
===============

AQG does not discover fields on its own. Open :guilabel:`Settings` and press
:guilabel:`Re-scan TCA`. AQG then inspects TCA and registers:

*   RTE-enabled text fields of :sql:`tt_content` (rendered by CKEditor),
*   file reference fields of :sql:`tt_content` (FAL relations, used by the
    ``structured.file_reference_*`` rules),
*   further structured fields evaluated by the ``structured.*`` rules, such as
    content element headers and form configuration.

Run the discovery again after installing extensions that add RTE or file fields,
after changing TCA, and after upgrading AQG to a version that adds new rules.

..  _configuration-scan-fields-enable:

Enabling and disabling fields
=============================

The :guilabel:`Scanned fields` tab lists all discovered fields grouped by table.
For each field you see the table, field name, label and detected type, and a
toggle that includes or excludes the field from future scans. Fields are enabled
when they are first discovered, so a discovery run turns on newly found fields
unless you disable them.

Disabling a field stops AQG from producing new findings for it. Existing
findings are not deleted automatically; they disappear after the next scan of
the affected pages.

..  important::
    Changes are only applied after pressing :guilabel:`Save settings`.
