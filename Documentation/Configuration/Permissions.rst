:navigation-title: Permissions

..  _configuration-permissions:

===================================
Permissions for editors and groups
===================================

AQG uses TYPO3's own backend permissions. You configure editors with ordinary
backend user groups. AQG has no role system and no user administration of its
own.

What decides access
===================

Every AQG request is checked on the server. A hidden button is never the only
protection, and a licence or an AI configuration never adds TYPO3 rights.

..  list-table::
    :header-rows: 1
    :widths: 30 70

    *   -   Action
        -   Required TYPO3 permission (checked on the server)
    *   -   Open AQG at all
        -   Module :guilabel:`Accessibility` (``web_a11y``) in the group's
            :guilabel:`Modules`. All AQG AJAX routes inherit this module access.
    *   -   Accessibility checks while editing (CKEditor, plain HTML)
        -   The same module access, plus edit access to the content element.
            Without the module, TYPO3 shows its plain editors: no AQG checks
            while typing, no issue summary after saving a content element and
            no Page module panel (the Quality Gate still applies).
            The checks start once a new content element has been saved. The
            alternative text status and advice on images in content elements
            need no module access.
    *   -   Overview of a site
        -   :guilabel:`Show page` on the site root, inside the user's DB mounts.
            The page list, its counts and totals only include pages the user
            may show.
    *   -   Page details, Page module panel
        -   :guilabel:`Show page` on that page, inside the DB mounts.
    *   -   Scan a page, Verify fix, export
        -   :guilabel:`Edit page` on that page (or on the site root for a site
            export) and ``pages`` in :guilabel:`Tables (modify)`. A site export
            of content findings leaves out pages the user may not show.
    *   -   Scan a site or subtree
        -   :guilabel:`Edit page` on the start page. The scan processes only
            the pages below it that the user may edit.
    *   -   Ignore a finding (Page details, CKEditor, plain HTML)
        -   Edit access to the content record: the table in
            :guilabel:`Tables (modify)`, :guilabel:`Edit content` on its page.
    *   -   Ignore a rule on the whole site
        -   :guilabel:`Edit page` on the site root. Only findings on pages
            whose content the user may edit are ignored.
    *   -   AI suggestions in an editor, AI fixes
        -   PRO or Agency licence, AI set up for the site, and edit access to
            the record **and field**: the field in
            :guilabel:`Allowed excludefields` if it is an exclude field, and
            the content type in :guilabel:`Explicitly allow field values`
            (``tt_content:CType:…``, required by TYPO3 since version 12). A
            user who may only read a page can ask for explanations, but cannot
            create an AI fixes item.
    *   -   Apply an image text, mark an image decorative
        -   Edit access to the content element and its image field **and** to
            ``sys_file_reference`` with the exclude fields
            ``sys_file_reference:alternative`` and
            ``sys_file_reference:tx_a11y_is_decorative``. The change is written
            with DataHandler as the user.
    *   -   Image metadata in the Filelist
        -   The file's storage in the user's :guilabel:`File Mounts`,
            ``sys_file_metadata`` in :guilabel:`Tables (modify)` and the
            metadata fields in :guilabel:`Allowed excludefields`. TYPO3's own
            form saves the value.
    *   -   Settings: Rules, Scan fields, Quality gate, Licence, Remote scan
            access, AI suggestions, Scan history
        -   **Administrators only.** These settings apply to every site of the
            installation. Other users see the Rules, Scan fields and Quality
            gate tabs read-only.
    *   -   Accessibility statement
        -   :guilabel:`Show page` on the site root (and a licence that includes
            the statement).

User TSconfig: switching AQG actions off
========================================

You can narrow what a group may do with User TSconfig, in the
:guilabel:`TSconfig` field of the backend user group. These options never grant
anything TYPO3 does not already allow. Administrators are not affected by them.

