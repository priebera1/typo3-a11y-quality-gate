:navigation-title: Page detail

..  _usage-page-detail:

============
Page detail
============

The page details list the issues of one page, grouped by severity. Open them
from a page in the Overview, from :guilabel:`Open issues` in the Page module or
from the toolbar. Each issue type shows its name, :guilabel:`How to fix` and its
occurrences with the affected record, field and a snippet of the matched
markup; :guilabel:`Edit record` opens the content element.

..  figure:: /Images/page-detail.png
    :alt: The page details of "Visit the library": content scan, last scan,
          the Remediation on this page panel with open issues by owner
          (5 Editor, 8 Developer), filters Open, Ignored, Resolved and All by
          severity, and the first critical issue type "Image file reference
          is missing alt text" with How to fix and Edit record.

    The page details group the issues by severity, with How to fix for each
    issue type and the remediation summary at the top.

The filters switch between :guilabel:`Open`, :guilabel:`Ignored`,
:guilabel:`Resolved` and :guilabel:`All` and by severity. The language selector
and :guilabel:`Export` work on the selected language.

..  _usage-page-detail-guidance:

Guidance
========

Every issue type shows one concrete fix (:guilabel:`How to fix`) directly. More
detail is folded away:

*   :guilabel:`Why it matters` — which users are affected and why,
*   :guilabel:`Standards and rule reference` — the referenced WCAG success
    criteria and the rule ID,
*   :guilabel:`Technical details` of an occurrence — the matched markup and
    record information.

When no richer metadata exists for a rule, the short rule hint is shown instead.

..  _usage-page-detail-remediation-plan:

Remediation on this page
========================

The panel at the top counts the open issues by owner — :guilabel:`Editor`,
:guilabel:`Developer` or :guilabel:`Mixed` — and the state of AI fixes for the
page (waiting for review, in the editor, saved and checking, saved but not
verified).

:guilabel:`Page remediation plan`
    Opens a side panel built from the stored findings, without AI, grouped by
    owner, with :guilabel:`Go to issue` and :guilabel:`Copy as ticket` for each
    step.

:guilabel:`Open AI fixes`
    Opens :ref:`usage-ai-fixes` filtered to this page.

The owner of an issue type (Editor, Developer, Mixed or Unassigned) can be
changed in the issue type's actions; every change is logged.

..  _usage-page-detail-ignore:

Ignoring findings
=================

A finding that is a false positive or that is accepted for a documented reason
can be ignored. AQG offers:

:guilabel:`Ignore`
    Ignores a single finding, with an optional expiry date. When the date is
    reached the finding becomes open again.

:guilabel:`Batch ignore`
    Ignores several selected findings at once. A reason must be confirmed.

:guilabel:`Ignore rule on this page`
    Ignores all current and future findings of one rule on this page.

:guilabel:`Ignore rule on this site`
    Ignores all current and future findings of one rule across the site.

:guilabel:`Unignore`
    Returns a finding to the open state.

Ignored findings do not count towards the quality gate thresholds. Because
findings are matched by a stable fingerprint, the ignore state survives
rescans as long as the underlying content does not change.

..  important::
    Ignoring hides a finding from the workflow. It does not fix the accessibility
    problem and does not change how the page behaves for users of assistive
    technology. Always record why a finding was ignored.

..  _usage-page-detail-remediation:

Image remediation
=================

For FAL image findings, AQG offers actions that write directly to the file
reference:

:guilabel:`Mark as decorative`
    Sets ``tx_a11y_is_decorative = 1`` and clears ``alternative`` in one
    DataHandler operation. Use this only for images that carry no information.

:guilabel:`Mark as informative`
    Sets ``tx_a11y_is_decorative = 0`` and leaves ``alternative`` untouched.

:guilabel:`Apply reviewed text`
    Writes the reviewed alternative text to ``alternative``.

These actions are available to backend users who may edit the content element
and the image's ``alternative`` and ``tx_a11y_is_decorative`` fields, unless
:confval:`options.a11y_quality_gate.allowImageRemediation` is ``0`` for them.
They need no licence. See :ref:`configuration-permissions` and
:ref:`usage-images`.

..  figure:: /Images/page-detail-image-ai-suggestion.png
    :alt: An image finding in the page details with a preview of the image,
          an AI-generated alternative text in the editable field, the note
          "AI-generated suggestion. Review and edit it before applying." and
          the actions Suggest alt text with AI, Discard suggestion, Apply
          reviewed text and Mark as decorative.

    An image finding with an AI suggestion. The text is written only when you
    choose Apply reviewed text.

..  _usage-page-detail-ai:

AI actions
==========

With AI set up for the site (PRO or Agency, see :ref:`configuration-ai`):

:guilabel:`Suggest alt text with AI`
    Fills the alternative text field of an image finding with a suggestion,
    marked as AI-generated. Review and edit it, then choose
    :guilabel:`Apply reviewed text` or :guilabel:`Discard suggestion`.

:guilabel:`Fix with AI`
    Prepares a change for a supported occurrence (for example an empty
    heading, link text, an iframe title or an image's alternative text) as an
    item in :ref:`usage-ai-fixes`. Nothing changes until someone reviews and
    approves it there.

:guilabel:`Explain with AI`
    Explains the issue in the context of this occurrence without preparing a
    change. The explanation opens in its own panel below the occurrence —
    what the problem is and how to fix it — says "Nothing was changed." and
    closes again with the same button.

Fix with AI shows exactly one result:

*   :guilabel:`Suggestion ready for review` with :guilabel:`Review
    suggestion`, which opens exactly that item in AI fixes.
*   :guilabel:`Already in AI fixes` with the item's real status, for example
    when it was prepared in another tab.
*   :guilabel:`No usable suggestion`, when the AI answered without a usable
    change.
*   :guilabel:`AI request failed` with the reason and :guilabel:`Try again`,
    which asks again for the same item.
*   :guilabel:`Fix with AI is not possible here` with the exact reason; no item
    is created and nothing is changed.

Each result says whether a request reached the AI provider and, if so, its
recorded cost. An occurrence already in AI fixes shows its state with
:guilabel:`Review suggestion` or :guilabel:`Open in AI fixes`.

Before anything is queued or sent, AQG checks an image finding's file. A
missing file, an external URL (never fetched), a file outside every file
storage, a file that is not an image, a file you may not read or a file that
cannot be read gets its own reason, no AI fixes item and no AI request.

AI actions are offered only for fields you may edit in the form, including
fields TYPO3 shows read-only or hides with a display condition; the server
checks the same rules again. The buttons show the maximum estimated cost of
one request, for example ``(<$0.02)``, and :guilabel:`How the AI actions work`
in the remediation panel explains the actions and amounts. Findings whose fix
is a template or markup change get no AI action. Progress and results are
announced to screen readers, and keyboard focus moves to the result.

..  _usage-page-detail-preview:

Hidden pages
============

A hidden page cannot be scanned like a public page. With a licence for
frontend scans and a Scanner Token for the site, the page details of a hidden page offer :guilabel:`Scan unpublished
page`; the result is a preview scan, labelled "Preview scan · page not public",
kept in its own history and never compared with public scans. See
:ref:`usage-remote-scans`.
