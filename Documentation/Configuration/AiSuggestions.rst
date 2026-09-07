:navigation-title: AI suggestions

..  _configuration-ai:

===========================
AI-assisted text suggestions
===========================

AQG can propose alternative text, link text and iframe titles for selected
findings. The feature is optional, disabled by default, review-only and requires
an OpenAI API key that you provide yourself. It is configured on the
:guilabel:`AI` tab of the Settings view and requires an active licence.

..  _configuration-ai-principles:

Principles
==========

*   **Bring your own key.** AQG does not proxy AI requests through the AQG
    service. Requests go from your TYPO3 installation directly to OpenAI with
    your own project key.
*   **Review only.** AQG never applies a suggestion automatically. An editor
    must review and explicitly accept every suggestion.
*   **Opt in.** Both the AI configuration and the individual suggestion types
    are switched off until an administrator enables them.
*   **No storage on the provider side.** Requests are sent with ``store=false``.

..  _configuration-ai-key:

Configuring the API key
=======================

The key can be provided in two ways:

Per site
    Enter an OpenAI project key on the :guilabel:`AI` tab. The key is stored
    encrypted in :sql:`tx_a11y_ai_configuration` and only ever displayed as a
    masked hint. Encryption requires the PHP ``sodium`` extension.

Globally
    Set the environment variable ``AQG_OPENAI_API_KEY``. It is used as a
    fallback when no site-specific key is configured. The tab shows that an
    environment key is active.

..  _configuration-ai-model:

Selecting and verifying a model
===============================

#.  Press :guilabel:`Refresh models`. AQG asks OpenAI which models the project
    key may use and filters them through the AQG compatibility registry. Models
    that are available to your project but not supported by AQG are listed
    separately and cannot be selected.
#.  Select a supported model.
#.  Press :guilabel:`Test connection`. AQG verifies the key, the selected model,
    the prompt version and the structured-output contract. Only a verified
    combination can be used for suggestions.

The tab shows the last test time, the last verification time and, on failure, a
machine-readable error code such as ``insufficient_quota``,
``model_not_permitted`` or ``connection_rate_limited``.

..  _configuration-ai-scope:

Which findings get suggestions
==============================

Alternative text suggestions are offered for FAL image findings:

*   ``structured.file_reference_alt``
*   ``structured.file_reference_alt_quality``

Link text and iframe title suggestions must be enabled separately with the
:guilabel:`Text suggestions` toggle (disabled by default). They are offered for:

*   ``rte.non_descriptive_link``
*   ``rte.empty_link``
*   ``rendered.empty_link``
*   ``rendered.iframe_missing_title``

Rules whose fix is a template or markup change, for example
``rendered.main_landmark_missing``, ``rendered.duplicate_id`` or
``rendered.html_lang_missing``, never receive AI suggestions.

..  _configuration-ai-data:

What is sent to the provider
============================

The browser sends only a ``findingId`` to TYPO3. The extension resolves the
context server-side and sends the minimum required data to OpenAI:

*   for alternative text: the referenced image and the surrounding content
    context,
*   for link text and iframe titles: the identified element and its immediate
    context.

User content is always passed as content, never as instructions. Suggestions
that contain HTML, encoded HTML, raw URLs, multiline text, control characters or
generic link text are rejected before they are shown. When the model cannot
produce a safe suggestion, AQG reports that no suggestion is available instead
of guessing.

..  important::
    Sending content to OpenAI is a transfer of data to a third-party processor.
    Check your own data protection requirements before enabling the feature, and
    do not enable it for sites whose content must not leave your infrastructure.
    See :ref:`privacy-security`.
