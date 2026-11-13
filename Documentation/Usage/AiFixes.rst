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
the page details (see :ref:`usage-page-detail-ai`). The Overview and the page
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
*   the progress :guilabel:`Suggested` → :guilabel:`In text, unsaved` →
    :guilabel:`Saved` → :guilabel:`Verified`.

..  figure:: /Images/ai-fixes-review.png
    :alt: The review drawer for "Iframe is missing a title attribute" on the
          page Find us: Before "(empty)", After "Map of the library location",
          the reason, and the actions Approve and open in editor, Regenerate,
          Reject and Open record.

    The review drawer shows the complete change before anything is approved.

What happens on approval depends on the target:

Image alternative text of one image use
    AQG saves the reviewed text through its allow-listed DataHandler path,
    after checking that the target did not change since the suggestion was
    prepared.

Rich text and plain HTML
    :guilabel:`Approve and open in editor` opens the record with the approved
    change staged. You apply it in the editor (one undo step) and save with
    TYPO3 :guilabel:`Save`. AQG never rewrites rich text or HTML on the server.

:guilabel:`Regenerate` asks for a new suggestion, :guilabel:`Reject` closes the
item without a change. Every item ends as :guilabel:`Saved`,
:guilabel:`Verified` (AQG's check of the stored record no longer finds the
issue) or :guilabel:`Not verified` with the reason; conflicts, failures,
rejections and findings that no longer apply are shown as such. Bulk review
approves only items whose change was opened.

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
