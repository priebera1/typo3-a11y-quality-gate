:navigation-title: Editing content

..  _usage-ckeditor:

==================================
Accessibility checks while editing
==================================

AQG checks rich text (CKEditor 5) and :guilabel:`Plain HTML` content elements
while an editor works on them. Problems are marked in the text, every marker
explains the problem and how to fix it, and after :guilabel:`Save` AQG checks
the saved record again. No scan has to be started for this.

..  figure:: /Images/ckeditor-inline-highlighting.png
    :alt: CKEditor with the AQG summary bar "4 issues found: 1 Critical,
          2 Warnings, 1 Needs review". An open marker explains that the link
          text "click here" is not descriptive, with How to fix guidance and
          the actions Fix with AI, Explain, Select in text and Ignore this
          issue.

    The summary bar counts the issues of the field. A marker at the right edge
    opens the explanation, How to fix and the available actions.

..  _usage-ckeditor-recognise:

Recognising accessibility warnings
==================================

Summary bar
    Above the editor toolbar, AQG shows how many issues the field has, by
    severity (:guilabel:`Critical`, :guilabel:`Warning`, :guilabel:`Needs
    review`). :guilabel:`Show issues` lists them, :guilabel:`Next issue` (or
    :kbd:`F8` / :kbd:`Shift+F8`) moves from one issue to the next.

Markers
    The affected element is outlined in the text, and a marker at the right
    edge of the editor shows its severity. Details open only from the marker,
    from :guilabel:`Show issues` or with :kbd:`F8` — never while you type,
    move the caret or select text. :kbd:`Escape` closes the details and returns
    focus to the editor.

How to fix
    Every issue names the problem in plain words and gives one concrete fix,
    for example "Replace generic link text with text that describes the
    destination or action". :guilabel:`Show details` adds why it matters and
    the referenced standards. :guilabel:`Select in text` selects the element so
    that you can correct it yourself.

Ignore this issue
    Accepts an issue for a documented reason, as in the page details (see
    :ref:`usage-page-detail-ignore`). Ignore follows the same permissions as in
    the page details, including
    :confval:`options.a11y_quality_gate.editRecord`.

The live check covers a subset of the ``rte.*`` rules — images, headings,
links, tables, buttons, iframes, SVG titles and duplicate IDs — limited to the
rules enabled in :ref:`configuration-rules`. The :ref:`rule reference
<rule-reference>` lists everything a scan applies.

..  _usage-ckeditor-common-fixes:

Typical fixes
=============

Link text
    Links such as "click here", "more" or a bare URL do not say where they
    lead. Replace the link text with the destination or action, for example
    "list of holiday closures". Mention when a link opens a document or a new
    window.

Headings
    Do not leave empty headings (an empty line formatted as a heading) and do
    not skip levels, for example from :guilabel:`Heading 2` directly to
    :guilabel:`Heading 4`. Use headings for structure, not for size.

Images in rich text
    Give each informative image an ``alt`` text that describes what it shows
    or does; do not use the file name. Images in content elements are handled
    in the image field, see :ref:`usage-images`.

Embeds and buttons
    Every iframe needs a ``title`` that names its content, and every button
    needs a visible text or an accessible name.

..  _usage-ckeditor-ai:

Optional AI help in the editor
==============================

When AI is set up for the site (PRO or Agency licence, see
:ref:`configuration-ai`), a small wand on a marker shows that AQG can prepare a
fix:

:guilabel:`Fix with AI`
    Requests a suggestion for exactly this element. AQG shows Before and After,
    an editable value, the reason and which model answered.
    :guilabel:`What was sent?` lists the content that went to the provider.

:guilabel:`Explain`
    Explains the issue in the context of this element, without changing
    anything.

:guilabel:`Apply to text`
    Puts the reviewed value into the editor as one change (one :kbd:`Ctrl+Z` /
    :kbd:`Cmd+Z` undoes it). Nothing is stored until you press TYPO3
    :guilabel:`Save`.

:guilabel:`Review fixes (n)`
    Lists every fix of the field in one dialog. Only the items you accept are
    applied.

..  figure:: /Images/ckeditor-fix-with-ai.png
    :alt: The marker details for a non-descriptive link with an AI suggestion:
          Before "click here", After "list of holiday closures", an editable
          field, the reason, and the buttons Apply to text, Discard and
          Regenerate.

    A suggestion shows Before and After and stays editable. Nothing changes
    until you apply it, and nothing is stored until you save.

Without AI, or for users whose group has
:confval:`options.a11y_quality_gate.ai.editor` set to ``0``, the editor shows
the same issues and How to fix guidance without AI actions. Which findings get
AI help is listed in :ref:`configuration-ai-editors`.

..  _usage-ckeditor-after-save:

Confirming a correction
=======================

When you save, AQG scans the content of the page again and shows a short
summary message. In the editor, the summary bar then says how many issues were
resolved, for example :guilabel:`1 resolved — The check of the saved record no
longer reports this issue`, next to the remaining counts. An applied change
that was not saved is reported as not saved; AQG reports a fix as resolved only
when the stored record no longer has the issue.

..  figure:: /Images/ckeditor-resolved-after-save.png
    :alt: CKEditor after saving: the summary bar reads "1 resolved" and the
          link now reads "list of holiday closures".

    After saving, AQG confirms which issues are resolved in the stored record.

The Page module, the toolbar and the Accessibility module show the new content
findings. Frontend scan results change only with the next frontend scan.

..  _usage-ckeditor-plain-html:

Plain HTML content elements
===========================

For :guilabel:`Plain HTML` elements, AQG marks affected lines in TYPO3's code
editor and lists the issues below the summary bar. Lines with issues carry an
AQG marker; :guilabel:`Show issue list` expands the list with the rule name and
severity. Live checks run when the source has changed, not on caret movement.

..  figure:: /Images/plain-html-feedback.png
    :alt: A Plain HTML element with "3 issues found, 3 Critical", the expanded
          issue list (button without accessible name, empty heading, iframe
          without title) and AQG markers on lines 2, 4 and 5 of the HTML code.

    Plain HTML: issues are listed above the code and marked on their lines.

With AI set up, the same Fix with AI, Explain and Review fixes actions are
available; a change may only set one attribute or replace the text of an
element, and AQG offers it only when everything else stays unchanged.

..  _usage-ckeditor-availability:

Availability and permissions
============================

*   The checks need access to the :guilabel:`Accessibility` module and edit
    access to the content element. Without module access, editors get the plain
    TYPO3 editors without AQG checks; the Quality Gate still applies. See
    :ref:`configuration-permissions`.
*   The checks start once a new element has been saved for the first time.
*   Fields that TYPO3 renders read-only (TCA or TCEFORM ``readOnly``) keep the
    issues and How to fix guidance but offer no action that changes content.
*   The live check is an aid while editing. Stored findings are written by
    scans, including the scan after saving; the Accessibility module can
    therefore differ from the editor until the next save or scan.

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
