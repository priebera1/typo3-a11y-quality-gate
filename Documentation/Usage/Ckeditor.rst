:navigation-title: CKEditor

..  _usage-ckeditor:

=====================
CKEditor integration
=====================

AQG registers a CKEditor 5 plugin that highlights accessibility problems while
an editor writes RTE content. Highlighting happens in the editor, in the
browser, and needs no scan and no server round trip.

..  figure:: /Images/ckeditor-inline-highlighting.png
    :alt: A CKEditor text area in which an image without alternative text and a
          link with non-descriptive text are marked with a coloured underline.

    Inline highlighting of accessibility problems in CKEditor.

The plugin applies a supported subset of the ``rte.*`` rules described in
:ref:`rule-reference-rte` — currently 15 of them, covering images, headings,
links, tables, buttons, SVG titles and duplicate IDs. It marks the affected
element and explains the problem, so that the editor can fix it before saving.

Rules outside that subset are evaluated only by a scan, not live in the editor.
The rule reference is the authoritative list of everything a scan applies.

..  note::
    Inline highlighting is a live aid, not a scan result. Findings are only
    written to the database when a content scan, a rendered page check, a CLI
    run or a Scheduler task runs. The backend module can therefore show findings
    that the editor has already fixed but not yet rescanned, and vice versa.

..  _usage-ckeditor-registration:

Registration
============

The plugin is added automatically. AQG listens to the TYPO3 event
:php:`BeforePrepareConfigurationForEditorEvent` and injects its CKEditor module
and stylesheet for :sql:`tt_content` RTE fields. No change to your RTE YAML
preset is required.

For plain HTML fields rendered with CodeMirror, AQG registers the passive
t3editor addon ``a11y-quality-gate/html-markers``, which reuses the same
markers.

Highlighting is limited to the live subset described above, further restricted
to the rules that are enabled in :ref:`configuration-rules`.
