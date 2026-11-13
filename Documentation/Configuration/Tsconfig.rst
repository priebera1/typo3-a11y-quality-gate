:navigation-title: TSconfig

..  _configuration-tsconfig:

==================
User TSconfig
==================

AQG evaluates a small number of options from **User TSconfig**. It reads them
through :php:`BackendUserAuthentication::getTSConfig()`, so set them in the
:guilabel:`TSconfig` field of a backend user group or of a backend user record.
Page TSconfig is a different configuration scope and is not evaluated for these
options.

These options only narrow what TYPO3's own permissions allow; none of them
grants access to a page, record, field or file. The complete list, including
the AI options, and two example groups are in
:ref:`configuration-permissions`.

..  code-block:: typoscript
    :caption: User TSconfig

    options.a11y_quality_gate {
        showToolbarItem = 1
        showScanAll = 1
        showScanNow = 1
        # not set: TYPO3's rights decide; 0 switches image fixes off
        # allowImageRemediation = 0
    }

..  confval:: options.a11y_quality_gate.showToolbarItem
    :type: boolean
    :Default: 1

    Shows the AQG item in the TYPO3 backend toolbar.

..  confval:: options.a11y_quality_gate.showScanAll
    :type: boolean
    :Default: 1

    Allows site scans: the :guilabel:`Scan site content` and
    :guilabel:`Scan published site` buttons, and cancelling a frontend site scan. Enforced by the
    server as well.

..  confval:: options.a11y_quality_gate.showScanNow
    :type: boolean
    :Default: 1

    Allows single-page scans: :guilabel:`Scan this page` (content and
    frontend), the Free Remote Preview and :guilabel:`Verify fix`. Enforced by
    the server as well.

..  confval:: options.a11y_quality_gate.allowImageRemediation
    :type: boolean
    :Default: not set

    Controls the image remediation actions (apply reviewed alternative text,
    mark an image as decorative or informative) for non-administrators. When
    the option is not set, TYPO3's own rights decide: a user who may edit the
    content element and the ``alternative`` and ``tx_a11y_is_decorative``
    fields of :sql:`sys_file_reference` can apply a reviewed fix. ``0`` (or
    any value other than ``1``) switches the actions off for the group;
    ``1`` keeps them on. Administrators always have the capability.

    Until AQG 3.0 the actions were off unless the option was ``1``. An
    explicit ``0`` keeps them off after the upgrade.

..  confval:: options.a11y_quality_gate.editRecord
    :type: boolean
    :Default: 1

    ``0`` removes :guilabel:`Ignore` and :guilabel:`Unignore` in the page
    details, in CKEditor and in the plain HTML editor. Enforced by the server.

..  confval:: options.a11y_quality_gate.showSettings
    :type: boolean
    :Default: 1

    ``0`` hides the Settings view (and the statement generator). Changing the
    global settings (rules, scan fields, Quality Gate, licence, remote scan
    access, AI, scan history) needs an administrator in any case.

..  confval:: options.a11y_quality_gate.ai.editor
    :type: boolean
    :Default: 1

    ``0`` removes AI help in CKEditor and the plain HTML editor, in the
    :guilabel:`AI fixes` view (Fix with AI, Explain, Review fixes) and the
    AI link text and iframe title suggestions in the page details. No AI
    request is sent for this user and nothing is billed. The accessibility
    checks and How to fix stay. See :ref:`configuration-ai`.

..  confval:: options.a11y_quality_gate.ai.images
    :type: boolean
    :Default: 1

    ``0`` removes AI suggestions for images: alternative text of an image use
    and file metadata. The alternative text status and guidance stay.

..  confval:: options.a11y_quality_gate.ai.writing
    :type: boolean
    :Default: 0

    ``1`` adds the writing help in CKEditor (clearer text, plain-language
    draft, translation draft in translated records).

..  confval:: options.a11y_quality_gate.ai.bulk
    :type: boolean
    :Default: 0

    ``1`` lets the group prepare AI fixes for several findings at once and
    review AI fixes requested by other people. Without it, users review their
    own requests.

..  confval:: options.a11y_quality_gate.mcp.connect
    :type: boolean
    :Default: 0

    ``1`` lets the user connect AI agents through the AQG MCP Gateway
    (:guilabel:`My connected agents`). It must be set explicitly, for
    administrators too. See :ref:`developer-agent-access`.

..  note::
    ``showToolbarItem``, ``showScanAll`` and ``showScanNow`` only affect
    non-administrators. Administrators always see the toolbar item and both scan
    buttons, regardless of these options.

..  note::
    ``allowImageRemediation`` never grants a TYPO3 right. Every remediation
    is additionally checked against TYPO3 permissions: the user must be allowed
    to edit both the content record and the :sql:`sys_file_reference`, the
    records must belong to the page of the finding, and the workspace and
    language of the reference must match the current context. Writes are
    restricted to the ``alternative`` and ``tx_a11y_is_decorative`` fields of
    :sql:`sys_file_reference`.
