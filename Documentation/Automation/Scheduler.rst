:navigation-title: Scheduler

..  _automation-scheduler:

==================
Scheduler task
==================

AQG registers the Scheduler task **Accessibility Quality Gate** with the class
:php:`Priebera\A11yQualityGate\Scheduler\A11yScanTask`.

Create it in :guilabel:`System > Scheduler` and configure the same parameters as
for the CLI command:

..  list-table::
    :header-rows: 1

    *   -   Field
        -   Description

    *   -   Page UID
        -   Scan a single page. ``0`` means no single page.

    *   -   Root page
        -   Root page of the subtree to scan, selected from the available site
            roots. ``0`` means no subtree.

    *   -   Depth
        -   Maximum page tree depth, default ``99``.

    *   -   Language
        -   ``All languages`` (default), the default language, or one specific
            site language.

    *   -   Changed content only
        -   Process only content that changed since the last scan.

..  note::
    On TYPO3 14 the task fields are registered through TCA on
    :sql:`tx_scheduler_task`. On TYPO3 13 the same fields are provided by the
    additional field provider of the task. The stored parameters are identical,
    so a task keeps working across the upgrade.

..  _automation-scheduler-recommendation:

Recommended setup
=================

*   One task with :guilabel:`Changed content only` enabled, running frequently,
    for example hourly, to keep the quality gate data current.
*   One full task without that option, running nightly or weekly, so that
    findings are also refreshed for content whose change state was not tracked,
    for example after a database import.

Run large scans through the CLI or Scheduler rather than the backend module, so
that they are not limited by the PHP execution time of a backend request.
