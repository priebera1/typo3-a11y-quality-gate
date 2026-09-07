:navigation-title: Command line

..  _automation-cli:

===================
Command line usage
===================

AQG registers the Symfony console command ``a11y:scan``.

..  code-block:: bash

    vendor/bin/typo3 a11y:scan --root-pid=1

..  _automation-cli-options:

Options
========

..  confval:: --root-pid
    :type: int

    Root page UID of the subtree to scan. Either ``--root-pid`` or
    ``--page-uid`` must be given.

..  confval:: --page-uid
    :type: int

    Scan a single page by UID.

..  confval:: --depth
    :type: int
    :Default: 99

    Maximum page tree depth for a subtree scan.

..  confval:: --language
    :type: string
    :Default: all

    ``sys_language_uid`` to scan, or ``all`` for every language.

..  confval:: --changed-only
    :type: flag

    Process only content that changed since the last scan. Uses the stored
    source state in :sql:`tx_a11y_source_state`.

..  _automation-cli-examples:

Examples
========

..  code-block:: bash

    # Scan a subtree
    vendor/bin/typo3 a11y:scan --root-pid=1

    # Scan a single page
    vendor/bin/typo3 a11y:scan --page-uid=42

    # Incremental rescan of changed content only
    vendor/bin/typo3 a11y:scan --root-pid=1 --changed-only

    # Scan one language of a subtree
    vendor/bin/typo3 a11y:scan --root-pid=1 --language=1

    # Limit the depth of the subtree scan
    vendor/bin/typo3 a11y:scan --root-pid=1 --depth=3

The command exits with a non-zero status when neither ``--root-pid`` nor
``--page-uid`` is supplied.

..  tip::
    Run a full scan after deployments and content imports, and a
    ``--changed-only`` scan on a shorter interval. The quality gate evaluates
    stored findings, so the more current the data is, the more useful the gate
    becomes.
