:navigation-title: AI suggestions

..  _configuration-ai:

===========================
AI-assisted text suggestions
===========================

AQG can propose alternative text, link text and iframe titles for selected
findings. The feature is optional, disabled by default, review-only and requires
an API key of OpenAI or Anthropic Claude that you provide yourself. It is
configured per TYPO3 site under
:guilabel:`Accessibility > Settings > AI suggestions` (administrators only);
editors see suggestions only with a valid PRO or Agency
licence for the site.

..  _configuration-ai-principles:

Principles
==========

*   **Bring your own key.** AQG does not proxy AI requests through the AQG
    service. Requests go from your TYPO3 installation directly to the provider
    with your own key.
*   **One provider per site.** Each site uses exactly one provider and one
    model. Switching never deletes the other provider's key or model choice.
*   **Review only.** AQG never applies a suggestion automatically. An editor
    must review and explicitly accept every suggestion.
*   **Opt in.** Both the AI configuration and the individual suggestion types
    are switched off until an administrator enables them.
*   **No storage on the provider side (OpenAI).** OpenAI requests are sent
    with ``store=false``. How Anthropic retains API data depends on Anthropic's
    terms and your account; AQG cannot check or change it.

..  _configuration-ai-provider:

Choosing the provider
=====================

Select the site at the top of the tab, choose :guilabel:`OpenAI` or
:guilabel:`Anthropic Claude` and press :guilabel:`Save provider`. Switching an
active provider asks for confirmation. After a switch:

*   the other provider's encrypted key, model choice and history stay stored,
*   AI is paused for the site's editors until a model of the new provider passes
    the connection test,
*   a model that was verified for this provider earlier is tested again before
    it is used (:guilabel:`Test the connection again`).

Sites that used OpenAI before AQG 3.0 keep OpenAI; no provider has to be chosen
after the upgrade. They still need the processing acknowledgement and a verified
model before AI reaches their editors again (see :ref:`upgrade-procedure`).

Switching the provider also clears the processing acknowledgement: it names one
provider and never carries over to the other one.

..  _configuration-ai-readiness:

When editors get AI
===================

The readiness line at the top of the tab is computed by the same server-side
policy that decides whether editors see AI. Every item must hold:

#.  a PRO or Agency licence for the site,
#.  a chosen provider,
#.  an API key of that provider,
#.  a verified model: selected, available to the key, and passed the
    connection test with the current key, prompt and connection contract,
#.  the processing acknowledgement for the active provider,
#.  a budget that does not block (see :ref:`configuration-ai-cost`).

..  figure:: /Images/settings-ai-readiness.png
    :alt: Settings, tab AI suggestions, for the site main: "AI active for
          editors", the readiness line with PRO or Agency licence, Provider
          OpenAI, API key stored, Model verified, Privacy acknowledged and
          Budget, and the provider choice between OpenAI (active) and
          Anthropic Claude.

    The readiness line shows at a glance whether editors of the site get AI,
    and which item is missing if not.

When an item is missing, editors see no AI buttons, and every AI request is
refused server-side with a safe code (``paused``, or ``budget_reached`` for a
used-up budget). The connection test is the exception: it needs neither the
acknowledgement nor the budget, because it sends a fixed prompt and a 16-pixel
synthetic image, never site content.

..  _configuration-ai-key:

Configuring the API key
=======================

The key can be provided in two ways:

Per site
    Enter the key of the site's provider on the :guilabel:`AI suggestions` tab: an OpenAI
    project key, or an Anthropic API key (``sk-ant-…``). Each provider's key is
    stored encrypted in its own row of :sql:`tx_a11y_ai_configuration` and only
    ever displayed as a masked hint. Encryption requires the PHP ``sodium``
    extension. Anthropic keys cannot be limited to reading the model list: any
    valid key can make billable requests.

Globally (OpenAI only)
    Set the environment variable ``AQG_OPENAI_API_KEY``. It applies to sites
    whose provider is OpenAI and overrides their site keys. The tab shows that
    an environment key is active. There is no environment variable for
    Anthropic.

