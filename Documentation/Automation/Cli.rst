:navigation-title: Command line

..  _automation-cli:

===================
Command line usage
===================

AQG registers the Symfony console commands ``a11y:scan``, ``a11y:monitor``
(see :ref:`automation-monitoring`), ``a11y:installation:identity`` (see
:ref:`automation-cli-installation-identity`) and ``a11y:history:prune`` (see
:ref:`automation-cli-history-prune`).

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

..  _automation-cli-installation-identity:

Installation identity of a copied database
==========================================

Every TYPO3 installation has an AQG identity: a random id that AQG stores in
the database (:sql:`sys_registry`) the first time it contacts the AQG service.
The AQG service recognises the installation by it — an Agency licence's project
with its own frontend scans, history and evidence, a PRO licence's licensed
installation, and the Free Remote Preview allowance.

The identity moves with the database on purpose. Deployments, TYPO3 and AQG
updates, a new domain or Site Configuration, a moved server, a restored backup
and a staging copy of the same site keep it, so the installation keeps its
project and history. AQG never replaces it on its own.

A copy of an activated database that becomes a **different** installation — for
example a new client site built from an existing one — still has its source's
identity. To the AQG service both are one installation: they share one Agency
project and one scan history, and each one's sites replace the other's in the
project. Give that copy, and only that copy, a new identity:

..  code-block:: bash

    # Show the identity fingerprint (never the id itself)
    vendor/bin/typo3 a11y:installation:identity

    # On the copy only: a new identity, confirmed with the fingerprint shown above
    vendor/bin/typo3 a11y:installation:identity --new-installation --confirm=1a2b-3c4d-5e6f-7a8b

Two installations that show the same fingerprint are one installation for the
AQG service. ``--new-installation`` changes nothing unless ``--confirm`` names the
fingerprint of the installation it runs on, so a copied script or a run on the
wrong system is refused. The command cannot be scheduled.

After a new identity:

*   **Agency**: the copy is enrolled as a new project of the licence on its next
    licence check. It takes one project slot, starts with an empty frontend scan
    history and can no longer read the scans, history or evidence of the project
    it was copied from. Its first public domain is activated automatically;
    activate further domains in the :guilabel:`Licence` tab or the customer
    portal.
*   **PRO and Trial** cover one installation: the copy is refused as
    ``project_mismatch`` and needs a licence of its own.
*   **Free Remote Preview**: the site's proof file changes with the identity; the
    copy has its own daily allowance.
*   Cached licence answers and scanner tokens are cleared. Results of frontend
    scans stored in the copied database stay as they are, but their details
    remain with the source project.
*   The source installation keeps its identity, project and history.

The previous identity is not kept on the copy (it would let the copy act as its
source). To undo the change, restore the :sql:`sys_registry` entry
``a11y_quality_gate`` / ``anonymous_installation_id`` from a backup of the copy.

..  _automation-cli-history-prune:

Deleting older stored frontend scans
====================================

``a11y:history:prune`` applies the period chosen in
:guilabel:`Settings > Scan history` to the frontend scans stored in this TYPO3
installation. It is a dry run unless ``--apply`` is given:

..  code-block:: bash

    vendor/bin/typo3 a11y:history:prune          # report only
    vendor/bin/typo3 a11y:history:prune --apply  # delete

With the default period ("keep") nothing is ever selected. Periods are
calendar years in UTC: the command selects scans that finished before the same
date and time 1, 3 or 5 years ago (29 February counts back to 28 February) and
prints that boundary, the number of scans, stored pages and occurrences, the
oldest and newest selected scan, a breakdown per site and language and how many
older scans are kept and why.

The command is not offered as a Scheduler task: run it deliberately, so that a
shortened period never deletes scans nobody has looked at. Never deleted are
the newest scan of each comparison, running scans, the scans of a Verify fix
that is still running and the scans monitoring still compares with (the
latest evaluated and the latest complete run per site and language, and while
a run is not evaluated yet, every completed scan of its site and language). When the selection changes while the command runs — a scan
finishes, or a Verify fix or monitoring run starts to use a scan — nothing is
deleted and the command exits with an error; run it again. The AQG service's
own scan history and its screenshots are not affected (see
:ref:`privacy-security-retention`).
