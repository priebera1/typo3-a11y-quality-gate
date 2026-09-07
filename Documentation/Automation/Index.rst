:navigation-title: Automation

..  _automation:

==========
Automation
==========

Content scans can be automated with the TYPO3 command line interface and with
the TYPO3 Scheduler. Both are available in all editions.

..  note::
    Subtree runs (``--root-pid``, or a Scheduler task with a root page) apply the
    local rules (``rte.*`` and ``structured.*``) only. Single-page runs
    (``--page-uid``, or a Scheduler task with a page UID) additionally run the
    rendered page check, see :ref:`usage-rendered-checks`. Changed-only runs never
    include it. Frontend crawler scans are always started from the backend module,
    see :ref:`usage-remote-scans`.

..  toctree::
    :titlesonly:

    Cli
    Scheduler