..  list-table::
    :header-rows: 1
    :widths: 38 10 52

    *   -   ``options.a11y_quality_gate.``
        -   Default
        -   Effect (all enforced on the server)
    *   -   ``showScanNow``
        -   ``1``
        -   ``0``: no page scans, Free Remote Preview or Verify fix.
    *   -   ``showScanAll``
        -   ``1``
        -   ``0``: no site or subtree scans.
    *   -   ``editRecord``
        -   ``1``
        -   ``0``: no Ignore / Unignore.
    *   -   ``allowImageRemediation``
        -   not set
        -   ``0``: no image text or decorative changes by AQG. Not set or
            ``1``: TYPO3's rights decide (see above).
    *   -   ``ai.editor``
        -   ``1``
        -   ``0``: no AI in CKEditor, plain HTML and AI fixes.
    *   -   ``ai.images``
        -   ``1``
        -   ``0``: no AI for images and image metadata.
    *   -   ``ai.writing``
        -   ``0``
        -   ``1``: writing help (clearer text, plain language, translation
            drafts).
    *   -   ``ai.bulk``
        -   ``0``
        -   ``1``: prepare AI fixes for several findings and review other
            people's AI fixes.
    *   -   ``showSettings``
        -   ``1``
        -   ``0``: hides the Settings view (and the statement generator).
            Changing global settings needs an administrator anyway.
    *   -   ``showToolbarItem``
        -   ``1``
        -   ``0``: hides the AQG item in the toolbar.

Example: regular accessibility editor
=====================================

Can open the findings of their site and fix what their TYPO3 rights allow.
Cannot change AQG's global configuration, other sites or other files.

In a backend user group:

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Field
        -   Value
    *   -   :guilabel:`Modules`
        -   :guilabel:`Accessibility`, plus the page, list and file list
            modules the editors already use
    *   -   :guilabel:`Tables (listing)` and :guilabel:`Tables (modify)`
        -   Page, Page content, File reference, File metadata
            (``pages``, ``tt_content``, ``sys_file_reference``,
            ``sys_file_metadata``)
    *   -   :guilabel:`Allowed excludefields`
        -   The content fields the editors work with, plus
            ``sys_file_reference: alternative``,
            ``sys_file_reference: tx_a11y_is_decorative`` and
            ``sys_file_metadata: alternative, title, description``
    *   -   :guilabel:`Explicitly allow field values`
        -   The content types the editors may edit (for example
            ``tt_content:CType:text``, ``textmedia``, ``image``)
    *   -   :guilabel:`DB Mounts`
        -   The site root page
    *   -   :guilabel:`File Mounts`
        -   The site's folder
    *   -   Page permissions (module :guilabel:`Permissions`)
        -   The group owns the site's pages with :guilabel:`Show page`,
            :guilabel:`Edit content` and, for scans, :guilabel:`Edit page`

No TSconfig is needed. Optional:

..  code-block:: typoscript
    :caption: User TSconfig of the group (optional)

    # Hide the Settings view; global settings are administrator-only anyway.
    options.a11y_quality_gate.showSettings = 0

Example: restricted accessibility editor
========================================

Works only in one section of a site. Gets no information about other pages and
runs no site-wide scans.

Same group as above, with these differences:

..  list-table::
    :header-rows: 1
    :widths: 35 65

    *   -   Field
        -   Value
    *   -   :guilabel:`DB Mounts`
        -   The section's start page only (not the site root)
    *   -   Page permissions
        -   :guilabel:`Show page`, :guilabel:`Edit content` and
            :guilabel:`Edit page` on the section's pages only, including its
            start page (TYPO3 ignores a DB mount the user may not show)

..  code-block:: typoscript
    :caption: User TSconfig of the group

    options.a11y_quality_gate {
        showScanAll = 0
        showSettings = 0
    }

Without show access to the site root the editor does not get the site
Overview. They use the Page module panel, the page details, the editor feedback
in CKEditor and plain HTML, and AI fixes for the pages of their section.

..  note::
    Workspaces: AQG reads and checks live records. In a workspace, editors
    apply fixes in the record form, and TYPO3's workspace rules decide what
    they may publish.
