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

..  code-block:: typoscript
    :caption: User TSconfig

    options.a11y_quality_gate {
        showToolbarItem = 1
        showScanAll = 1
        showScanNow = 1
        allowImageRemediation = 0
    }

..  confval:: options.a11y_quality_gate.showToolbarItem
    :type: boolean
    :Default: 1

    Shows the AQG item in the TYPO3 backend toolbar.

..  confval:: options.a11y_quality_gate.showScanAll
    :type: boolean
    :Default: 1

    Shows the :guilabel:`Scan site` button in the overview module.

..  confval:: options.a11y_quality_gate.showScanNow
    :type: boolean
    :Default: 1

    Shows the :guilabel:`Scan this page` button in page-related and
    record-related views.

..  confval:: options.a11y_quality_gate.allowImageRemediation
    :type: boolean
    :Default: 0

    Allows non-administrator backend users to use the image remediation actions
    (apply reviewed alternative text, mark an image as decorative or
    informative). Administrators always have the capability.

..  note::
    ``showToolbarItem``, ``showScanAll`` and ``showScanNow`` only affect
    non-administrators. Administrators always see the toolbar item and both scan
    buttons, regardless of these options.

..  note::
    ``allowImageRemediation`` only grants the AQG capability. Every remediation
    is additionally checked against TYPO3 permissions: the user must be allowed
    to edit both the content record and the :sql:`sys_file_reference`, the
    records must belong to the page of the finding, and the workspace and
    language of the reference must match the current context. Writes are
    restricted to the ``alternative`` and ``tx_a11y_is_decorative`` fields of
    :sql:`sys_file_reference`.
