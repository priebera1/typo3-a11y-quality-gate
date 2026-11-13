:navigation-title: Images

..  _usage-images:

=================================
Image alternative text
=================================

Every informative image needs an alternative text that describes what the image
shows or does. Purely decorative images are marked as decorative instead, so
that screen readers skip them. AQG helps with both directly in the image field
of a content element, in the page details and — optionally — with AI.

..  _usage-images-status:

Status of each image use
========================

In content elements with images (for example :guilabel:`Text & Images`), AQG
shows the alternative text state of each image in its header:

:guilabel:`ALT missing`
    Neither this use nor the file has an alternative text.

:guilabel:`ALT own`
    This use has its own alternative text (:guilabel:`Set element specific
    value`).

:guilabel:`ALT inherited`
    This use shows the alternative text stored in the file's metadata (the file
    default, shared by every use of the file).

:guilabel:`Decorative`
    The image is marked as decorative; TYPO3 renders an empty alternative text.

A text that was entered or applied but not saved yet is shown as unsaved until
you press :guilabel:`Save`.

While the alternative text is missing, a short line below the field says what to
do: describe the image, use the file default, or switch on
:guilabel:`Decorative image` if the image adds no information. The status and
the guidance update as you type, need no licence and no AI, and send no request.

..  figure:: /Images/image-alt-status-guidance.png
    :alt: An image use in a Text & Images element. The header shows the status
          "ALT missing"; below the empty Alternative Text field AQG explains
          that neither this use nor the file has an alternative text and how
          to fix it. The Decorative image switch is directly below.

    The image header shows the state; the guidance below the field explains
    the next step.

..  _usage-images-write:

Writing a good alternative text
===============================

*   Describe what the image shows or does in this context, in one short
    sentence. Leave out "image of" or "photo of".
*   Do not use the file name.
*   For a linked image, describe the link target.
*   Use :guilabel:`Decorative image` only when the image conveys no
    information; the alternative text is then empty on purpose.
*   The file default (in the file's metadata) is used by every content element
    that does not set its own text. Set an element-specific value when the
    context needs a different description.

After :guilabel:`Save`, AQG checks the saved reference with its structured
image rules; the Page module and the page details show the result.

..  _usage-images-ai:

AI suggestion for one image use
===============================

When AI is set up for the site (PRO or Agency licence, :guilabel:`Allow images`
switched on, see :ref:`configuration-ai`), a wand next to the
:guilabel:`Alternative text` field offers :guilabel:`Suggest for this use`.
The suggestion uses the image, the element's header and first paragraph and,
when allowed, the page title. It stays editable; :guilabel:`Apply to field`
only fills the form field and ticks :guilabel:`Set element specific value`.
TYPO3 :guilabel:`Save` stores it for this use only — the file default and other
uses stay unchanged.

..  figure:: /Images/image-alt-ai-suggestion.png
    :alt: The AQG popover "Alternative text for this use" with an editable
          suggestion "Library building with a clock tower and the main
          entrance", the reason, the model used and the buttons Apply to
          field, Discard and Regenerate.

    An AI suggestion for one image use. It is applied to the form field only
    after review and stored with TYPO3 Save.

The file default itself can be suggested in TYPO3's metadata form: in the
:guilabel:`Filelist`, choose :guilabel:`Suggest metadata with AI` in the file's
context menu. See :ref:`configuration-ai-media` for what is sent and which
permissions apply.

..  _usage-images-page-detail:

Image fixes in the page details
===============================

Image findings in the page details show the image with an
:guilabel:`Alternative text` field and the actions :guilabel:`Apply reviewed
text` and :guilabel:`Mark as decorative`, and — with AI set up —
:guilabel:`Suggest alt text with AI`. These actions write only the
``alternative`` and ``tx_a11y_is_decorative`` fields of that file reference,
with the user's own TYPO3 permissions. See :ref:`usage-page-detail-remediation`.
