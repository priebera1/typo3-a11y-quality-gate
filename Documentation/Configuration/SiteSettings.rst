:navigation-title: Site settings

..  _configuration-site-settings:

===========================
Site settings (site sets)
===========================

AQG ships the site set ``priebera/a11y-quality-gate``. Add it to a site
configuration to control the language handling of the phrase-based rules and to
customise their phrase lists.

..  _configuration-site-settings-set:

Including the site set
======================

In :file:`config/sites/<identifier>/config.yaml`:

..  code-block:: yaml
    :caption: config/sites/my-site/config.yaml

    dependencies:
      - priebera/a11y-quality-gate

The set also imports :file:`EXT:a11y_quality_gate/Configuration/TypoScript/setup.typoscript`,
which adds the optional frontend markers that let AQG map findings back to
:sql:`tt_content` records. In installations without site sets, include the
static template :guilabel:`Accessibility Quality Gate` instead.

The markers are not rendered for normal visitors. They are only emitted when the
request carries a valid scanner token, when a rendered page check supplies a
valid nonce, or when a logged-in backend user calls the page with
``?aqgDebug=1``. See :ref:`privacy-security-frontend` for details.

..  _configuration-site-settings-settings:

Available settings
==================

The settings can be edited in the TYPO3 backend under
:guilabel:`Site Management > Sites`, or written directly to
:file:`settings.yaml`.

..  confval:: a11yQualityGate.dictionary.mode

    :type: string
    :Default: auto
    :Allowed values: auto, force, disable

    Controls which language the phrase lists of the text-based rules use.
    ``auto`` follows the language of the scanned page, ``force`` always uses
    :confval:`a11yQualityGate.dictionary.forceLanguage`, and ``disable`` turns
    the phrase-based checks off.

..  confval:: a11yQualityGate.dictionary.forceLanguage

    :type: string
    :Default: (empty)

    Language code used when the dictionary mode is ``force``, for example
    ``en``, ``de``, ``sk``, ``fr`` or ``pl``.

..  confval:: a11yQualityGate.dictionary.rte_non_descriptive_link.additionalPhrases

    :type: stringlist
    :Default: []

    Additional phrases that are flagged as non-descriptive link text by
    ``rte.non_descriptive_link``.

..  confval:: a11yQualityGate.dictionary.rte_non_descriptive_link.disabledPhrases

    :type: stringlist
    :Default: []

    Built-in non-descriptive phrases that should be ignored for this site.

..  confval:: a11yQualityGate.dictionary.rte_link_new_window_no_warning.additionalPhrases

    :type: stringlist
    :Default: []

    Additional phrases that count as a valid new-window or new-tab warning for
    ``rte.link_new_window_no_warning``.

..  confval:: a11yQualityGate.dictionary.rte_link_new_window_no_warning.disabledPhrases

    :type: stringlist
    :Default: []

    Built-in new-window warning phrases that should be ignored for this site.

..  code-block:: yaml
    :caption: config/sites/my-site/settings.yaml

    a11yQualityGate:
      dictionary:
        mode: force
        forceLanguage: de
        rte_non_descriptive_link:
          additionalPhrases:
            - 'hier klicken'
            - 'mehr'

..  note::
    The built-in phrase list of ``rte.non_descriptive_link`` is English only.
    Add localised phrases through ``additionalPhrases`` for every language you
    publish in, or set the dictionary mode to ``disable`` if you do not want
    phrase-based link checks at all.
