:navigation-title: Upgrade

..  _upgrade:

========
Upgrade
========

..  _upgrade-versioning:

Versioning
==========

AQG follows semantic versioning (``MAJOR.MINOR.PATCH``):

MAJOR
    Incompatible or breaking changes.

MINOR
    Backwards-compatible new functionality.

PATCH
    Backwards-compatible bug fixes.

The changelog of every release is maintained in :file:`CHANGELOG.md` in the
repository.

..  _upgrade-procedure:

Updating the extension
======================

..  code-block:: bash

    composer update priebera/typo3-a11y-quality-gate
    vendor/bin/typo3 extension:setup
    vendor/bin/typo3 cache:flush

In Classic installations, update the extension in the Extension Manager and run
the database analyser.

..  _upgrade-after:

After an update
===============

#.  Run :guilabel:`Re-scan TCA` in the Settings view if the release adds rules
    or supports new field types.
#.  Check the :guilabel:`Rules` tab. New rules are available for the first time
    and may need to be reviewed for your project.
#.  Run a full scan so that existing pages are re-evaluated with the new rules.
    Expect additional findings after a release that adds rules; this does not
    mean the site got worse.
#.  If the quality gate runs in blocking mode, run the full scan before the next
    editorial cycle, so that editors are not blocked by findings they have not
    seen yet.

..  _upgrade-typo3:

TYPO3 version upgrades
======================

AQG supports TYPO3 13.4 LTS and TYPO3 14.3 or later. TYPO3 14.0 to 14.2 are
declared as conflicting.

When upgrading TYPO3 13 to 14:

*   The backend module moves from :guilabel:`Web` to :guilabel:`Content`. The
    route identifier stays ``web_a11y``, so backend user group permissions,
    bookmarks and AJAX routes keep working.
*   Scheduler task parameters are unchanged. On TYPO3 14 the fields are
    registered through TCA on :sql:`tx_scheduler_task`, on TYPO3 13 through the
    additional field provider of the task.
*   Rulesets, findings, ignore states and field configuration are kept.

..  _upgrade-fingerprints:

Findings and fingerprints
=========================

Findings are matched across scans by a stable fingerprint. Ignore states survive
rescans as long as the underlying content is unchanged. Editing the content that
produced a finding creates a new finding, which is open again — this is
intentional, because the changed content has to be re-evaluated.