..  _configuration-ai-model:

Selecting and verifying a model
===============================

#.  Press :guilabel:`Refresh models`. AQG asks the provider which models the
    key may use (``GET /v1/models``) and filters them through the AQG
    compatibility registry. For Anthropic, a model is also excluded when
    Anthropic reports that it cannot take images or structured outputs. Models
    that are available but not supported by AQG are listed separately and
    cannot be selected. When the list cannot be loaded, no model is selected:
    AQG never falls back to a default model. A model is selected automatically
    only when exactly one supported model is available.
#.  Select a supported model.
#.  Press :guilabel:`Test connection`. AQG verifies the key, the selected model,
    the prompt version and the structured-output contract. Only a verified
    combination can be used for suggestions.

The tab shows the last test time, the last verification time and, on failure, a
machine-readable error code such as ``insufficient_quota``,
``provider_billing_error``, ``model_not_permitted``, ``model_unavailable`` or
``connection_rate_limited``. The connection test sends a fixed prompt and a
16-pixel test image, never site content. Editors get AI only for a verified
model; a model that is selected but not verified keeps AI paused. Test connection and Refresh models
share the AI rate limit of the user and site (10 requests per minute).

Supported Claude models in this release: Claude Haiku 5.5, Sonnet 5.5,
Opus 5.5, Sonnet 5, Opus 5, Opus 4.8 and Haiku 4.5. AQG requests a low
reasoning effort and never uses a server-side fallback model, so content only
reaches the model the administrator selected and verified.

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

..  _configuration-ai-editors:

Fixes in CKEditor and the plain HTML editor
===========================================

In rich text fields (CKEditor 5) and in :guilabel:`Plain HTML` content elements,
a small wand on AQG's existing marker shows that a finding can be fixed with AI.
The finding's details then offer :guilabel:`Fix with AI` and :guilabel:`Explain`.
Nothing changes until the editor reviews the suggestion and presses
:guilabel:`Apply to text` (CKEditor) or :guilabel:`Apply to source` (plain HTML).
The change then lives in the editor only; TYPO3 Save stores it, and AQG checks
the saved record before it reports the finding as resolved and verified.

Supported findings and the one change each may make:

*   ``rte.non_descriptive_link``, ``rte.link_text_is_url_or_filename``,
    ``rte.empty_link``: the text of that link,
*   ``rte.img_alt_missing``, ``rte.img_alt_is_filename``,
    ``rte.img_alt_redundant_phrase``, ``rte.img_alt_too_long``,
    ``rte.image_in_link_missing_alt``: the ``alt`` attribute of that image,
