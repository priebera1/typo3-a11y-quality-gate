:navigation-title: AI fixes

..  _usage-ai-fixes:

========
AI fixes
========

:guilabel:`Accessibility > AI fixes` collects changes that AI prepared for the
findings of a site, so that they can be reviewed, approved and checked in one
place. It is available with a PRO or Agency licence when AI is set up for the
site (see :ref:`configuration-ai`). Nothing in this view changes content by
itself: every change is reviewed by a person, and AQG checks the saved record
afterwards.

..  figure:: /Images/ai-fixes-queue.png
    :alt: The AI fixes view of the site "main" with OpenAI GPT-5.4 mini, the
          tabs All open, My queue, Done and Change log, filters, and a table of
          four suggested changes with page, finding, field, suggested change,
          owner, status "Pending review" and age.

    AI fixes lists the prepared changes of a site with their status and owner.

..  _usage-ai-fixes-create:

How items are created
=====================

An item is created when someone chooses :guilabel:`Fix with AI` on a finding in
the page details (see :ref:`usage-page-detail-ai`). The page details show the
item for review and can apply it in place; it is the same item, with the same
checks and history, as here, so moving between the two never asks the AI
again. The Overview and the page
details show how many items wait for review. :guilabel:`Explain with AI` and
suggestions inside CKEditor or the plain HTML editor do not create items; they
are reviewed directly in the editor (see :ref:`usage-ckeditor-ai`).

..  _usage-ai-fixes-review:

Reviewing a change
==================

The tabs :guilabel:`All open`, :guilabel:`My queue` and :guilabel:`Done`
organise the items; search and the filters (status, owner, site, source,
severity) narrow the list. Opening an item, or a link to it such as
:guilabel:`Review suggestion` in the page details, shows the review drawer:

*   the page, record, field and owner of the finding,
*   Before and After, with an editable value,
*   the reason given by the model, the model and the token usage, and
    :guilabel:`What was sent?`,
*   the progress :guilabel:`Suggested` → :guilabel:`Saved` →
    :guilabel:`Verified` (:guilabel:`In text, unsaved` appears when the change
    is applied in the editor).

..  figure:: /Images/ai-fixes-review.png
    :alt: The review drawer for "Iframe is missing a title attribute" in the
          rich-text field of the record "Opening hours map": Before "(empty)",
          After "Map: location of our bakery (OpenStreetMap)", the reason, and
          the actions Apply and verify, Apply in the editor instead,
          Regenerate, Reject and Open record.

    The review drawer shows the complete change before anything is approved.

What happens on approval depends on the target:

Image alternative text of one image use
    AQG saves the reviewed text through its allow-listed DataHandler path,
    after checking that the target did not change since the suggestion was
    prepared.

Rich text and plain HTML
    :guilabel:`Apply and verify` stores the reviewed change through TYPO3
    DataHandler as you, with your record, field, language and workspace
    permissions. AQG changes only the target element: one attribute (iframe
    ``title``, button ``aria-label``) or the plain text of a link or an empty
    heading; the value is escaped and never inserted as markup. Every other byte
    of the field stays as it was, and an edit elsewhere in the field since the
    review is kept. If the element itself changed after the review, the item
    becomes a :guilabel:`Conflict` and nothing is written. AQG then checks the
    saved record and reports :guilabel:`Verified` or :guilabel:`Not verified`
    with the reason; in a workspace the change is published with the workspace.

    When AQG cannot prove the change (for example markup inside the link, or an
    HTML source stored encoded), the item is offered in the editor instead.
    :guilabel:`Apply in the editor instead` opens the record with the change
    staged; you apply it there (one undo step) and save with TYPO3
    :guilabel:`Save`. Rich text is never applied in a bulk run.

:guilabel:`Regenerate` asks for a new suggestion, :guilabel:`Reject` closes the
item without a change. Every item ends as :guilabel:`Saved`,
:guilabel:`Verified` (AQG's check of the stored record no longer finds the
issue) or :guilabel:`Not verified` with the reason; conflicts, failures,
rejections and findings that no longer apply are shown as such. Bulk review
approves only items whose change was opened.

..  _usage-ai-fixes-coverage:

What AI fixes cover
===================

Every AQG finding has a remediation path; only some can be changed by AQG.

Reviewed :guilabel:`Apply and verify` (13 rules)
    Link text (``rte.non_descriptive_link``, ``rte.link_text_is_url_or_filename``,
    ``rte.empty_link``), image alternative text in rich text and Plain HTML
    (``rte.img_alt_missing``, ``rte.img_alt_is_filename``,
    ``rte.img_alt_redundant_phrase``, ``rte.img_alt_too_long``,
    ``rte.image_in_link_missing_alt``), button names
    (``rte.button_label_missing``), iframe titles (``rte.iframe_missing_title``),
    empty headings (``rte.empty_heading``) and the alternative text of image
    uses (``structured.file_reference_alt``,
    ``structured.file_reference_alt_quality``). AQG changes exactly the one
    element or field after a person reviewed the suggestion. A link or heading
    whose text contains other elements (an icon, formatting) is fixed by hand.

Editor-guided (21 rules)
    The remaining rich-text and record findings (for example table headers and
    captions, document links, new-window links, form labels, header fields,
    transcripts) need an editorial decision. Page Detail shows how to fix each
    one; ``rte.heading_hierarchy_jump`` has a rule-based level change in
    CKEditor without AI.

Developer or template work (14 rules)
    Findings in the rendered page (``rendered.*``) come from templates,
    TypoScript or extensions. AQG shows developer guidance and, for empty links
    and iframes without a title, an AI text suggestion to review and copy; it
    never writes rendered output to a record.

AQG never applies rich text or HTML in a bulk run and never applies a change
without a person's click. When it cannot prove that only the reviewed element
changes, it opens the record in the editor with the change prepared instead.

..  _usage-ai-fixes-log:

Change log and permissions
==========================

:guilabel:`Change log` lists the events of the items you may see, filtered by
period, person, action and source. It stores codes and value hashes, not the
texts themselves.

Approving needs edit access to the record and field, the same workspace, and
either your own request or the User TSconfig option
:confval:`options.a11y_quality_gate.ai.bulk`. See
:ref:`configuration-permissions`.
