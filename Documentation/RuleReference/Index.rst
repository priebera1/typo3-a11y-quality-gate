:navigation-title: Rule reference

===============
Rule reference
===============

..  _rule-reference:

AQG ships 48 built-in rules in three categories. Every rule can be enabled
or disabled individually, see :ref:`configuration-rules`.

The severity column shows the default severity. Some rules adjust their severity
depending on the detected case, for example a missing alternative text versus a
title-only fallback. The WCAG column names the success criteria a rule relates
to. A rule that does not report a finding does not prove conformance with that
criterion.

..  _rule-reference-severities:

Severities
==========

Critical
    A problem that very likely blocks users of assistive technology. Counted
    against :confval:`threshold_critical` of the quality gate.

Warning
    A problem that degrades accessibility or is a likely failure in most
    contexts. Counted against :confval:`threshold_warning`.

Info
    A recommendation. Not counted against the quality gate thresholds.

Needs review
    A case the automated rule cannot decide. A person has to look at it.

..  _rule-reference-rte:

RTE rules
=========

Applied to RTE bodytext of :sql:`tt_content` during content scans, and used for
the inline highlighting in CKEditor.

=============================================  ================  ========================
Rule ID                                        Default severity  WCAG 2.1 / 2.2 reference
=============================================  ================  ========================
``rte.img_alt_missing``                        Critical          1.1.1
``rte.img_alt_is_filename``                    Warning           1.1.1
``rte.img_alt_too_long``                       Warning           1.1.1
``rte.img_alt_redundant_phrase``               Warning           1.1.1
``rte.image_in_link_missing_alt``              Critical          1.1.1, 2.4.4
``rte.svg_missing_title``                      Warning           1.1.1
``rte.empty_heading``                          Critical          1.3.1
``rte.heading_hierarchy_jump``                 Warning           1.3.1
``rte.empty_link``                             Critical          2.4.4
``rte.non_descriptive_link``                   Warning           2.4.4
``rte.link_text_is_url_or_filename``           Warning           2.4.4
``rte.link_text_duplicate_different_targets``  Warning           2.4.4
``rte.link_to_document``                       Needs review      2.4.4
``rte.link_to_document_missing_notice``        Info              2.4.4
``rte.link_new_window_no_warning``             Warning           3.2.2
``rte.button_label_missing``                   Critical          4.1.2
``rte.form_control_missing_label``             Critical          4.1.2, 3.3.2
``rte.iframe_missing_title``                   Critical          4.1.2
``rte.table_missing_header``                   Warning           1.3.1
``rte.table_th_missing_scope``                 Warning           1.3.1
``rte.table_missing_caption``                  Info              1.3.1
``rte.duplicate_id``                           Warning           1.3.1
``rte.marquee_or_blink``                       Critical          2.2.2
=============================================  ================  ========================

..  _rule-reference-structured:

Structured rules
================

Applied to structured TCA field values during content scans, for example file
reference metadata, content element headers and form field configuration.

===============================================  ================  ========================
Rule ID                                          Default severity  WCAG 2.1 / 2.2 reference
===============================================  ================  ========================
``structured.file_reference_alt``                Critical          1.1.1
``structured.file_reference_alt_quality``        Warning           1.1.1
``structured.header_ctype_empty``                Warning           1.3.1
``structured.header_link_no_text``               Critical          2.4.4, 4.1.2
``structured.header_level_is_h1``                Needs review      1.3.1
``structured.uploads_file_missing_description``  Warning           2.4.4
``structured.table_missing_caption``             Info              1.3.1
``structured.form_placeholder_as_label``         Warning           1.3.1, 3.3.2
``structured.form_field_label_missing``          Critical          1.3.1, 3.3.2
``structured.form_autocomplete_missing``         Warning           1.3.5
``structured.media_no_transcript_hint``          Needs review      1.2.1
===============================================  ================  ========================

..  _rule-reference-rendered:

Rendered HTML rules
===================

Applied to the server-rendered HTML of a page. They run for
:guilabel:`Scan this page`, for :guilabel:`Scan site` and for single-page CLI or
Scheduler runs, but not for subtree CLI or Scheduler runs and never in
changed-only mode. See :ref:`usage-rendered-checks` for the full matrix.

========================================  ================  ========================
Rule ID                                   Default severity  WCAG 2.1 / 2.2 reference
========================================  ================  ========================
``rendered.img_missing_alt``              Critical          1.1.1
``rendered.svg_missing_accessible_name``  Warning           1.1.1
``rendered.empty_heading``                Critical          1.3.1
``rendered.empty_link``                   Critical          2.4.4
``rendered.empty_button``                 Critical          4.1.2
``rendered.iframe_missing_title``         Critical          4.1.2
``rendered.form_control_missing_label``   Critical          4.1.2, 3.3.2
``rendered.duplicate_id``                 Warning           1.3.1
``rendered.table_missing_header``         Warning           1.3.1
``rendered.table_empty_header``           Warning           1.3.1
``rendered.html_lang_missing``            Warning           3.1.1
``rendered.page_title_missing``           Warning           2.4.2
``rendered.main_landmark_missing``        Needs review      1.3.1
``rendered.landmark_unique``              Needs review      1.3.1
========================================  ================  ========================

..  _rule-reference-remote:

Rules of the frontend crawler
=============================

Frontend scans additionally run axe-core in a real browser. Their findings use
the axe rule identifiers, for example ``color-contrast``, ``target-size`` or
``region``, and are shown on the :guilabel:`Frontend scan` tab. They are not
part of the rule list above and cannot be toggled in the :guilabel:`Rules` tab.

..  note::
    Rules whose fix is a template or markup change — for example
    ``rendered.main_landmark_missing``, ``rendered.landmark_unique``,
    ``rendered.duplicate_id``, ``rendered.html_lang_missing`` or
    ``color-contrast`` — usually cannot be resolved by editors. Route them to
    integrators or developers.