*   ``rte.button_label_missing``: an ``aria-label`` on that button,
*   ``rte.iframe_missing_title``: the ``title`` attribute of that iframe,
*   ``rte.empty_heading``: the text of that empty heading,
*   ``rte.heading_hierarchy_jump``: a rule-based level change without an AI
    request (CKEditor, when the level is available in the editor's heading list).

Link text and iframe titles follow the site's :guilabel:`Text suggestions`
switch; image fixes follow :guilabel:`Allow images`. Findings without a safe
change (tables, form labels, landmarks, template placeholders, links with inner
formatting) get no AI action.

Exact target
    The live check identifies the element of each finding by its position among
    all elements with the same tag and by its attributes and text. The editor
    proves it found that element before it requests or applies anything, so
    identical duplicates stay apart. If the element changed while the suggestion
    was prepared, the suggestion is marked as outdated and cannot be applied. In
    the plain HTML editor a change may only set one attribute value or replace
    the text of an element without inner markup; AQG dry-runs it and offers it
    only when everything outside that range stays byte-identical.

Undo and saving
    Apply is one CKEditor change or one CodeMirror transaction, so one
    :kbd:`Ctrl+Z` / :kbd:`Cmd+Z` restores the previous text. AQG never writes
    rich text or HTML on the server.

Review fixes
    When a field has two or more fixes, :guilabel:`Review fixes (n)` in the
    summary bar lists all of them. AI suggestions start undecided; only accepted
    items are applied, each as its own undo step.

Keyboard
    :kbd:`F8` and :kbd:`Shift+F8` open the next and previous finding in both
    editors; :guilabel:`Next issue` in the summary bar does the same.

Permissions
    Editor AI needs a PRO or Agency licence, a verified model for the site, edit
    access to the field, and the User TSconfig option
    ``options.a11y_quality_gate.ai.editor`` (default ``1``). Writing help
    (:guilabel:`Make clearer`, :guilabel:`Plain-language draft` and, inside a
    translated record, :guilabel:`Translate draft (review)`) additionally needs
    ``options.a11y_quality_gate.ai.writing = 1`` (default ``0``). Administrators
    have both.

..  code-block:: typoscript
    :caption: User TSconfig

    options.a11y_quality_gate.ai.editor = 1
    options.a11y_quality_gate.ai.writing = 0

..  _configuration-ai-media:

Image metadata in the Filelist and in image fields
==================================================

File default in the metadata form
    For JPEG, PNG, WebP and SVG images the user may edit,
    :guilabel:`Suggest metadata with AI` appears in the file's context menu
    (tile and list view) and in the list view's :guilabel:`More actions` menu.
    It opens TYPO3's own metadata form of the file. There the AQG wand beside
    :guilabel:`Alternative text`, :guilabel:`Title` and :guilabel:`Description`
    shows the stored value, a suggestion and how many content elements use the
    file. :guilabel:`Apply to field` only fills the form field ("Filled in,
    unsaved"); TYPO3 :guilabel:`Save` stores it with the user's own
    permissions, history, undo and workspace handling. Uses with their own
    alternative text keep it. After the save AQG runs its structured image
    checks on the uses that show the file default; a save alone is reported as
    "Saved · not verified".

No AQG write path into file metadata
    AQG has no endpoint that writes ``sys_file_metadata``. It suggests only
    ``alternative``, ``title`` and ``description``, and only where TYPO3 allows
    the user to edit them: FAL's metadata permission for the file (storage,
    file mounts), modify access to ``sys_file_metadata`` and the fields'
    exclude settings. A licence never widens TYPO3 permissions. Whether AQG may
    apply reviewed values to several files itself is an open product decision;
    until it is taken, every file is saved in its own TYPO3 form.

Several files and uploads
    There is no multi-file apply. When several files are selected, an AQG
    line below TYPO3's selection bar says so and points to the per-file entry;
    nothing is requested or sent. After an upload, one notification lists up
    to five new images without alternative text, with one
    :guilabel:`Open metadata` action per image that opens its metadata form.
    Nothing is sent to an AI provider before the editor asks for a
    suggestion in the form. ``ai.bulk`` has no effect in the Filelist.

What is sent for image metadata
    The image (scaled down; SVG as a PNG rendered by TYPO3, and only when the
    SVG refers to nothing outside itself), the file name, the name of its
    folder, the metadata language, the stored values of the requested fields
    and, for SVG, its own text labels. For one image use: the image, the
    element's header and first paragraph, the file default, the language, the
    page title when :guilabel:`Allow page titles` is on, and for a linked image
    the linked page's title (same switch, only pages the user may read) or the
    host name. Never the file path, user names, other files or their uses.

Languages
    Each language has its own metadata record. A suggestion for German fills
    only the German record and is written in German; AQG never creates a
    missing translation (use TYPO3's :guilabel:`Translate`) and never falls
    back to the default record. Inside a translated record,
    :guilabel:`Translate from the default language` sends the default-language
    text only, never the image.

Diagrams and charts
    For SVG files AQG first reads the SVG's own text labels without AI, then
    sends a PNG rendered by TYPO3, never the SVG itself. Diagrams, charts,
    maps and screenshots carry a :guilabel:`Manual review` badge; the model is
    told to name what it could not read instead of guessing values.

One image use in a content element
    The wand beside an image's :guilabel:`Alternative text` suggests text for
    this use only, from the image, the element's header and first paragraph,
    the page title (when allowed) and the file default. :guilabel:`Apply to
    field` fills the form field and ticks :guilabel:`Set element-specific
    value`; TYPO3 Save stores it on this reference only. The file default and
    every other use stay unchanged. :guilabel:`Use file default` (no AI)
    restores inheritance, and a :guilabel:`Probably decorative` answer only
    offers the existing :guilabel:`Decorative image` switch. After Save AQG
    runs its structured image checks on the saved reference.

..  code-block:: typoscript
    :caption: User TSconfig

    options.a11y_quality_gate.ai.images = 1
    options.a11y_quality_gate.ai.bulk = 0

..  _configuration-ai-data:

What is sent to the provider
============================

In the AQG module the browser sends only a ``findingId`` to TYPO3. The
extension resolves the context server-side and sends the minimum required data
to the site's provider:

*   for alternative text: the referenced image and the surrounding content
    context,
*   for link text and iframe titles: the identified element and its immediate
    context.

In the editors the content is not saved yet, so the editor sends its current
field content; AQG runs the finding's rule on it again, derives the element
itself and sends only that element, the text of its block, and the link or
embed address without query string or fragment (e-mail addresses and phone
numbers are reduced to their type). :guilabel:`What was sent?` under each
suggestion lists exactly what left AQG.

User content is always passed as content, never as instructions. Suggestions
that contain HTML, encoded HTML, raw URLs, multiline text, control characters or
generic link text are rejected before they are shown. When the model cannot
produce a safe suggestion, AQG reports that no suggestion is available instead
of guessing.

The :guilabel:`What may be sent to the AI provider` section of the tab
controls two site-wide switches, both on by default so existing sites keep
their behaviour:

Allow images
    When off, AQG offers no AI for image findings and never sends an image.

Allow page titles
    When off, page titles are removed from every request.

A table on the tab shows what each feature sends with the current switches.

Processing acknowledgement
    Below the table an administrator confirms: "I confirm that a data
    processing agreement (DPA) with <provider> covers this site, or that sending
    this content is otherwise lawful." AQG stores only the backend user ID and
    the time, per site and for the active provider. Without it AI stays off for
    the site (:guilabel:`AI cannot be activated for this site`). Switching the
    provider clears it. AQG cannot check whether a DPA exists or how a provider
    account is configured; the checkbox records a human statement, it is not a
    compliance proof.

..  _configuration-ai-usage:

Usage
=====

Every provider request is recorded in :sql:`tx_a11y_ai_usage` with the site,
the backend user ID, the feature, provider, model, a safe outcome code, the
token counts the provider reported and the estimated cost. No prompt, image,
answer or key is stored. The tab lists this calendar month's requests, tokens
and estimated cost per feature. Cost is shown to administrators only; editors'
:guilabel:`What was sent?` shows tokens.

..  figure:: /Images/settings-ai-usage.png
    :alt: AI usage this month: 26 requests, input, cached and output tokens,
          an estimated cost of about $0.05, the monthly budget bar with the
          warning marker at 80 %, and a table by feature with provider and
          model, requests, failed requests, tokens and estimated cost.

    AI usage of the current month per feature, with the budget. Cost is always
    an estimate; provider invoices can differ.

..  _configuration-ai-cost:

Model prices, estimated cost and the monthly budget
===================================================

Administrators never enter prices. AQG prices every model it supports
automatically and keeps those prices current; the :guilabel:`Model prices`
card on the :guilabel:`AI suggestions` tab shows them read only.

Automatic model prices
    AQG ships a reviewed price list for every supported OpenAI and Anthropic
    model, so a new installation estimates cost and enforces a budget without
    any setup. Current prices come from the AQG service: AQG reads the
    official, public pricing pages of OpenAI and Anthropic once, centrally,
    and publishes a versioned, verified price catalogue. Each installation
    only downloads that catalogue
    (``GET https://api.priebera.sk/ai/pricing/v1/catalogue``) and keeps a local
    copy. Prices cover ordinary input, cached input (cache reads), cache writes
    at the default and the longer cache lifetime, output, and any prompt-length
    tier (for example Claude Haiku 5.5 above 100,000 prompt tokens). They are
    stored as integer micro-dollars per 1M tokens, never as floats, with their
    source and verification time.

    The installation runs no price job and has nothing to configure: no
    Scheduler task, console command, button or setting. The request sends no
    key, licence, installation, site, user or usage data.

How the local copy is kept current
    When AQG needs a price (an AI request, the budget, the :guilabel:`AI suggestions` tab)
    and the local copy is more than six hours old, that one request asks the
    AQG service, conditionally (``If-None-Match``), with a 3-second timeout.
    Only one request at a time refreshes (a database claim); others use the
    copy they have. A failed refresh is retried an hour later, and a service
    that does not offer the catalogue yet (HTTP 404) is asked again the next
    day. Editors' AI requests never wait longer than that timeout.

Safe updates
    The installation accepts a catalogue only as a whole: the contract
    (``aqg.ai-price-catalogue.v1``, USD, micro-dollars per 1M tokens) must
    match, every rate must be a positive integer that fits the input price, a
    prompt-length tier must lie above the standard rates, and no model may be
    listed twice or verified in the future. Otherwise the local copy stays as
    it is. A catalogue with a lower version than the copy is refused, and a
    price is never replaced by one that was verified earlier — neither by the
    catalogue nor by the shipped list. A model the catalogue no longer lists
    keeps its last price and is marked "not listed any more". The copy is
    written in one transaction. AQG never guesses a price, derives one from a
    similarly named model or treats a missing price as zero. Dated snapshots
    (``gpt-5.4-mini-2026-03-17``, ``claude-haiku-4-5-20251001``) are priced
    only through an explicit, reviewed mapping to the id the pricing page uses.

    :guilabel:`Pricing details` shows where the prices come from (catalogue
    version or the shipped list), the last update and whether the AQG service
    was reached. When it is not, the last verified prices — or the shipped
    list — stay in use and AQG tries again on its own; a hard-stop budget
    keeps working with them.

Estimated cost
    Each request is settled with the provider-reported usage, each category at
    its own rate: ordinary input, cache reads, cache writes and output
    (reasoning and thinking tokens count as output). OpenAI's Responses API
    reports cached and cache-written tokens inside ``input_tokens``; AQG
    separates them. Anthropic reports them on top of ``input_tokens``. When a
    request's prompt exceeds a model's prompt-length threshold, the whole
    request is billed at the long-context rates. The price used is the one
    valid when the request started; a later price change never reprices
    recorded usage, and each usage row keeps where its price came from. When
    the provider reports no usage, AQG records the request's worst-case
    estimate (see below), because it cannot prove the request was not billed;
    a definitive rejection before processing (key rejected, rate limited,
    model unavailable or not permitted, quota or billing problem, malformed
    request) costs nothing. Provider invoices can differ (discounts, taxes,
    rounding); AQG always shows cost as an estimate.

Prompt caching
    AQG sends no explicit cache markers. Its instructions and output schemas
    are a few hundred tokens, below the providers' minimum cacheable prompt
    length (1,024 tokens and more), so explicit caching could not produce hits.
    Cache reads and writes the provider reports (OpenAI caches automatically)
    are counted and priced; AQG never assumes a cache hit.

Monthly budget
    Per site, in USD (at least $1), for the calendar month in server time. An
    optional warning threshold shows a warning in the usage section. When a
    budget is set, the administrator must choose explicitly between
    :guilabel:`Pause AI for editors when the budget is reached` (hard stop) and
    :guilabel:`Warn only`; AQG preselects neither. No price setup is needed
    first. Requests in progress finish, and connection tests stay possible
    (they are recorded but never blocked).

Budget alert e-mail
    When a site has a budget and a warning threshold, AQG sends one e-mail as
    soon as the recorded estimated cost of the month reaches the threshold
    (the cost stored on this month's usage rows; reservations of requests
    still running are not counted). The check runs right after each AI
    request's usage is recorded; there is no scheduler task.

    Choose a warning threshold below 100%. With the hard stop, AQG refuses a
    request whose worst-case cost would exceed the budget, so the recorded
    cost usually stays below 100% and a 100% alert may never be sent. AQG
    does not change an existing threshold; the help text under
    :guilabel:`Warn at` recommends a lower value.

    Recipient: the address in :guilabel:`Budget alert e-mail` (administrators
    only, one valid address). Without one, AQG uses TYPO3's
    ``$GLOBALS['TYPO3_CONF_VARS']['BE']['warning_email_addr']`` if it holds a
    valid address. Without either, no e-mail is sent. The :guilabel:`Limits`
    section shows the effective recipient. AQG never mails backend users.

    One alert per site, month (server time), threshold and budget. Changing
    the threshold or the budget starts a new alert: if usage is already above
    the new threshold, one e-mail follows at the next AI request. Switching
    back to a threshold and budget already alerted this month sends nothing.
    A failed delivery is retried at later AI requests (after 5, 10, 20 and
    40 minutes, 5 attempts in all) and never fails the AI request. Without a
    recipient nothing is recorded, so an address added later in the month
    still receives the alert.

    The e-mail names the site, threshold, budget and estimated usage in USD,
    states how many requests of the month have an unknown cost (the real
    spending may be higher) and links to :guilabel:`Accessibility > Settings > AI suggestions` when an
    absolute backend URL is known. It contains no keys, prompts, content or
    editor names. Its language is the backend language of the user whose
    request crossed the threshold (English or German), English without one.

Enforcement
    Before each provider request AQG locks the site's month
    (``SELECT … FOR UPDATE`` on MySQL, MariaDB and PostgreSQL; SQLite
    serialises writers itself) and adds the recorded cost of the month, the
    reservations of requests still running and this request's worst case. When
    the sum would exceed a hard-stop budget, the request is refused with
    ``budget_reached`` before anything is sent; otherwise the worst case is
    reserved and replaced by the actual cost when the answer arrives. A request
    that crashes leaves its reservation counted for the month.

    The worst case counts every byte of text AQG sends (instructions, context,
    output schema) as one token, adds a fixed envelope of 1,024 tokens, 6,400
    tokens per image, and the output cap AQG sends to the provider (for
    Anthropic including the thinking headroom). It prices every input token at
    the highest input rate a request can incur (an automatic cache write at the
    default lifetime) and applies the prompt-length tier the input could reach.
    The displayed price, the reservation, the settlement and the monthly sums
    all use the same price resolver.

    Limits of the estimate: a request still running when a new month starts is
    recorded in the new month without having been reserved there.

Model without a complete price
    Every supported model has an automatic price. A model without one (an id
    AQG does not know) or with only a price entered before AQG 3.0 (input and
    output, no cache or prompt-length rates) cannot be bounded: its cost reads
    "Unknown" (or is estimated with the old price), a warning-only budget never
    blocks, and a hard-stop budget keeps AI paused for editors with
    ``budget_unenforceable``, so spending never runs unbounded.

Prices entered before AQG 3.0
    Prices administrators entered in a 3.0 release candidate stay stored
    unchanged and are listed under :guilabel:`Pricing details`. An automatic
    price always replaces them; they are only used for a model without an
    automatic price, and never to enforce a hard-stop budget.

..  important::
    Sending content to an AI provider is a transfer of data to a third-party processor.
    Check your own data protection requirements before enabling the feature, and
    do not enable it for sites whose content must not leave your infrastructure.
    See :ref:`privacy-security`.
