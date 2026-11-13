# Changelog

## [3.0.0] - Unreleased (release candidate)

AQG 3.0.0 introduces Atlas, AQG's AI-assisted remediation workflows — fixes in the editor, for image metadata and in a reviewable remediation queue — with per-site provider, privacy, budget and data processing controls, and keeps every change in the editor's hands: AQG prepares suggestions, TYPO3 Save stores them. Atlas adds these workflows and the screens they need (AI settings, AI fixes); it is not a redesign of every backend screen, and the existing views keep their 2.x design. Frontend scans of pages visitors cannot open become separate preview scans. Scanning, findings, the Quality Gate, history and reporting work as in 2.1.

> **AI is optional and off until an administrator sets it up.** Without a provider key, a verified model and the data processing acknowledgement for a site, AQG sends nothing to an AI provider and editors see no AI actions; every non-AI feature works unchanged.

**Scope of 3.0.0 within the Atlas design:** 3.0.0 ships the AI foundation with Claude, Smart Editor AI, AI image metadata and the remediation workflow (Atlas packages 1–4), the preview scans of hidden pages (package 6) and Ask AQG in the toolbar. It also contains the agent access connector (MCP, package 5 v1 plus Accessibility Intelligence: settings tab, consent page, "My connected agents", ten read and page-scan tools), **off by default and not publicly available**: it works only with the hosted AQG MCP Gateway, which AQG has not opened yet, and nobody may connect agents until an administrator grants it. Not part of 3.0.0: public agent access, MCP site crawls, suggestion preparation and applying changes by agents (open decision D10), MCP approval and the AI fixes "Agent activity" tab, the advanced accessibility and agency features (package 7: forms, media and documents, agency reporting), the combined metadata review dialog (3.0 suggests metadata per field in TYPO3's metadata form; Filelist bulk and upload flows that would save metadata outside the form need a write path and are not planned for 3.0.0), AI suggestions for frontend scan rules, and in AI settings model capability columns, a per-person daily request limit and an all-sites overview.

### Added

* Anthropic Claude as a second AI provider next to OpenAI. Each TYPO3 site uses exactly one provider and one model; the other provider's encrypted key and model choice stay stored when you switch. Claude models are discovered from the Anthropic account, filtered by AQG's model registry and Anthropic's reported capabilities (images, structured outputs), and verified with the same connection contract as OpenAI. AQG never selects a fallback model.
* AI settings: site selector, readiness list (licence, provider, key, verified model, processing acknowledgement, budget) computed by the same server-side policy that decides whether editors get AI, provider choice with confirmation, a connection test that must pass again before a previously used model is reused after a provider switch, and a description of what the connection test sends.
* AI privacy switches per site: allow images, allow page titles, with a table of what each feature sends.
* AI usage per site and month: requests and provider-reported tokens per feature, provider and model, with cache reads and cache writes counted separately (`tx_a11y_ai_usage`). No prompt, image, answer or key is stored. Each request also stores its estimated cost — every usage category at its own rate, at the prompt-length tier the request reached — computed with the price valid when it started, and where that price came from; recorded cost is never repriced. Cost is shown to administrators only, always labelled as an estimate, and reads "Unknown" for a model without a price; Accessibility › Settings › AI suggestions › Usage & limits shows administrators every backend user with usage in a month they choose (current month and the 12 before it): requests, failed and pending attempts, input/output tokens and estimated cost per person, with the current name, username and id (deleted and disabled accounts keep their history), the site total in the same table, and a closed breakdown by feature and model. Estimated spend counts settled requests only; a pending attempt is shown separately as reserved worst case, a request rejected before processing as $0 by rule, a failure after sending at its worst case, and a model without a price as unknown. Amounts are USD. "Export CSV" downloads the same month and site by backend user (administrators only; exact amounts with six decimals, the site total as last row, spreadsheet formulas neutralised, UTF-8). When rows rounded to the cent add up differently from the site total, the report says so with both figures. Dates and amounts follow the backend language.
* Automatic AI model prices: no administrator ever enters a price, and no installation runs a price job. AQG ships a reviewed price list for every supported OpenAI and Anthropic model, and the AQG service reads the providers' official pricing pages once, centrally, and publishes a versioned, verified price catalogue (`GET /ai/pricing/v1/catalogue`: input, cached input, cache writes, output and prompt-length tiers, in integer micro-dollars). Each installation keeps a local copy, refreshed when a price is needed and the copy is older than six hours: conditional request, 3-second timeout, one refresh at a time (database claim), one transaction, retried after an hour on failure and after a day when the service does not offer the catalogue yet. A catalogue is accepted only as a whole and only if it is valid; an older catalogue version or an earlier-verified price never replaces a newer one, and a model the catalogue drops keeps its last price. Until the service is reached, the shipped list prices every model, budgets included. No Scheduler task, console command or settings control; nothing but the request itself is sent. Never a guessed, derived or zero price (`tx_a11y_ai_model_price`, `tx_a11y_ai_price_catalogue`).
* Monthly AI budget per site (administrators): an amount, an optional warning threshold and an explicit choice between "Pause AI for editors when the budget is reached" and "Warn only" (nothing is preselected); no price setup is needed. With a hard stop, AQG reserves each request's worst-case cost (every input token at the highest rate it can incur, at the prompt-length tier it can reach) under a database lock before the request is sent and refuses requests that would exceed the budget; connection tests stay possible. A model without a complete price cannot be bounded: a hard stop then keeps AI paused for editors (`budget_unenforceable`) (`tx_a11y_ai_budget_lock`, `tx_a11y_ai_budget_reservation`).
* Budget alert e-mail: when a site's recorded, estimated AI spending of the month reaches its warning threshold, AQG sends one e-mail (English or German) to the address set in the site's AI budget settings, or else to TYPO3's `[BE][warning_email_addr]`; without a recipient nothing is sent. The mail names the site, month, threshold, budget and estimated usage, states how many requests have an unknown cost, and links to Accessibility › Settings › AI suggestions. It is sent at most once per site, month, threshold and budget (`tx_a11y_ai_budget_alert`, safe under concurrent requests), retried after a failed send on later AI requests, and never fails an AI request (it is sent during the request that crosses the threshold, so a slow mail server delays that one request). No Scheduler task is needed. Keep the warning threshold below 100%: with the hard stop, AI pauses before the recorded spending reaches the budget, so a 100% alert may never be sent; existing thresholds are not changed.
* Data processing acknowledgement per site and provider (administrators): AI requests with site content are sent only after an administrator confirms that a data processing agreement with the active provider covers the site, or that sending the content is otherwise lawful. AQG stores who confirmed it and when; switching the provider clears it.
* Smart Editor AI in CKEditor 5 and the plain HTML editor: a wand on AQG's existing marker offers Fix with AI and Explain for link text, image alt text, button names, iframe titles and empty headings, plus a rule-based heading level fix without AI. Each suggestion shows Before/After (a source diff with range checks in plain HTML), an editable value, the model and "What was sent?". Apply is one editor change (one Ctrl+Z); TYPO3 Save stores it, and AQG checks the saved record before it says "Resolved and verified". Review fixes (n) handles all fixes of a field in one dialog, F8 / Shift+F8 step through the findings, and optional writing help (clarity, plain language, translation drafts) is available with `options.a11y_quality_gate.ai.writing = 1`.
* Live checks identify the exact element of each AI-eligible finding (tag, position among elements of that tag, attributes, text). Editors prove they found that element before requesting or applying a change, so identical duplicates stay apart and edited elements make a suggestion outdated instead of overwriting the edit.
* User TSconfig `options.a11y_quality_gate.ai.editor` (default on) and `options.a11y_quality_gate.ai.writing` (default off).
* AI image metadata (Package 3): the Filelist context menu and list-view menu offer "Suggest metadata with AI" for images the user may edit. It opens TYPO3's metadata form of the file, where a wand beside alternative text, title and description suggests a value per field. Apply only fills the form field and TYPO3 Save stores it; AQG has no write path into `sys_file_metadata`. For a selection of several files AQG says that metadata is applied per file, and after an upload one notification links each new image without alternative text to its metadata form (no AI request until the editor asks in the form). Text-only translation of metadata, SVG labels read without AI, and a manual-review badge for diagrams and charts. In content elements, a wand beside each image's alternative text suggests text for this use only, each image shows its alternative-text state in its header (missing, own, inherited, decorative) and "Decorative image" sits directly under the alternative text; Apply fills the form field, TYPO3 Save stores it on that reference, and AQG's structured image checks verify the saved record.
* User TSconfig `options.a11y_quality_gate.ai.images` (default on) and `options.a11y_quality_gate.ai.bulk` (default off; used by the remediation queue, no effect in the Filelist).
* AI remediation workflow (Package 4): a new Accessibility › AI fixes view collects prepared changes per site with a sub-navigation (Overview · AI fixes), tabs All open · My queue · Done · Change log, a search and filters (status, owner, site, source, severity; a page from Page Detail appears as a removable filter), a review drawer with the complete Before/After, an editable value, provenance and history, and a bulk review that approves only changes whose diff was opened. Image texts of one image use are saved through AQG's existing allow-listed DataHandler path after a check that the target did not change; rich text and Plain HTML open in their editor with the approved change staged, and the person applies it (one undo step) and saves with TYPO3 Save. Every item ends as Saved, Verified (AQG's check of the stored record) or Not verified with the reason — conflicts, failures, rejections and findings that no longer apply are shown as such. Page Detail gains a remediation summary, a "Fix with AI" entry per eligible occurrence, an owner per issue type (Editor, Developer, Mixed, Unassigned; changes are logged) and a page remediation plan built from stored findings without AI (a side panel grouped by owner, with "Go to issue", "Copy as ticket" and, with the queue permission, "Send editor steps to queue"); the Page module indicator says who fixes what and how many suggestions wait; the Overview shows waiting, saved and verified counts. Approvals and writes are server-side compare-and-set steps (no duplicate application), need the exact displayed version, the queue permission (`options.a11y_quality_gate.ai.bulk`) or one's own request, record and field edit access and the same workspace; agent-originated items cannot be approved. New tables `tx_a11y_remediation_item`, `tx_a11y_remediation_event`, `tx_a11y_remediation_owner`. An item keeps the target's own value before and after and the AI's short reason; no prompt, image or other page content, and the history keeps codes and value hashes only. In a workspace, image texts are applied in the record form instead of by AQG.
* Overview › What to fix first: an Owner tag per frontend rule (Editor, Developer, Mixed, or "Varies by page" when assignments differ), shown only for real assignments the viewer may read. Users with PRO or Agency who can edit the site root assign it in the row's Details; the owner route now also checks the licence and accepts only rule ids with a stored finding (content) or found by a stored paid frontend scan of that site (site root).
* AI fixes › Change log: the remediation events of the items you may see, filtered by period, person, action and source; values appear only as the stored hashes. A conflict in the review drawer shows the original value, the value saved now and the suggestion side by side.
* Paid frontend scans of hidden pages are stored as preview scans: labelled "Preview scan · page not public" on the page details, its findings and exports, listed in their own history, and never compared, paired or used for Verify fix with public scans. Site-level views, totals and monitoring count public scans only. The Scanner Token opens hidden pages only, so a page scan of a page outside its start/stop time or limited to frontend user groups is refused with an explanation before anything is sent to the crawler. Page Detail of a hidden page offers "Scan unpublished page" when the site has a Scanner Token and says what is missing otherwise; the Page module marks such a page "Not public".
* Toolbar › Ask AQG: the AQG toolbar item opens with Ask AQG for the page, content element or file you are working on — its context and language, a question field and, only after you ask, the answer. No findings are listed when it opens. Common questions (what to fix first, why it matters, where to fix it, for this page or, with access to the site root, the website) are answered from your stored AQG findings and guidance without AI and at no cost, also without a licence. With AI set up for you, other questions make one structured request through the site's own provider key, AI budget, usage recording and privacy settings; answers cite findings with Details and Edit links and never change content. The conversation is kept in your browser only, per backend user, login and page/site/language context: another user or a new login in the same tab never sees it, and a page without its own conversation starts empty (the last 3 AI questions are sent back with a new one, and the server accepts only answers it signed for this conversation, user, login and context; AQG stores no question or answer). Server-enforced limits: 400 characters per question, one provider request and 45 seconds per question, 10 AI questions and $0.05 per conversation, 60 AI questions per user, site and day, one AI question per user at a time. Every AI request is recorded as a usage row before it is sent and counts against these limits even if recording its result fails; if that row cannot be written, nothing is sent. The answers use only findings you may read now; every question checks your access again.
* Toolbar: Content scan and Frontend scan are collapsed sections whose header shows a summary ("2 new · 300 open") and the scan's status. Opened, the Content scan section shows its details and progress and offers Check saved page now for the open page to users who may scan it (Scan now and edit access, checked again by the scan route), with a visible result or error; the Frontend scan section shows its details and progress. The footer has two equal buttons, Open Accessibility and Open MCP. Open MCP opens "My connected agents" and appears only when agent access is usable for you (`options.a11y_quality_gate.mcp.connect`, access to the Accessibility module, a connected AQG MCP Gateway); in 3.0.0 agent access is not publicly available, so it is normally not shown.
* Page Detail › AI actions: Explain with AI and Fix with AI show separate results. An explanation opens in its own panel ("What the problem is / How to fix it", "Nothing was changed.") and is toggled by its button. Fix with AI reports exactly one outcome: suggestion ready for review (with Review suggestion, which opens that item in AI fixes), already in AI fixes (with the item's real status), no usable suggestion, AI request failed (with Try again for the same item), or not possible here (with the reason). Each result says whether a request reached the AI provider and its recorded cost. Images are checked before anything is queued or sent: a missing file, an external URL, a file outside every storage, a file that is not an image, a file you may not read or an unreadable file gets its own reason, no AI fixes item and no AI request. AI actions are offered only for fields you may edit in the form. Buttons announce progress, focus moves to the result, and the result works in dark mode and narrow content areas.
* AI fixes accepts a link to one item (`?item=<uid>`) and opens its review drawer; the item's own visibility check still applies.
* Agent access (MCP), off by default and not publicly available in 3.0.0: an outbound connector (`vendor/bin/typo3 a11y:mcp:connector`) to the hosted AQG MCP Gateway, a settings tab per site, a consent page with "My connected agents" and ten tools: `aqg_whoami`, `aqg_list_sites`, `aqg_list_scans`, `aqg_get_scan_results`, `aqg_start_scan` (one page), `aqg_get_scan_status`, and the read-only Accessibility Intelligence tools `aqg_find_pages`, `aqg_list_content_findings`, `aqg_get_finding_context` and `aqg_get_remediation_guidance`. Scopes: `findings:read` and `history:read` (the only ones ticked in advance), `scans:run-page` and `content:read` — local content findings with snippets of records the user may read, which can include hidden or scheduled content, so it is never ticked in advance and never granted to a request without scopes. The consent page shows which agent asks (named from the address it returns to, never a verified identity), the site, the scopes and the duration (7, 30 or 90 days); every decision is a POST of the signed-in user. Every agent acts as one TYPO3 backend user with that user's rights at call time; no tool changes content. Nobody may connect agents until an administrator grants `options.a11y_quality_gate.mcp.connect`, turns a site on and pairs the installation; the Gateway refuses pairing until AQG opens it (it needs the AQG service's Gateway release, API migration 097). New tables `tx_a11y_mcp_site`, `tx_a11y_mcp_audit`, `tx_a11y_mcp_scan_request`, `tx_a11y_mcp_consent`. See Developer › Agent access.
* Licence card in a multi-site installation: Settings › Licence asks which site the licence card, Validate, Revalidate and the domain list are for (a site picker, offering only sites with a host the user may see) instead of validating a default or empty domain, which the AQG service refused and the card showed as an inactive licence. A single site is chosen automatically.
* Shared AI task foundation for the following packages: typed structured requests and responses, a strict structured-output contract, provider-independent execution with usage recording, and suggestion envelopes that detect a changed target.

### Changed

* Test connection and Refresh models share the AI rate limit (10 requests per minute per user and site).
* `AQG_OPENAI_API_KEY` applies only to sites whose provider is OpenAI.
* Editors get AI only while the site's selected model passes the connection test for the current key, model, prompt and contract; a failed or outdated verification pauses AI for editors instead of only showing "Not verified" in the settings.
* Smart Editor AI shows the result of an applied change inside the existing summary bar and on the marker (dashed while unsaved) instead of an extra row, and says "Saved" only after AQG has confirmed that TYPO3 stored the record; a change that was not saved is reported as not saved.
* Upgrading: AI pauses for editors on every site until an administrator confirms the data processing acknowledgement in Accessibility › Settings › AI suggestions, and sites whose last connection test does not match the current key and model need one more Test connection. Run `vendor/bin/typo3 extension:setup` for the new tables and columns.
* PDF export is Composer-only. The TER package for Classic mode does not include the mPDF library, and AQG no longer suggests providing it there. In a Classic mode installation the export menus, the acceptance evidence and the Statement Assistant offer CSV, HTML or text and say that PDF export needs a Composer installation, instead of a PDF link that downloads a notice; every other feature works in Classic mode as before. Composer installations keep PDF export unchanged.
* Accessibility › Settings › AI suggestions is one page in four groups — Provider & model, Privacy & editor features, Usage & limits, Diagnostics & model prices — with a compact section navigation (anchor links that stay in reach while scrolling, each with its current state). The data-sent matrix, the connection facts of a verified connection and the feature/model usage table are closed disclosures; the acknowledgement, the budget form and failure states stay visible. The navigation states follow every server answer without a reload, and the usage report says when the server has recorded requests since the page was loaded.

### Security

* Scans accept only a default-language page uid plus a language: frontend single-page scans (MCP `aqg_start_scan`, "Scan this page", Free Remote Preview) and content scans ("Scan this page", "Scan site"). The uid of a page translation record was accepted as language 0: an editor without that language could scan the translation, and the result was stored as a default-language scan. Such a uid is now refused in every language before any reservation, scan or crawler request, also for administrators, and frontend scans stored that way earlier are readable only under the translation's real language and record permissions. Content "Scan site" now applies the same language policy as "Scan this page" (a language the user may not use is refused). The Page module panel shows nothing for a translation record's uid (read as language 0 it showed "No issues found" and the translation's scan time). A frontend export refuses a translation record's uid as its page, and a frontend export that names no scan refuses a concrete language the user may not use.
* Single-page scans check the user's current rights on the target before anything else, including a page's current live translation (SOL P1-01/P1-02). Before, `aqg_start_scan` answered a repeated `idempotencyKey` with `replayed`, the earlier job ID and site before checking the language, so a user whose language or translation access had been removed still learned the job ID; and every single-page submit path (`aqg_start_scan`, Scan this page, Verify fix, Free Remote Preview) checked the base page and the language but not the translation record, so a translation the user may not read or edit was sent to the crawler under its translated URL. Now the page (show and edit), the language and — for a translated language — the page's current live translation (show and edit, its own page permissions) are checked on every call, before an idempotency key is looked up or reserved and before any crawler request; a refusal is the same `permission_denied` for a repeated and a new key and names no job, URL or translation, and a replay names its job only while the user may read that scan. A scan of a stored result URL (Scan this page from a result, Verify fix) is also admitted by the page that URL belongs to and its translation, in the URL's own language, not only by the page the result is mapped to (the site root for a result without mapped elements); a stored URL that belongs to no TYPO3 page (an extension route, an old slug) is re-scanned by administrators only. Every live translation row counts if a page has duplicates. The translated URL is taken from the live translation only (a workspace version's slug is no longer used as the frontend URL). The default language, readable translations, pages without a translation and administrators are unaffected; revocation applies on the next call without a new token.
* Stored frontend results of a translated page follow that page's current translation. Before, a result recorded with a translation that was later moved, re-parented or replaced stayed readable through the recorded translation record, even when the page's current translation was refused to the user — in `aqg_get_scan_results` and in the module's Overview, page detail, CSV/PDF exports, toolbar and scan notification (IA-MCP-01). Now the current live translation must be readable, and a recorded translation that is no longer a live translation of that page in that language withholds the result (administrators still read it). Results of the default language and of unchanged readable translations are unaffected.
* Language restrictions (`allowed_languages`) apply to stored results in the default language too. Before, a user allowed only a translation still read default-language frontend results: remote CSV/PDF exports and the Overview, `aqg_get_scan_results` and `aqg_list_scans` (job, URL and counted in `total`). Now every concrete language — 0 included — needs the user's language access for a scan, its result pages, its mapped records and the agent history candidates; a scan in a forbidden language is refused when it is submitted and answers like an unknown job. A mapped element of a translated result page that TYPO3 rendered from a connected translation is decided by that translation. A crawl of all languages stays readable by site readers and shows only result pages in the user's languages; a legacy page scan without a recorded language is shown only to users without a language restriction. Local page findings follow the page record's current language: a finding whose source page row is now a translation needs that translation's language, and a finding whose stored language no longer matches its translated source row is withheld; rendered-HTML findings of a default-language page in another language keep working, other findings of a default-language page row stored with another language are withheld.
* Rules, scan fields and the rendered-check settings (including "Allow private/local frontend hosts") apply to every site, so only TYPO3 administrators can change them now, on the server. Before, any user with access to the Accessibility module and the default `showSettings = 1` could save them, including forged requests. For other users the Rules and Scan fields tabs are read-only; `showSettings` only hides the Settings view.
* The Overview's page list, its pagination, language counts and totals, the open totals in a site scan's notification and the Content scan site export (CSV, PDF) leave out pages the user may not show (TYPO3 page permissions). They listed the titles, counts and findings of every page of the site. Ignoring a rule on the whole site changes only findings on pages whose content the user may edit.
* A content site or subtree scan started by a backend user processes only the pages that user may edit; other pages keep their stored findings. Administrators, the CLI and the Scheduler scan the whole tree.
* Ignore from CKEditor and the plain HTML editor honours `options.a11y_quality_gate.editRecord = 0` like Ignore in the page details.
* Preparing or reopening an AI fixes item needs edit access to the record field it fixes. A user who could only read the page could create an item that held the finding for everyone else.
* Image fixes also check the content element's own image field (relevant when it is an exclude field).
* A frontend URL maps to the TYPO3 page of its own site: the same slug in another site no longer stands in for it, and hidden live pages map too (page links of frontend results, exports).
* Fields that TYPO3 renders read-only (TCA or TCEFORM `readOnly`) in CKEditor and the plain HTML editor no longer get AQG actions that change content (Fix with AI, rule-based fixes, Review fixes, writing help), and no AQG apply path writes into them. Before, an applied change was kept by TYPO3 Save, because TYPO3 submits read-only RTE and code editor fields and does not enforce `readOnly` when saving. The accessibility findings and their "How to fix" guidance stay available. The server-side write check of every AQG writer (image alternative text and decorative flag, the AI fixes queue, editor AI) applies the field configuration TYPO3 shows — TCA, the record type's `columnsOverrides`, TCEFORM page and user TSconfig (`readOnly`, `disabled`, per type), the type's form layout (`showitem` and palettes), display conditions (`displayCond`, evaluated by TYPO3's own FormEngine pipeline against the current record values, for an image reference as the opened inline child of its image field; a condition TYPO3 cannot evaluate refuses the write), translations of `l10n_mode = exclude` fields, the image field of the content element and its `overrideChildTca` (also its types and palettes) — and the current workspace, for administrators too; a refused write changes nothing. Keeping "Decorative image" and the alternative text consistent no longer changes the other field when TYPO3 shows it read-only or not at all.
* Stored content findings follow the current rights on their source record: the Overview, its counts and page list, Page Detail, CSV and PDF exports (also of one page), the toolbar, the Page module, scan notifications and the AI fixes entry points show and count a finding only while its record exists, is live and lies in a table, language and page the user may read (a page translation by its own permissions). The same applies to the live feedback and Ignore in CKEditor and the plain HTML editor, to the items listed in AI fixes and to the image findings of a file in the toolbar. Before, a finding stayed visible through the page it was stored with after the record moved to a refused page or the user lost access to its table. Findings without a known source are administrators only; the Quality Gate still counts every finding.
* Frontend results follow the page and record they were recorded for: AQG stores the TYPO3 page of every result page when it stores a scan (`tx_a11y_remote_scan_page.owner_*`) and checks the user's current page, language, translation, table and record rights on every read — Overview, page details, CSV and PDF, toolbar, notifications, Verify fix, the Page module and agent access. Elements without a record mapping, results stored before 3.0 or without an established page, page screenshots, crawler comparisons and crawler-wide summaries are administrators only. Such restricted views say "Results limited to your access" instead of reporting a clean scan or a scan that checked no page, without counting what was withheld.
* A content scan of one page (Check saved page now in the toolbar, Scan again in the Page module panel, the page details) scans only a language the user may use, the default language included; all languages at once only for users whose languages are not restricted. The scan route decides this itself, before a scan starts, and answers a refused language like a page the user may not edit.
* Ask AQG in the toolbar refuses records of another workspace, page translations and languages the user may not use, and image findings in a language or table the user may not read.
* Content scans check the actual translation record (SOL AUTH-01). "Scan this page" in a language checked the default page and the user's language but not the page's translation, so an editor could scan, and store findings for, a translation they may neither show nor edit. A Content scan of a language now needs show and edit access to every live translation row of the page in that language, as a frontend or MCP scan does; "All languages" needs it for every translation of the page. A site/subtree scan checks only the pages the user may scan in the requested language, reports the others as not checked in its notification and stores them on the scan run, so they are never counted as covered; a subtree without any such page is refused. A refused request changes no lock, status, scan, finding or source state. The site-wide retirement of findings of deleted records runs only for scans without a page restriction (administrators, CLI, Scheduler).
* The Content scan status, its 409 "already running" answer, cancellation and the toolbar follow the page, its translation and the language of the running or last scan (SOL AUTH-02). Users who may not read them see only that a scan is running; a site scan's counters are shown to administrators and the user who started it, and its warnings only for pages the viewer may read. Cancelling a scan needs the right to start that same scan.
* Frontend CSV and PDF exports resolve one report and authorize exactly that scan: site, scope, paid scan, language and the user's current rights (SOL AUTH-03). A named scan that does not fit (not completed, another site or page, a Free Remote Preview result) or that the user may not read is refused and no longer replaced by another scan; an export without a named scan is refused when the scan it would use is one the user may not read.

### Fixed

* Agent access (MCP), right after pairing: the tab no longer reloads into the red "Connector offline" alert while the connector simply has not reported yet. A fresh connection without a first heartbeat (or still awaiting its activation) shows "Waiting for the connector", updates by itself once the connector reports, and becomes "Connector offline" only after 15 minutes. Within those 15 minutes a connection still awaiting its activation is not shown as "No longer accepted" (the Gateway refuses connector calls until activation); the tab completes the activation itself, so a real refusal shows within seconds.
* Agent access (MCP) › "Open AQG account" is shown only for an absolute web address from the AQG Gateway (it names the AQG account page of its environment).
* Agent access (MCP) › Audit trail: paged in the database, 25 entries per page, newest first with a stable order for entries of the same second, previous/next and page links that keep the filter, and "Entries x–y of z". The 90-day retention and the "Refused" filter apply to the count and the rows alike; a deep page reads only the entries it shows. Pairing is recorded by the poll that completes it, no longer on every status poll.
* Agent access (MCP) › connector: agent calls run in parallel in worker processes (`--workers`, 1–8, default 4; `a11y:mcp:connector:worker`, started by the connector). Each poll asks the Gateway only for as many calls as workers are idle, so one slow call no longer holds the others back until the agents stop waiting; a call that cannot start in time is answered `installation_busy` (nothing ran, the scan quota is not used). Workers act under the connector's own identity and run every call through the same Gateway begin check, consent check and TYPO3 permission checks; `--workers=1` or a host without `proc_open` runs calls one at a time as before.
* Agent access (MCP): results are sent as UTF-8 (a page in Cyrillic, Greek or CJK no longer takes six bytes per character), and an answer larger than the Gateway relays is answered `result_too_large` with the advice to request a smaller page, instead of a timeout. Connected agents are read from the Gateway page by page, active ones first, so they stay listed and revocable however many ended tokens an installation has. The consent page says to deny a connection you did not start yourself. A consent submitted without a duration is 30 days (it was sent as 0 days and refused).
* Toolbar › Ask AQG: when the AI provider rejects the site's key or model (for example a revoked key), the question is answered from the AQG findings with a note that AI is paused, instead of "This did not work". An error page a proxy or CDN puts in place of an AQG answer is reported as "The AI provider did not answer" instead of the generic message.
* Classic mode (TER) on TYPO3 14: the extension could not be activated ("depends on mpdf/mpdf which is not present in the system"). TYPO3 14 reads `require` from `composer.json` as package dependencies. The TER package now carries its own `composer.json`: the Composer manifest without the Composer-only `mpdf/mpdf` requirement, built from the same commit (`Build/Scripts/build-distributions.sh`, which records the provenance of both artefacts). AQG declares no package as provided (`providesPackages` stays empty), so it never satisfies another extension's mPDF requirement in Classic mode. Composer installations still install mPDF (PDF export); the TER package contains no mPDF. A Git checkout or Composer ZIP copied into `typo3conf/ext` is not the TER package and does not activate in Classic TYPO3 14.
* A Content scan whose rendered page check did not run — because the page could not be fetched, or because visitors cannot open it (hidden, scheduled, access-restricted) — no longer reports "No open issues." but "No open issues in the content that was checked.", next to the pages that were not checked (a warning for a failed check, information for an expected skip). When a finished frontend scan's results cannot be saved, the toolbar says so instead of "did not finish".
* While a finished frontend scan's results are being saved or could not be saved, the Overview shows the storage state, the pages loaded and the scan date only: no crawler-reported occurrences, priority fixes, "What to fix first" list, WCAG-mapped findings, reporting groups, additional signals, regression alert, scan comparison or "new" badge, and no CSV/PDF export of that scan (the export route refuses it, also as the latest scan, instead of exporting the crawler's summary or an older scan). They appear once the results are stored (also after recovery).
* The toolbar's Frontend card shows the newest frontend scan the user may read. A newer scan of a page the user may not read made it say "Not scanned yet" although an older scan of a readable page existed; the unreadable scan is still never named.

* "No issues found" means a verified result (SOL STATE-01). The Page module panel, Content Page Detail ("This page is clean"), the Content Overview, the toolbar cards and icon, the Content scan notification, the frontend Overview and toolbar used a zero of the findings the user may read as a clean result, also when results were hidden from that user, owned by another page, not stored yet, or when the scan did not cover the page or site. Now only a completed, stored scan that covered the scope, read by a user who sees every result (administrators), is shown as clean. Otherwise AQG says what it can: "No open issues in the results you can access", "Not fully checked", "Results are being saved" or "Results could not be saved" — from the user's rights and the scan's own state, never by revealing hidden results or their number. A newer result of another page no longer hides an older result of this page that the user may read, and the panel links only to frontend results the user may open. Ask AQG and Repair say that they list the issues the user can access.
* Unicode site domains (`müller.de`) are read the same on every platform and locale (SOL COMPAT-01). On macOS with PHP 8.5 under a UTF-8 locale, `parse_url()` corrupted the host, so Free Remote Preview activation, the installation proof and paid scan input failed for such sites. Hosts are now parsed without depending on the process locale; ASCII and punycode hosts are unchanged.

* Toolbar › Check saved page now: while a content scan runs (your own check of another page, or a scan started elsewhere) the button stays visible and says that only one content scan runs at a time and this page can be checked when it has finished; a click sends nothing and announces the reason. Before, the click was ignored or the button disappeared.

* The Quality Gate also decides when a workspace is published (Workspaces module: publish a record, a selection, a stage or the whole workspace; auto-publish). Publishing from a workspace used to bypass it. When a publish request makes a page visible (a page created in the workspace, or a hidden live page made visible, translations included), AQG checks the content that exactly this request puts live on that page, before anything is written, with the content scan's rules, thresholds and ignored findings, without storing results. In blocking mode with an active licence, nothing of the request is published: live records and workspace versions stay as they were, and the reason is logged for the request and shown as a message on the next backend page. In warning mode, or without a licence, the publish happens with a warning. Content published later onto a page that is already visible is not gated, as for live editing.

* Content scans read live records only. Workspace versions and records or pages created in a workspace were scanned and stored as live findings; they are no longer collected. The next scan of a live page resolves findings stored from draft content on it, and findings stored on draft or version pages no longer appear in totals, lists or exports. Saving inside a workspace runs neither content feedback nor the Quality Gate (it would judge the live page, not the draft); making a page visible there shows a note.
* Content Scan CSV and PDF exports cover the language selected in the view, on the Overview and on the page details; the PDF names it. A link with `language=-1`, or without a language, exports every language; links saved from 2.x name `language=0` and now export the default language only.
* AI hard-stop budget: an AI request could be admitted beyond the monthly budget while another request of the same site was settling (SOL-FINAL-01). The admission read the recorded cost and the open reservations with two separate queries; under READ COMMITTED (PostgreSQL's default, or MySQL/MariaDB configured that way) a settlement could commit between them, so neither the reservation nor the settled cost was counted. Both are now read by one statement while the site's monthly lock is held, and on PostgreSQL the reservation transaction always runs at READ COMMITTED (under REPEATABLE READ it could miss a reservation committed while it waited for the lock).
* Rendered check: the 5 MiB limit of a rendered page now limits the transfer itself (SOL-FINAL-02). Before, the whole response was downloaded and buffered (also a compressed response expanding far beyond the limit) and only then rejected as too large. A declared size above the limit now ends the request after the headers, and the body stops at the limit after decoding, with chunked transfer, without or with a wrong Content-Length and after a same-host redirect. Redirects, HTTP error statuses and non-HTML types are decided from the headers without reading the body. Host, port, address pinning, the redirect checks and the 8-second timeout are unchanged.

### Upgrade notes

* **Requirements:** unchanged — TYPO3 13.4 LTS or TYPO3 14.3–14.x, PHP 8.2+. `ext-sodium` is needed to store AI provider keys (as for remote-access passwords).
* **Classic mode (TER):** PDF export needs a Composer installation. Classic mode installations keep every other feature, including CSV export; to keep PDF reports, move the installation to Composer.
* **Database isolation for AI budgets:** a hard-stop budget is enforced on MySQL/MariaDB at READ COMMITTED, REPEATABLE READ (default) or SERIALIZABLE, including MariaDB's `innodb_snapshot_isolation` (default from 11.6), and on PostgreSQL (its reservation transaction is pinned to READ COMMITTED). Tested on MariaDB 10.11 and 11.8, MySQL 8.4 and PostgreSQL 16. READ UNCOMMITTED is not supported.
* **Database:** new tables `tx_a11y_ai_site_setting`, `tx_a11y_ai_usage`, `tx_a11y_ai_price` (read only: prices entered in 3.0 release candidates), `tx_a11y_ai_model_price`, `tx_a11y_ai_price_catalogue`, `tx_a11y_ai_budget_lock`, `tx_a11y_ai_budget_reservation`, `tx_a11y_ai_budget_alert`, `tx_a11y_remediation_item`, `tx_a11y_remediation_event`, `tx_a11y_remediation_owner`; `tx_a11y_mcp_site`, `tx_a11y_mcp_audit`, `tx_a11y_mcp_scan_request`, `tx_a11y_mcp_consent` (agent access; empty until it is set up); new columns `tx_a11y_remote_scan.is_page_preview`, `tx_a11y_remote_scan_page.owner_state`, `owner_page_uid`, `owner_language_uid`, `owner_translation_uid`, `tx_a11y_ai_configuration.revalidation_required` and `tx_a11y_ai_usage.conversation_ref` with the index `conversation` (Ask AQG; an opaque conversation id, no content), `tx_a11y_ai_usage.reserved_cost_micro_usd` and `budget_reservation` (the worst case of an AI request that is still being recorded). Apply them before the new code serves requests. Run "Analyze Database Structure" or `vendor/bin/typo3 extension:setup -e a11y_quality_gate` after updating; existing settings, scans and findings are kept.
* **AQG service:** scans, licences, AI and reports use the same AQG API and crawler endpoints as 2.1.0; no service change is needed for them. Agent access (MCP) is off by default and needs the hosted AQG MCP Gateway (API migration 097 and its Gateway configuration), which is not publicly available yet.
* **Frontend results stored before 3.0** have no recorded page and are shown to administrators only until the next frontend scan; run a site scan after the upgrade so editors see current results. Content findings are shown to editors only while they may read the source record.
* **Rollback:** keep a database backup from before the upgrade. 2.1 ignores the new tables and columns; disconnect a paired agent access first.
* **Image fixes for editors:** without `options.a11y_quality_gate.allowImageRemediation`, editors who may edit the content element and the `alternative` and `tx_a11y_is_decorative` fields of `sys_file_reference` can now apply reviewed image texts and mark images decorative (before, the actions needed `allowImageRemediation = 1`). Groups with an explicit `allowImageRemediation = 0` keep them off. To keep the 2.x behaviour for everyone, set `options.a11y_quality_gate.allowImageRemediation = 0` in the editors' groups. See Configuration › Permissions for editors and groups.
* **Global settings:** non-administrators can no longer save Rules, Scan fields or the rendered-check settings.
* **Content scan coverage:** new columns `tx_a11y_scan.pages_not_permitted` and `not_permitted_json` (pages an editor's site scan left out). Apply them with the other schema changes; scans stored before have none.

## [2.1.0] - 2026-10-27

AQG 2.1.0 lets administrators decide how long frontend scans stay in TYPO3 and delete older ones only after a review, makes every stored site scan browsable, explains why a cloud screenshot is missing, and adds the administrator activation that FREE Remote Preview requires.

> **FREE Remote Preview needs the AQG service:** FREE scans now start only after a TYPO3 administrator has accepted the FREE terms for the domain and the AQG service has recorded that acceptance. The AQG service decides when it publishes these terms and offers FREE. Until then, Settings › Licence says that the terms are not published yet and FREE scans stay off. Licensed frontend scans and local content scans are not affected. Once the AQG service offers the acceptance, it requires extension 2.1.0 or later for FREE Remote Preview.

### Added

* Settings › Licence › FREE Remote Preview (administrators): shows what FREE sends to the AQG service, links the FREE Terms and FREE Data Processing Terms in the version the AQG service publishes, and activates FREE per domain after an administrator ticks the declaration (unticked by default) and the AQG service confirms the acceptance. Each domain shows its status, the service's UTC acceptance time and the terms version; FREE can be switched off and on again without a new acceptance, and "Check status" asks the AQG service whether the acceptance is still valid. New terms, a revoked or expired acceptance, or a copied installation require a new acceptance. The table lists one row per domain (Site, Status, Accepted (UTC), Terms version, actions), with the acceptance details in a panel below the row.
* Settings › Scan history (administrators): keep frontend scans stored in TYPO3 indefinitely (the default) or for 1, 3 or 5 years. Saving the setting deletes nothing. Older scans are deleted only after an administrator reviews exactly what would be deleted and confirms, or with `vendor/bin/typo3 a11y:history:prune --apply` (a dry run without `--apply`). Scans that are still needed are never offered for deletion: the newest scan of each comparison, running scans, scans of a running Verify fix, and the scans that monitoring still compares with.
* Overview › Frontend scan history › "Browse all stored site scans": every completed site scan stored in TYPO3, newest first, with "View report" and "Compare with previous compatible scan".
* Frontend page details and PDF exports say why a page has no screenshot: removed after the AQG service's retention period, deleted, could not be captured, not taken, or not stored by an earlier version. Findings, counts and comparisons stay available.

### Changed

* Before FREE is activated for a site, the Overview no longer contacts the AQG service about FREE, so nothing about the site leaves TYPO3 before the terms are accepted.
* Screenshots in the backend are cached only for the backend user who loaded them, so after Switch User or another login no other user's screenshot is shown from the browser cache.

### Fixed

* FREE Remote Preview did not work for sites with an internationalised domain name (for example `müller.de`): TYPO3 keeps the domain in Unicode, the AQG service identifies it in its ASCII form (`xn--mller-kva.de`), so the installation proof never matched. AQG now uses the service's form for the domain everywhere; `müller.de`, `www.müller.de` and `xn--mller-kva.de` are one domain with one FREE activation, different domains never share one, and Settings shows the readable form.
* A frontend scan refused because the licence owner has not yet accepted the AQG data processing agreement said that the scanner did not accept the licence and asked to revalidate it. It now says that the agreement must be accepted once in the AQG customer area, links the Licence page there, and keeps the valid licence state.
* When a screenshot could not be loaded, screen readers announced the reason twice and keyboard focus jumped to the top of the page.

### Upgrade notes

* **Requirements:** unchanged — TYPO3 13.4 LTS or TYPO3 14.3–14.x, PHP 8.2+.
* **Database:** new columns `tx_a11y_remote_scan_page.screenshot_state` and `screenshot_expires_at`. Run "Analyze Database Structure" or `vendor/bin/typo3 extension:setup -e a11y_quality_gate` after updating; existing rows keep working.
* **FREE Remote Preview:** after updating, a TYPO3 administrator activates FREE once per domain in Settings › Licence, as soon as the AQG service offers it.
* **Scan history:** stored frontend scans are kept as before; nothing is deleted by the update or by saving a retention period.

## [2.0.2] - 2026-10-18

AQG 2.0.2 is a maintenance release: frontend scans say why they failed and no longer start twice, sites without a sitemap can be scanned, licence checks ride out service outages, and remediation guidance, editor feedback and PDF exports are more accurate.

### Added

* A failed frontend scan now says why: the sitemap was not found, could not be loaded, is not valid or points to another host; the site did not answer or its TLS certificate is not valid; the scan reached the scanner's time limit or was stopped; or the licence ended before the scan started. Each reason comes with the next step.
* `vendor/bin/typo3 a11y:installation:identity` shows the AQG identity fingerprint of an installation. With `--new-installation --confirm=<fingerprint>` it gives a copy of an activated database that became a separate installation (for example a new client site built from an existing one) an identity of its own, so it no longer shares the source's Agency project and scan history. Deployments, restores and domain changes keep the identity as before; the command never runs on its own and cannot be scheduled.

### Changed

* TYPO3 14 Scheduler: the `a11y:scan` console command is listed in the "Accessibility Quality Gate" category as "Content scan command (a11y:scan)", next to the Site Scan and monitoring tasks, instead of in a separate "A11y" category. Existing `a11y:scan` tasks keep running unchanged; TYPO3 13 is not affected.
* Frontend scans ask for a new scanner token when the AQG service refuses the cached one (for example after a licence change), instead of failing until the cached token expires.

### Fixed

* Frontend site scans of a site without `/sitemap.xml` always failed, which affected every new TYPO3 site without an EXT:seo sitemap. AQG now uses the sitemap of the site language and, when there is none, crawls from the start page. A sitemap that refuses access or answers with a server error is still reported as such.
* A paid frontend scan whose start the AQG service did not confirm (timeout, dropped connection or server error) could still run in the background, spend a trial scan and block a new start with "a scan is already running". AQG now checks whether the scanner accepted the scan and, when the same scan is started again within 15 minutes, picks up the accepted scan instead of starting a second one.
* Opening the Licence tab deleted the 48-hour offline copy of the licence check, so the next AQG service outage dropped a PRO or Agency installation to Free at once. The offline copy is now kept; a definitive refusal of the key still replaces it.
* While the AQG service did not answer, the Overview waited for every request to time out in turn (more than 40 seconds). A service that does not answer is now skipped for the rest of the page request.
* Saving content with findings through DataHandler on the command line (imports, deployment scripts, Scheduler tasks, `impexp`) ended in a fatal error after the records were written. The Quality Gate decision is now kept as a queued message.
* After saving several records at once, the Overview and the toolbar could name different scans as the latest one.
* A customer whose saved key the AQG service refuses was asked for "a valid Trial, PRO or Agency licence" when starting a scan, and a site scan started from an outdated view answered "Free Remote Preview request expired". Both now name the licence state and its next step, as the Licence tab does.
* With an expired TYPO3 login, a scan answered "Content scan failed: Unknown error". It now says that the login expired.
* The Statement Assistant drafted the statement from the newest site scan of any language. It now prefers the default language or an all-languages scan.
* On TYPO3 14, a Site Scan Scheduler task saved without a page or root page reported success and scanned nothing. It now fails with "Please configure either a single page UID or a root page."
* The HTTP authentication test of a site on a local or private host (DDEV, localhost, VPN) asked for a site, user name and password. It now explains that the AQG crawler only reaches public sites.
* PDF export without the mPDF library ended in a fatal error. It now explains that PDF export needs `mpdf/mpdf`; CSV export keeps working.
* The Content Overview, page view and Content PDF gave an unnamed button the advice and WCAG reference of the form-field label rule ("Add a visible label and associate it with the form field."). They now recommend visible button text or an accessible name such as `aria-label` (WCAG 4.1.2), like the CSV export and the editors.
* The accessibility statement PDF could end a page with a heading or field label whose text continued on the next page, and could add an almost empty last page. Headings and labels now stay with their text, list items and notices are not split, and the space reserved for a running header is no longer left empty. Long statements still continue over as many pages as they need.
* The CKEditor and plain HTML summaries read "5 Criticals" or "1 Critical". They now read "5 critical issues", "1 warning" or "2 need review", with matching German wording.
* "Scan again" in the Page module: when the frontend part was refused (for example for a site on a private host), the notification said "Frontend scan failed: Unknown error" and lost its "Open page report" action. It now gives the reason and keeps the action to the content report.
* Verify fix reported "(1 occurrences)" when one occurrence was left; it now reads "(1 occurrence)".

### Upgrade notes

* **Requirements:** unchanged — TYPO3 13.4 LTS or 14.3+, PHP 8.2+. No database schema, TypoScript or configuration changes; clear TYPO3 caches after the update.
* **AQG service:** frontend failure reasons and duplicate-start protection use the current AQG scanning service. Older service versions keep working with the generic failure message and without the duplicate check.

## [2.0.1] - 2026-10-13

### Changed

* The Quality Gate runs when a hidden page is made visible: when it is published or unhidden in the page tree, the list or the page properties. Saving a page that is already visible, for example after changing its title or other page properties, no longer runs the gate again, so a page that is online is not taken offline or warned about by a routine save. Hidden pages and their translations are still checked when they are made visible, in warning and blocking mode.

### Fixed

* "Show details" in the CKEditor issue popover opened an empty area, and the plain HTML editor panel did not show the rule, location and snippet of a finding. Both show their details again.
* Opening a rich text field showed the stored findings of the last content scan first and replaced them about two seconds later with the live check of the text, announced as a content change although nothing was edited (for example 4 critical findings, then "Accessibility check passed"). The editor now shows "Checking accessibility…" until the live check of the current text answers. If the live check is unavailable, it shows the findings of the last content scan, labelled as such, with Retry.
* The Statement Assistant offered sites the user cannot access, and generating a statement for them failed with "Access denied". It now offers only the sites the user can read and explains when there is none.
* Removing a Quality Gate site override or resetting the publishing rules to their defaults deleted that site's Remote Scan Access settings, including its Scanner Token, HTTP authentication and crawl lists. These settings are now kept, and the site uses the global publishing rules again.
* Saving Remote Scan Access settings or generating a Scanner Token for a site without its own publishing rules created a "Warn editors" site override. Such sites now keep using the global publishing rules.
* Rules disabled in Settings > Rules, dictionary and rendered-check settings were ignored for sites with a Quality Gate site override, so disabled rules still counted towards the gate. They now apply to every site.
* Unhiding a translated page judged it as a default-language page, so a translation with open critical issues could be published in blocking mode. The gate now checks the translation's own language and keeps the translation hidden when it fails.
* The page issue list offered "Apply", "Mark as decorative" and AI alt text suggestions to editors who are not allowed to change image references, and the action then failed. These controls are now shown only to editors who may use them.
* The toolbar showed the frontend crawler's own count of new issues (for example "396 new" after a site scan identical to the previous one) while the scan notification correctly reported 0. The toolbar now compares the latest frontend scan with the previous compatible scan from the stored results, like the notification, and shows no change count when there is no compatible earlier scan.
* The toolbar showed "Not scanned yet" for frontend scans when opened from the Dashboard or another module without a page, although the site had frontend scans. It now uses the page selected in the page tree, or the site of the latest content scan, for both its content and its frontend summary, and the content summary no longer reports the last content scan of another site or language. With a licence, a Free Remote Preview result is no longer shown as the site's latest frontend scan, matching the Overview.
* Frontend scan history rows of scans that the AQG service no longer lists for this installation showed 0 critical, serious and moderate occurrences. They now show the stored occurrences by impact, as the Overview does, or "—" when no findings were stored for that scan.

## [2.0.0] - 2026-10-12

Accessibility Quality Gate 2.0 refreshes the TYPO3 backend experience, makes scan results easier to interpret and act on, improves accessibility feedback while editing, and strengthens frontend scanning and report security. Existing findings, ignore states and configuration are preserved.

### Highlights

- **Redesigned scan views:** Clearer Content scan and Frontend scan overviews, grouped findings, recommended fixes, affected pages and collapsible technical details. The interface also works better with narrow TYPO3 module widths.
- **Less intrusive editor feedback:** CKEditor shows compact issue markers without interrupting typing or text selection; details open on request and support keyboard navigation.
- **Improved reports:** More compact PDF exports, better Unicode support, readable severity indicators, consistent occurrence counts and clearer downloadable file names.
- **Scan notifications and coverage:** Results explain open, new and resolved findings, pages that could not be checked and incomplete scans, with links to the relevant reports.
- **Frontend screenshot evidence:** Reports retain screenshots associated with their own scans. When provided by the updated AQG service, page reports offer Original and Highlighted views.
- **Updated TYPO3 integration:** Refined toolbar, Page module indicator, Settings layout and responsive tables for TYPO3 13 and 14.

### Improvements and fixes

- Corrected scan status and coverage reporting so failed or incomplete scans are not presented as clean results.
- Improved comparison with previous compatible scans and preserved historical report selection when browsing or filtering results.
- Avoided duplicate findings between rich-text and rendered-page checks; repeated scans preserve finding identities, resolution history and ignored states.
- Improved handling of hidden or restricted pages, missing record mappings, multilingual field labels and special characters in findings and exports.
- Fixed links from findings to the affected frontend page and improved keyboard focus when closing screenshot previews or issue dialogs.
- Made frontend scan permissions and page actions consistent with backend access checks.
- Quality Gate configuration is read-only for non-administrators; unauthorized saves are rejected with a user-facing message rather than displaying a raw JSON response.
- Clarified Scanner Token guidance and trial scan usage where supported by the AQG service.

### Security

- Hardened rendered-page requests against private or reserved network destinations, including IPv4/IPv6 address resolution and redirects.
- Restricted screenshot access to users permitted to view the corresponding frontend report, site and page.
- Preserved server-side authorization for administrator-only settings and scan operations.

### Upgrade notes

- **Requirements:** TYPO3 13.4 LTS or 14.3+, PHP 8.2+. Upgrade through Composer or the TYPO3 Extension Manager, apply pending database schema updates and clear TYPO3 caches.
- **Database:** Only additive schema changes (`tx_a11y_scan.rendered_checked`, `rendered_skipped`, `rendered_skipped_json` and `tx_a11y_remote_scan_page.screenshot_evidence_json`). Existing data is not removed.
- **Configuration and findings:** No new licence key, TypoScript or TSconfig changes are required. Existing rules, findings, ignore states, field configuration, Scanner Tokens and AI settings remain in place. A subsequent content scan updates affected stored snippet formats without treating unchanged issues as newly introduced.
- **Custom template overrides:** If you override AQG's internal PDF or backend partials, review them after upgrading; some internal templates and stylesheets have been replaced.
- **AQG service:** Updated frontend screenshot evidence, scan history/coverage and trial usage features depend on the compatible AQG service. Earlier screenshots may not have both image variants. The updated service remains compatible with AQG 1.9.x clients.

## [1.9.8] - 2026-10-04

### Changed

* Administrators now see a notice in the Overview and Settings when a newer stable AQG release is available, with links to the release notes and update instructions. The notice can be dismissed per administrator and appears again for later releases. AQG never downloads or installs updates automatically, and update checks fail silently if the release service is unavailable.
* First-use guidance now explains the recommended AQG workflow: select a page, run a content scan, then use a frontend scan to check what visitors' browsers render. Installations without a licence also get clear guidance for using the Free Remote Preview.
* The Free Remote Preview now states clearly that it needs no licence key, account or email and requires a publicly reachable site. Local, development and private addresses such as DDEV, `localhost`, `.test`, `.local` and private IP addresses show an explanation instead of offering a remote scan that cannot succeed.
* The Free Remote Preview trial offer is now shown after a Free result instead of before the first scan. It explains what the 5-day trial adds and what PRO and Agency provide beyond the trial.
* Frontend scans of local, private, VPN-only or otherwise publicly unreachable sites now explain why the site cannot be scanned remotely and clarify that content scans inside TYPO3 continue to work.
* Saved licence keys are no longer exposed in the licence settings or page source. AQG displays only a masked version of the saved key. Replacing or removing a key is now an explicit action, while Revalidate checks the currently saved key.
* Overview tables now fit better into typical TYPO3 backend layouts, including when the page tree is open. Long titles and URLs wrap instead of unnecessarily widening the module, and horizontally scrollable tables remain keyboard accessible.
* Settings tabs now wrap more evenly on narrow backend layouts.
* Scan spinners, progress indicators and pulsing status elements now respect the operating system's reduced-motion preference.
* Exported PDF reports have improved readability, including larger minimum text sizes, clearer headers and footers, and better handling of long URLs and identifiers.
* PDF reports, acceptance evidence and accessibility statement PDFs now declare their document language, use a meaningful document title and include section bookmarks.
* Licence domains can now be managed directly in AQG Settings and in the customer portal. The domain table shows active, available, no-longer-detected and unavailable domains and supports search, filters and bulk actions. Trial covers one domain, PRO supports up to three active domains and Agency has no domain limit. Removing a TYPO3 site does not automatically free its domain slot, and PRO domain activations remain locked for 90 days.
* PDF exports now state clearly that they are visual reports and are not tagged for screen readers. Where available, AQG points users to an accessible alternative such as CSV export or the HTML version of the accessibility statement.

### Fixed

* Fixed permission and plan-state checks that could incorrectly show Free Remote Preview actions or paid remediation information to users who should not see them.
* Improved the contrast of page IDs in the Overview to meet WCAG AA requirements in light mode.
* Removed unnecessary empty space above the Content scan and Frontend scan tabs when no scan is running.
* Improved accessibility feedback in the rich text editor: action buttons now have visible keyboard focus, captions and severity information have improved contrast, and the selected TYPO3 colour scheme is respected consistently.
* The "Show issue list" summary in the HTML editor accessibility check is now translated.
* Improved contrast of labels, captions and running headers in exported PDF reports.
* Fixed acceptance evidence PDFs displaying their stylesheet as text instead of applying it to the document.
* Fixed missing styling in accessibility statement PDFs on case-sensitive systems such as Linux servers.
* Frontend scans and Free Remote Preview now provide clearer guidance when a configured site points to a local, private or unknown host instead of showing a generic scan failure.
* The Overview now explains when a selected page is outside every TYPO3 Site Configuration instead of incorrectly asking the editor to select a page.
* Fixed PRO licence validation for installations with multiple TYPO3 sites while preserving licence isolation between separate TYPO3 installations.
* Single-page frontend scans now correctly support TYPO3 language bases that use their own domain.
* The selected Quality Gate scope now has a visible keyboard focus indicator.
* AQG backend scripts now refresh reliably after extension updates, preventing outdated Page module, Settings or page detail behaviour caused by cached browser assets.
* Free Remote Preview and frontend scan errors now explain the next step with user-facing messages for cases such as pages outside the configured site, redirects to another host, unsupported site addresses or licence/project mismatches.
* The Page module indicator now follows a frontend scan that was already running when the page was opened and updates its progress and final state without requiring a page reload.
* Frontend scans left over from an earlier licence or Agency project identity are no longer restored as new failed scans. Stale scan state is discarded safely and no longer keeps Local Scan controls disabled.
* Agency licence summaries now distinguish between domains active in the current TYPO3 project and the total active domains across all Agency projects.
* Screenshots that are no longer available to the current project now show an explanatory unavailable state instead of a broken image.
* Report and PDF dates now use the timezone configured for the TYPO3 application.

### Security

* Hardened sitemap handling so frontend site scans only follow sitemap redirects and sitemap entries that belong to the configured site.
* Frontend scan screenshots are served only as supported image formats.
* Monitoring backend URLs are restricted to secure HTTPS host URLs.
* Hardened screenshot isolation so separate licence or Agency projects cannot replace or remove screenshots belonging to another project.

## [1.9.7] - 2026-09-28

### Changed

* PRO and Agency can check a single finding again with "Verify fix" on the frontend page detail. A fresh scan of that page reports the finding as Resolved, Still present or Not verified. Resolved is shown only when the page was checked completely; a check that is still running continues after a reload, and an earlier Resolved verdict is marked as outdated when a newer scan reports the issue again.
* PRO and Agency can monitor a site with the new Scheduler task "Accessibility monitoring", which offers the configured sites and the languages of the selected site, or with the new `a11y:monitor` console command. A removed site or language fails the run with a message instead of scanning something else. Monitoring scans the site, compares the result with the last complete scan and e-mails new or worse issues, incomplete scans and failed scans. An incomplete scan is never reported as "no change", and a notification that could not be sent is sent again on the next run.
* A scan comparison can be exported as acceptance evidence (PDF or CSV) with fixed, new, worse and open findings. Pages that were not checked completely in both scans are listed as not compared. The CSV names the site, scope, language and scan dates in every row and closes with the coverage and the limits of automated checks.
* Agency licences cover several client TYPO3 installations. Each installation is its own project with separate frontend scans, history and evidence. The licence tab shows how many projects are in use and links to the customer portal to manage them; an installation whose project was removed there is told so and can be restored.
* A single-page frontend scan now needs the "Scan this page" permission instead of "Scan all". The frontend scan buttons in the Overview and the page module are hidden for users without the permission.
* Trial texts in the licence settings no longer mention a 24-hour trial period.
* The regression signal is headed "Changes since the previous scan". The Free Remote Preview shows its remaining scans once, and its upgrade offer is hidden while a licence problem is shown. The licence tab and statement settings show less repeated status text.

### Fixed

* Scan comparisons, the regression signal and scan history now only pair scans of the same site, scope, language, start URL and scan type.
* The Free Remote Preview scans the selected site language instead of always the default language.
* The frontend page detail and running scans follow how a scan was made (Free Remote Preview or paid), not the licence that is active now. Upgrading while a Free scan runs no longer interrupts it.
* The Quality Gate now checks pages that are unhidden by editors without access to the AQG module.
* Frontend scan results are marked as saved only after all of their pages and findings are stored. An interrupted save is completed automatically instead of showing partial results as complete.
* A temporary licence service outage or request limit no longer replaces the last valid licence state, which is kept for up to 48 hours and never beyond the licence end. An expired, revoked or removed licence takes effect immediately, including after "Revalidate". A request limit is now reported as such instead of as an unreachable service.
* Ignoring a rule for a whole site now requires access to that site.
* `vendor/bin/typo3 list` now shows the AQG console commands, and on TYPO3 14 `a11y:scan` can be chosen in the Scheduler task "Execute console commands", as on TYPO3 13.
* The settings tabs wrap onto a second row when the backend content area is narrow (for example with the page tree open) instead of running past the edge of the module.

### Security

* Frontend scans started from a page are bound to that page on the server: a site, URL or stored page sent by the browser can no longer direct a scan, together with the site's scan credentials, to another site or page.
* Frontend scan results, comparisons, statements, page details and scan status are only shown for sites and pages the backend user may access. Scan job IDs in a URL can no longer open another site's results, and only users who may edit a page can cancel its content scan.
* Error messages no longer expose internal details of the scanning and licence services.
* Agency projects are bound to their TYPO3 installation, so another installation using the same licence key cannot take over a project's scans, history or evidence. Removed projects and revoked licences lose frontend scan access without waiting for already issued access tokens to expire.

## [1.9.6] - 2026-09-17

### Changed

* AQG screens are less crowded. "What to fix first" cards and the rules on a frontend page lead with the title, impact, counts and a suggested fix. Owner, fix type, confidence, WCAG and standards references, the remediation plan and technical scan data are one click away.
* Counts now say what they count: "Issue types" are the distinct rules found on a page, "Occurrences" are their individual instances. The Overview, frontend page detail, scan history, regression signal and page module use these terms instead of "Findings" or "Errors".
* "View affected pages" in "What to fix first" lists only the pages on which that rule was found, and opening one of these pages shows that rule expanded. Search and pagination keep the filter, and "Show all pages" removes it.
* The frontend page detail says which scan it shows and links to the latest scan when the page has been scanned again. Scan history, scan comparison and the regression signal mark the scan shown on the page and label compared scans as earlier and later.
* The page module labels its rows "Content scan" and "Frontend scan", each with its own count and last scan time. The panel headline summarises the overall state instead of repeating one row's count.
* On the page findings screen, each issue leads with its title. Rule-wide actions (select all with this rule, ignore on this page or site) are grouped in a "More actions" menu, so "Edit record" stays the main action.
* Quality Gate site overrides show a one-line summary of the publishing mode and thresholds, and their fields open on demand. A newly added site opens for editing, and every field is labelled for assistive technologies.
* Quality Gate messages shown on publishing now include the time of the content scan the decision is based on. The settings and the manual explain that frontend scan results are not part of the decision.
* The licence tab has one main button: "Save and validate" stores a new key and checks it. "Revalidate" and the "Retry" action in the licence status check the saved key again, and the status updates once the check succeeds.
* The Statement Assistant keeps the scan source and required fields in view and groups the optional sections under "Optional details". Required fields are marked, and a validation error opens and focuses the field concerned.
* Upgrade hints now explain what a trial, PRO or Agency licence adds where it is relevant: in the Free Remote Preview, on locked screenshots and record mapping, in the Quality Gate and licence settings and in the Statement Assistant. The AI settings tab is now called "AI suggestions", matching its alt text, link text and iframe title suggestions.

### Fixed

* Free Remote Preview request limits are now reported as a temporary pause ("Too many free scan requests") with a hint to try again later, instead of an API error.
* When the licence service cannot be reached or limits requests, the licence status shows "Not checked" instead of "Inactive".
* If the content scan fails while a page is published or unhidden, editors now see a warning that the Quality Gate could not check the page, instead of the page passing without notice.
* The regression signal no longer asks for a licence when a page has no earlier frontend scan to compare. It now says that no compatible scans exist yet.
* The licence key help no longer states that a key is bound to a single domain.
* The affected pages list in the Overview is now translated.
* Improved the AQG backend layout and accessibility: Overview page tables no longer show a doubled border with notched header corners, keyboard focus in the page module is clearly visible, also in forced-colors (high contrast) mode, and the Quality Gate scope buttons are announced correctly by screen readers.

## [1.9.5] - 2026-09-16

### Changed

* The German translation now covers all AQG backend screens, including the page module, toolbar, publish notifications, editor feedback in CKEditor and HTML content elements, and AQG record labels. Issue titles and fix guidance appear in the backend language wherever a translation is available. PDF exports remain in English.
* On first use, AQG detects the rich-text and image fields in your TYPO3 content and enables them for scanning. The Overview shows how many fields were enabled and links to the scan field settings. An existing scan field configuration is never changed.
* Licence problems are now explained in the licence settings together with the matching next step, such as renewing, choosing a plan, managing domains or validating again. Users with access to the AQG settings also see this notice in the Overview.
* In Free installations, the page module shows the page's latest Free Remote Preview result and free scan availability, with a link to the frontend scan, instead of a PRO notice.
* The "Show PRO upgrade offers" setting (previously "Show PRO hints in the AQG UI") now controls the upgrade card with trial and pricing links in the Free Remote Preview. The PRO hint in the Overview was removed.
* Plan descriptions in the backend and documentation now match what each plan includes: Trial includes frontend scans, scan history and the blocking quality gate, while PDF export, AI suggestions and the Statement Assistant require PRO or Agency. Frontend scanning is no longer described as PRO-only, because Free installations include the Free Remote Preview.

### Fixed

* A licence service outage no longer shows a valid licence as inactive. AQG now reports that the service is temporarily unavailable and offers to try again.
* The page module no longer shows Free Remote Preview results as the frontend scan status of licensed installations.
* Quality gate threshold labels now state how many open issues are allowed. They previously read "or more", although a page only fails when its issue count exceeds the threshold.

### Security

* Hardened licence validation so that a failed licence service request no longer returns internal error details.

## [1.9.4] - 2026-09-15

### Changed

* The Accessibility Statement Draft Assistant uses date pickers for the statement created and approval dates.
* Documented the Statement Assistant scan scopes, input validation and error handling in the extension manual.
* Improved visual consistency of AQG backend actions and form controls across Light and Dark modes.

### Fixed

* Fixed the Statement Assistant reporting every failed generation as "not available right now". Temporary request limits, service outages and invalid service responses now show a specific, translated message with retry guidance, and a failed attempt no longer leaves an earlier statement available for copying or download.
* Fixed "Latest site scan" in the Statement Assistant for sites that are scanned without a sitemap. It now uses the newest completed site scan of the selected site.
* Fixed Statement Assistant input handling: invalid email addresses and report URLs, unsupported options, impossible or future dates and over-long texts are rejected with a message instead of producing a statement, and long texts are no longer cut off.
* Improved the accuracy of generated statement drafts: the wording matches the scan result, incomplete scans no longer suggest a conformance status, no response time is added unless one is entered, deselected measures and technologies stay omitted, and the statement created date is included.
* Fixed statements for a specific job ID accepting a scan of another site, and PDF downloads that could use a newer scan than the previewed statement.
* Fixed a saved, valid licence being shown as inactive in Settings when the module was opened without a selected page.
* Fixed the Free Remote Preview daily usage count not updating right after a scan.
* Improved accessibility of the AQG backend: keyboard focus is visible in Dark mode and in forced-colors (high contrast) mode, the automatic colour scheme uses the correct Dark mode colours while an explicit Light choice is respected, settings tabs stay selected and keyboard-reachable on TYPO3 14, and the language switcher announces the selected language.

### Security

* Hardened remote page screenshots so that a failed screenshot request no longer returns internal error details.

## [1.9.3] - 2026-09-14

### Fixed

* Fixed scan failures caused by removed Guzzle JSON helper methods on newer Guzzle versions.
* Improved JSON compatibility across AQG API, remote scanning and AI integrations.
* Improved handling of malformed JSON requests in backend API endpoints.

## [1.9.2] - 2026-09-09

### Fixed

* Fixed Remote Page Detail finding totals so they reflect the selected page instead of the complete remote scan.
* Fixed remote scan selection so results from another page cannot be displayed in the current page context.
* Improved AQG settings error handling when TYPO3 configuration files are not writable.
* Improved accessibility of the AQG backend interface, including text contrast in Light and Dark modes, visible keyboard focus and correct settings tab semantics.
* Improved Free Remote Preview responsiveness and retry handling when the remote service is temporarily slow or unavailable.
* Hardened remote export access checks so permissions are always evaluated against the site that owns the selected scan.
* Hardened CSV exports against spreadsheet formula injection.

## [1.9.1] - 2026-09-07

### Added

* Added the official TYPO3 extension manual under `Documentation/`, rendered with the TYPO3 `render-guides` toolchain and configured through `Documentation/guides.xml`.
* Added a GitHub Actions workflow that renders the documentation on pull requests and on documentation pushes to `main`, and fails on rendering warnings.

### Changed

* Documented the rendered page check accurately: it runs for the backend page and site scans and for single-page CLI and Scheduler runs, and is skipped for subtree CLI and Scheduler runs, in changed-only mode, when disabled in the ruleset and for page doktypes without a frontend page.
* Documented CSV export scope: local findings export in all editions, frontend scan results export with a licence that includes the remote crawler.
* Documented that licensed frontend scans support both site scans and single-page scans.
* Documented that `options.a11y_quality_gate.*` is read from User TSconfig only, and that the visibility options apply to non-administrators.
* Documented finding-to-record mapping accurately, including the page and URL fallback for rendered and crawler findings.
* Documented that AI suggestions and the Free Remote Preview allowance are governed by the AQG service rather than by fixed values in the extension.
* Documented that the CKEditor plugin highlights a supported subset of the `rte.*` rules.
* Clarified the privacy wording for local and rendered checks: no scan data is sent to AQG-hosted services, while the rendered page check performs a real request to the configured site frontend.

### Fixed

* Fixed the README rule table so that rule identifiers, default severities and WCAG references match the current rule implementations and rule metadata.
* Fixed the README link to the rule reference on the product website.

## [1.9.0] - 2026-08-18

### Added

* Added Free Remote Preview for Free-tier installations. Users can run up to 5 remote single-page scans per day without a licence key, registration or email address. The daily limit resets at 00:00 UTC.
* Added a Free Remote Preview status in the Frontend scan tab showing daily usage, remaining scans and the next reset time.
* Added direct scanning of the currently selected TYPO3 page from the backend.
* Added PRO upgrade hints for remote features that are not included in Free Remote Preview, including page screenshots and TYPO3 record mapping.

### Changed

* Remote scan results are now kept separate between Free Remote Preview and PRO/Agency scans.

## [1.8.0] - 2026-07-08

### Added

* Added a local issue guidance panel for Page Detail findings, reusing AQG rule metadata for owner, fix type, WCAG references, affected users, why-it-matters and how-to-fix guidance.
* Added read-only AI link-text suggestions for `rte.non_descriptive_link`, `rte.empty_link` and `rendered.empty_link` findings.
* Added read-only AI iframe-title suggestions for `rendered.iframe_missing_title` findings.
* Added review-only AI suggestion UI states with suggested text, reason, copy action and safe no-suggestion handling.
* Added an administrator-controlled AI text suggestion toggle for link-text and iframe-title suggestions, disabled by default.

### Changed

* Local Page Detail now shows AI link-text controls only for supported link findings when AI access, AI configuration and the AI text suggestion toggle are available.
* Local Page Detail now shows AI iframe-title controls only for supported rendered iframe-title findings.
* Local Page Detail now keeps the existing issue hint as the “How to fix” fallback when no richer rule metadata is available.
* AI link-text and iframe-title suggestions now use only server-derived finding context from the submitted `findingId`; the browser request does not send bodytext, rendered HTML, href, model, API key or provider payload.
* AI suggestion workflows remain review-only: AQG suggests text and provides copy support, but does not automatically write to RTE bodytext, rendered HTML or FAL data.
* Rendered scan cHash handling no longer excludes TYPO3’s generic `no_cache` parameter globally; AQG keeps only its own rendered-scan parameters excluded.

### Fixed

* Fixed TYPO3 13 image-remediation payload preparation so FAL files are resolved through `sys_file.uid`, storage and identifier instead of relying on public file paths.
* Fixed valid TYPO3 13 FAL image originals being rejected during AI image payload preparation when they could be sent safely without unnecessary derivative processing.
* Fixed PRO remote scan submission race conditions by adding a per-site TYPO3 Core lock around the active-scan check and remote submit.
* Fixed remote submit lock contention so parallel submit requests fail closed with a safe `409` conflict instead of continuing without a lock.
* Fixed remote crawler debug log sanitizing to mask authorization headers, cookies, API keys, bearer tokens, client secrets and related sensitive keys.
* Fixed ambiguous AI link-text contexts so AQG returns `unsupported_context` unless it can identify one exact supported link from the stored finding context, rendered snippet metadata or source field.
* Fixed the AI link-text endpoint so oversized or unusually dense runtime HTML is rejected before DOM parsing.
* Fixed unexpected AI link-text resolver and provider failures so they return bounded AQG JSON instead of an HTTP 502 / invalid server response.
* Fixed local-to-metadata rule key resolution so rendered and RTE rules can reuse existing guidance metadata instead of falling back to the raw issue hint only.
* Fixed affected-users-only guidance so it no longer opens an empty “Standards and impact” details block.
* Fixed static labels in the local issue guidance partial by moving them to localization files.
* Fixed local guidance unit-test isolation by depending on a metadata presenter contract instead of constructing the full TYPO3 localization-dependent presentation service in pure unit tests.

### Security

* AI link-text and iframe-title requests send only `findingId` from the browser; all link, iframe and page context is resolved server-side.
* AI link-text and iframe-title suggestions are opt-in, disabled by default and controlled by administrator AI Settings.
* AI link-text and iframe-title suggestions never apply changes automatically and never mutate RTE bodytext, rendered HTML or database records.
* Unsafe AI outputs such as HTML, encoded HTML, raw URLs, multiline text, control characters and generic link text are rejected before being shown as usable suggestions.
* Remote scan submit locking prevents duplicate remote crawler submissions from parallel backend requests for the same site.
* Sensitive remote crawler debug values are masked before logging.

## [1.7.1] - 2026-07-06

### Added

* Added AI-assisted alt-text suggestions for local FAL image findings, covering missing and low-quality alternative text with mandatory editor review before anything is written.
* Added site-specific encrypted OpenAI project key configuration for AI alt-text suggestions, with masked key display and support for the global `AQG_OPENAI_API_KEY` environment fallback.
* Added dynamic OpenAI model discovery for the configured project key. AQG loads project-available models, filters them through an AQG compatibility registry and lets administrators select a supported model.
* Added connection verification for the current key, selected model, prompt version and AQG connection-test contract before AI suggestions can be used.
* Added support for showing OpenAI models that are available to the project but not yet supported by AQG.
* Added editor workflows for marking image findings as decorative, marking them as informative and applying reviewed alt text to the related FAL reference.
* Added an explicit non-admin image-remediation capability via User TSConfig:
  `options.a11y_quality_gate.allowImageRemediation = 1`.

### Changed

* AI alt-text suggestions now use OpenAI Responses API requests with strict Structured Outputs, bounded server-derived context, selected model profiles, `store=false` and the final `aqg_alt_text_v3` prompt.
* AI Settings now use a shared server-derived UI state for model discovery, model selection, verification status, timestamps, safe error codes and action availability.
* The selected OpenAI model is now site-specific and verification is valid only for the current key fingerprint, selected model, prompt version and connection-test contract.
* FREE and PRO backend local page-scan handling now share an idempotent JavaScript initializer so “Scan this page” behaves consistently across backend module variants.
* Settings navigation and AI Settings layouts were improved for narrow TYPO3 backend viewports, light mode, dark mode, keyboard focus and responsive wrapping.
* Image remediation permission handling now combines the explicit AQG capability with TYPO3 page, record, table, field, workspace and language checks before any write operation.

### Fixed

* Fixed the PRO Page Detail “Scan this page” action so the enabled button triggers the local page-scan request instead of remaining inert.
* Fixed AI Settings refresh handling so disappeared OpenAI models, stale options and stale selected models cannot survive a model-discovery refresh.
* Fixed AI Settings state rendering so non-2xx model-discovery and connection-test responses update the visible status, safe error code, timestamps and button states without relying on a page reload.
* Fixed unsupported OpenAI model rendering so server-rendered and AJAX-rendered Settings views stay consistent.
* Fixed responsive overflow in the AQG Settings tab navigation on narrow backend viewports.
* Fixed image-remediation write endpoints so non-admin users without the explicit AQG image-remediation capability receive `permission_denied` before any FAL reference or finding state is mutated.
* Fixed permission-denied image-remediation requests so `sys_file_reference` values and finding metadata remain unchanged.

### Security

* Image-remediation write endpoints now require server-side authorization before changing FAL reference data or resolving findings.
* Non-admin image remediation is denied by default unless explicitly enabled through User TSConfig.
* Permission failures return HTTP 403 with `permission_denied` and fail before database mutation.
* OpenAI API keys remain encrypted at rest and are never displayed after saving.
* OpenAI diagnostics expose only bounded technical status codes and do not expose API keys, authorization headers, image payloads or full provider responses.
* AI suggestions are never applied automatically; editors must review and explicitly apply the suggested text.

## [1.6.0] - 2026-06-18

### Added

* Added the Accessibility Statement Draft Assistant with English and German preview, HTML, TXT and PDF exports, configurable statement details and clear automated-draft disclaimers.
* Added PRO frontend scan history for site and single-page scans, including scan comparison, regression signals and recommended remediation plans.
* Added a centralized rule metadata presentation layer with friendly titles, plain-language guidance, affected user groups, WCAG references, techniques, documentation links, recommended owner and fix type.
* Added the conservative `rendered.landmark_unique` check for duplicate or missing accessible landmark names.
* Added the `rte.form_control_missing_label` check for form controls without an accessible label in RTE content.
* Added presentation and reporting support for the WCAG 2.2 `target-size` axe-core rule and expanded `color-contrast` remediation metadata.
* Added German translations for the new Accessibility Statement, reporting metadata and TYPO3 backend module labels introduced in this release.

### Changed

* Manual **Scan site** actions now run the FREE server-rendered HTML checks for every supported frontend page in the selected site scope. Scheduler, CLI and PRO crawler behavior remains unchanged.
* Unified rule titles, guidance and metadata across Frontend Overview, Frontend Page Detail, priority fixes, Report Bundle data, remote PDF reports and Accessibility Statement known limitations.
* Improved duplicate-ID guidance with clearer explanations for labels, links, ARIA references and affected users.
* Redesigned local, frontend and Accessibility Statement PDF exports with consistent branding, dedicated layouts and page numbering.
* Improved Settings links to product, documentation, pricing, trial, support and portal pages, using safe external-link handling and no background telemetry.
* Updated Packagist, TER and GitHub discovery metadata, installation documentation and community contribution resources.

### Fixed

* Fixed TYPO3 13 and TYPO3 14 administrator detection in Settings so authorized administrators consistently see licence management controls.
* Fixed the root Frontend Overview and its PDF/CSV exports so completed single-page scans are not presented as site-wide affected-page results.
* Fixed site-scope reporting so root contexts use completed site scans while page contexts continue to use the corresponding single-page scan results.

### Security

* Stored AQG licence keys are no longer assigned to non-administrator Fluid views, and licence management actions remain restricted to administrators.
* Hardened scanner preview-token validation so tokens remain site-specific and invalid or unresolved tokens fail closed without breaking normal frontend rendering.
* Accessibility Statement generation remains server-side, and generated HTML, TXT and PDF exports contain no licence key, preview token, debug payload or raw API response.

## [1.5.0] - 2026-06-08

### Added

* Added a redesigned Frontend scan overview for PRO remote scans with an automated accessibility signal, priority fixes, affected pages, report support and export actions.
* Added “What to fix first” recommendations based on impact, affected pages, WCAG mapping and remediation guidance.
* Added report and audit support blocks for remote scan results, including WCAG / BFSG / BITV reporting aid, manual review checklist and automated review disclaimers.
* Added additional automated signals for remote scans, including keyboard exploration, page structure and shared template/component signals.
* Added remediation summaries for remote scan results to group suggested work by editor/content, developer/template and design-related fixes.
* Added page-level “Start here” recommendations in Remote Page Detail.
* Added node-level remediation guidance for common frontend accessibility findings.
* Added color contrast remediation details for remote `color-contrast` findings, including current colors, actual/required ratio, candidate colors, preferred candidate and estimated contrast ratios.
* Added compact color candidate output to Remote Page Detail, CSV export and PDF export.
* Added support for API contract feature metadata so the TYPO3 integration can detect available remote crawler capabilities.
* Added CSV export columns for remote contrast remediation data.

### Changed

* Improved Remote Overview wording to consistently describe results as automated signals and reporting aids, not as compliance confirmation.
* Improved Remote Overview export links so CSV and PDF exports use the currently displayed remote scan context.
* Improved Remote Page Detail PDF generation for better server-side performance.
* Remote Page Detail PDF now uses a compact export layout and omits heavy screenshots in favour of a placeholder when needed.
* Improved PDF layout for remote report summaries, screenshots, candidate colors and remediation summaries.
* Improved dark/auto mode support for TYPO3 14 backend themes, including `data-theme="fresh"` and `data-color-scheme="auto"`.
* Improved responsive layout and spacing in Remote Overview priority cards, report support sections and affected page tables.
* Improved PRO capability resolution across site and language base URLs.
* Improved PRO cache invalidation after licence re-validation.
* Improved remote scan recovery and persistence for newer optional crawler fields while keeping backward compatibility with older scans.

### Fixed

* Fixed Remote Overview CSV/PDF exports falling back to an unrelated latest site scan instead of the currently displayed page scan.
* Fixed empty or incomplete Remote Overview exports for page-scope remote scans.
* Fixed Remote Page Detail handling of contrast detail data stored as either a list or a single object.
* Fixed persistence of remote page remediation and recommendation data.
* Fixed Remote Overview visibility when existing remote results are available but the PRO status cache is stale.
* Fixed dark mode hover and focus states for report support summaries, manual review rows, reporting tables, toggle badges and affected page action links.
* Fixed duplicated SCSS override layers in the Remote Overview styles.
* Fixed “Last scan” label formatting in Remote Overview.
* Fixed protected local issues so ignored or muted issues are not reopened or updated incorrectly during later upserts.
* Fixed resolved issue recovery when a matching issue is found by source and rule but its fingerprint changed.
* Fixed local issue lifecycle consistency for ignored issue expiry data.

### Security

* Hardened Settings permissions so licence keys, PRO configuration, remote scan access and quality gate administration settings can only be changed by administrators.
* Hardened remote scan status, summary and cancel actions with local job ownership and site-context validation.
* Hardened remote crawler response handling with job/site identity checks before persisting results.
* Reduced debug data exposure in remote crawler error responses outside development/admin debug contexts.
* Hardened remote scan access helpers for scanner token, HTTP Basic Auth and remote access test actions.

## [1.4.1] - 2026-06-04

### Fixed
- Fixed remote frontend scanner token not activating AQG debug markers on the TYPO3 frontend. Remote scans now correctly embed content-element markers and map issues to source records.
- Fixed scanner token validation to accept site-specific ruleset tokens in addition to the default scanner token.
- Cleaned up the TypoScript marker condition to read PSR-7 request attributes instead of performing redundant token validation.
- Clarified scanner token help text and warning layout in Remote Scan Access settings.

## [1.4.0] - 2026-06-02

### Added
- Added TYPO3 14 compatibility while keeping TYPO3 13.4 LTS support in the same extension version.
- Added TYPO3 14 compatible Scheduler task handling for automated local accessibility scans.
- Added improved PRO/Agency/Trial frontend scan support for TYPO3 14, including scan progress, screenshots, page detail actions and report exports.
- Added Trial support with visible trial status, expiry information and frontend scan limits.

### Changed
- Moved the AQG backend module into the Content area on TYPO3 14 to match the new backend structure.
- Improved Remote/Frontend Overview so it behaves like the local Overview: one page is shown once, repeated page scans update the latest result instead of creating duplicate rows.
- Improved Remote Page Detail with clearer frontend scan results, screenshot preview, mapped record actions and export handling.
- Improved toolbar and Page Module status so running and completed frontend scans are visible while editors work.
- Improved the empty Remote Overview state by keeping the normal summary layout with dash values until a site-wide frontend scan exists.

### Fixed
- Fixed TYPO3 14 Scheduler task creation, editing and execution while keeping TYPO3 13.4 LTS Scheduler compatibility.
- Fixed Settings save behavior for rules, fields, rendered checks, dictionary mode and licence-related settings.
- Fixed Agency and Trial remote scan access so valid licences unlock token generation, remote settings and frontend scans consistently.
- Fixed remote scanner token persistence for site-specific rulesets.
- Fixed frontend scan submission compatibility with crawler payload validation and relative priority URL handling.
- Fixed Remote/Frontend Overview deduplication after repeated single-page scans.
- Fixed Remote/Frontend Overview search so page UID, title, URL, HTTP status and remote scan metadata can be used for filtering.
- Fixed Remote Overview PDF/CSV export so site-wide reports are generated only from completed site scans.
- Fixed remote page PDF exports so captured screenshots are embedded reliably.
- Fixed dark mode contrast issues in the toolbar and plain HTML editor issue list.
- Fixed completed scan messages so finished scans no longer say that the scan is still running.
- Fixed the “Show PRO hints” switch so its visual state and saved value stay in sync.
- Fixed Quality Gate behavior on TYPO3 14 for warning and block-on-publish flows.

## [1.3.1] - 2026-05-27

### Fixed
- Rendered scans no longer create accessibility issues when the fetched
  frontend response is a detected technical error page. The scan reports
  a warning instead.
- Old rendered false positives with stored technical error snippets are
  automatically resolved on the next scan that detects an error page for
  the same page.
- Overview pagination now correctly preserves local, remote and
  failed-remote list state, including language and search parameters.
- Settings → Remote scan access now shows a PRO/Trial gate in FREE mode
  instead of the scanner token and crawler setup forms.
- Rendered Frontend URLs in Page Detail technical details are now
  clickable links.
- Language switcher in Overview and Page Detail now only offers languages
  that have an existing page translation.
- Rendered page checks are now skipped for non-frontend page types
  (External URL, Shortcut, Backend user section, Mountpoint, Spacer,
  Folder) while custom renderable page types remain eligible.
- Reduced rendered-scan false positives: inert `<template>` content is
  ignored across all rendered rules; hidden noscript tracking iframes, SVG
  sprite containers, hidden helper form controls and SVGs inside
  already-named links or buttons are no longer flagged.
- `structured.header_level_is_h1` review noise is suppressed when a
  successful rendered scan confirms the final HTML contains exactly one `<h1>`.
- Rule messages, hints and severity are refreshed for re-seen issues on
  rescan, so wording fixes apply without a manual delete-and-rescan.
- Updated `rte.link_new_window_no_warning` wording to "new window or tab".

### Changed
- Improved user-facing warnings for rendered fetch failures, private/local
  host blocking, oversized responses and unsupported page types.
- Clarified Settings and Page Detail copy: local rendered checks inspect
  server-rendered HTML only and do not execute JavaScript, AJAX or
  lazy-loaded content.

## [1.3.0] - 2026-05-26

### Added
- Added rendered page check (FREE, no licence key required): fetches and
  analyzes the live rendered HTML of the current page, running a dedicated
  set of HTML-level accessibility rules alongside the existing TCA/RTE checks.
- Added short-lived HMAC nonce authentication for rendered page checks so
  FREE users can run page scans without configuring a scanner token in Settings.
- Added rendered HTML scanner, SSRF-hardened fetcher, URL resolver, analyzer,
  rule registry, issue factory and issue mapper.
- Added `source_type`, `frontend_url` and `css_selector` metadata fields to
  local issues to track rendered issue origin and position.
- Added rendered HTML issue mapping via `data-aqg-content-uid` /
  `data-aqg-c-type` frontend debug markers; issues outside a mapped marker
  fall back to the page record with a template/layout attribution hint.
- Added 13 rendered HTML rules: missing image alt, empty links, empty buttons,
  empty headings, missing iframe title, missing form label, duplicate IDs,
  missing table header, empty table header, SVG missing accessible name,
  missing HTML lang, missing page title, missing main landmark.
- Added custom TypoScript ExpressionLanguage condition `aqgDebugMarkers(request)`
  replacing the previously unsupported `request.getAttribute()` approach.
- Added `FE.cacheHash.excludedParameters` registration in `ext_localconf.php`
  for all rendered check URL parameters (`aqgDebug`, `aqgh`,
  `tx_aqg_rendered_check`, `_aqg_page`, `_aqg_lang`, `_aqg_nonce`).
- Added a ruleset setting to disable rendered page checks per project.
- Added an explicit `allowPrivateHosts` development override for private/local
  frontend hosts; production default remains blocked for SSRF safety.

### Changed
- Rendered checks are intentionally HTML-only: no sitemap crawling, Playwright,
  axe-core, screenshots, JavaScript execution or scheduled scans.
- Rendered checks run only during manual "Scan this page" page scans;
  site/subtree scans and scheduled tasks do not trigger rendered checks.
- `tx_aqg_rendered_check=1` requests now require either a valid short-lived
  HMAC nonce or a valid scanner token to render debug markers; backend-user
  login alone is not sufficient for rendered check requests.
- Hardened rendered URL validation for same-host redirects with optional
  site port checks.
- Tightened media transcript review hints so `uploads` document lists are
  not flagged by CType alone.
- Extended `aria-labelledby` lookup helpers with content validation and
  reused them across RTE accessible-name checks.
- Replaced remaining manual backend language parameter parsing in Overview
  and Page Detail controllers with the shared `RequestParameterService`.

### Fixed
- Fixed fingerprint backward compatibility so rendered issues include
  `rendered` and `cssSelector` in their hash while existing RTE and
  structured issues keep their previous fingerprint; ignored issues from
  earlier versions are not re-opened after upgrade.
- Fixed CKEditor live validation for new and unsaved `tt_content` records
  by resolving `pageUid` from context and falling back to page-level content
  permissions when the record UID does not yet exist.
- Fixed CKEditor live validation for escaped HTML fragments (image alt,
  document links) submitted as plain text in live validation requests.
- Fixed CKEditor issue panel to show server-side live issues even when a
  matching editable DOM target cannot be found.
- Fixed Settings module stylesheet loading so Remote scan access buttons
  use correct AQG/TYPO3 styling instead of browser defaults.
- Fixed Copy token button in Remote scan access so the icon is preserved
  when the token is regenerated.

## [1.2.0] - 2026-05-21

### Added
- Added batch ignore workflow in local Page Detail, including selectable issue cards, sticky bulk action bar, required reason confirmation, group selection, and rule-based ignore shortcuts.
- Added temporary ignore expiry for single and batch ignores with 7, 30, 90 day and custom-date options.
- Added automatic reopening of expired ignored issues with audit metadata for reopened ignores.
- Added Settings → Rules management for enabling and disabling individual accessibility rules per site/default ruleset.
- Added rule configuration filtering to local scans, CKEditor live validation, existing issue APIs, overview counts, and Quality Gate checks.
- Added remote scan access settings for HTTP Basic Authentication, excluded URL patterns, priority URLs, and cookie accept selectors.
- Added scanner token warning notice in the Overview remote scan section when hidden/draft page scanning is not configured.
- Added show-once scanner token handling in Settings → Remote scan access.
- Added local and remote scan progress blocks above the source tabs, including completed, cancelled, and running states.
- Added local content scan cancellation and remote scan cancellation UI handling.
- Added language-aware scan state handling for Overview, Page Detail, toolbar, and Page Module Indicator.
- Added Page Module Indicator for accessibility status directly in the TYPO3 Page module.
- Added improved toolbar scan card/dropdown UI and mobile handling.
- Added reusable empty state for modules opened without page/site context.
- Added cookie consent selector support in crawler submit payloads.

### Changed
- Renamed local scan actions to match frontend scan wording: `Scan this page` and `Scan site`.
- Improved Overview and Page Detail responsive behaviour, especially toolbar rows and horizontally scrollable tables on narrow screens.
- Improved scan completion UX so local and frontend scans briefly show a green completed state before reloading results.
- Changed scanner token settings so raw tokens are no longer rendered in the DOM or editable through TCA.
- Changed HTTP Basic Auth testing to resolve the target URL from TYPO3 Site configuration instead of accepting arbitrary request URLs.
- Changed remote single-page scan validation so submitted URLs must belong to the configured TYPO3 Site base or one of its language bases.
- Changed remote crawler submit payload handling to include scanner token, HTTP auth, excluded patterns, priority URLs, cookie selectors, and language metadata where configured.
- Changed Quality Gate checks and issue count queries to ignore disabled rules.
- Changed scan language constraints so all-language scans (`language_uid = -1`) are considered when viewing a specific language.
- Refactored repeated site/language resolving and URL generation into shared services.
- Refactored language switcher, expiry picker, and other repeated UI pieces into shared partials where appropriate.

### Fixed
- Fixed root Overview showing “No scan on record” after all-language subtree scans.
- Fixed Quality Gate blocking so blocked publish/unhide actions re-hide the page after DataHandler has completed.
- Fixed local and remote scan progress not reliably refreshing the backend iframe after completion.
- Fixed remote scan completion recovery so completed crawler jobs are persisted without requiring a manual “View issues” click.
- Fixed frontend scan state leaking across languages in the Page module and toolbar.
- Fixed secondary-language frontend scan buttons being disabled when a usable site/language URL can be resolved.
- Fixed stale running scan states and improved local scan cancel handling.
- Fixed duplicate `escapeHtml()` definition in backend JavaScript.
- Fixed unsupported `rte.heading_order` fallback handling in CKEditor JavaScript.
- Fixed `SiteNotFoundException` namespace usage and consolidated site resolving code.
- Fixed scanner token notice placement and styling without affecting the language switcher.
- Fixed mobile overview toolbar layout and table horizontal scrolling.
- Fixed cookie accept selector fallback between site-specific and default rulesets.

### Security
- Hardened scanner token handling: raw tokens are shown only immediately after regeneration and are no longer rendered into normal settings HTML.
- Hid scanner token storage from TCA forms by using passthrough configuration.
- Added TYPO3 record/page permission checks to CKEditor issue APIs, local scan AJAX actions, remote scan submit actions, and Page Detail ignore workflows.
- Added verification that ignored CKEditor issues belong to the requested content record.
- Added site-base validation for single-page remote scan URLs to reduce SSRF/open-crawler risk.
- Hardened HTTP Basic Auth testing by deriving the test target from TYPO3 Site configuration and blocking localhost/private/reserved targets.
- Added explicit removal option for stored HTTP Basic Auth passwords.
- Prevented crawler client exceptions from exposing raw response bodies in backend responses.
- Kept crawler payload log sanitization for scanner tokens and HTTP auth passwords.

## [1.1.0] - 2026-05-04

### Added
- Licence validation and feature gating for FREE, Trial, PRO, and Agency plans
- Trial licence support with time-limited access to PRO features
- Remote crawler integration for frontend accessibility scans (PRO)
- Remote scan overview with summary cards, top affected pages, and failed pages (PRO)
- Remote page detail view for frontend accessibility results (PRO)
- Remote CSV export for crawler results
- PDF export for overview and page detail reports (PRO)
- Screenshot preview in remote page detail
- Screenshot embedding in remote PDF exports
- Per-site quality gate configuration
- Quality gate blocking mode for publish and unhide actions (PRO)
- Multi-site support with per-site licence assignment (Agency)
- Cookie consent handling in remote scans with fallback strategies for blocking banners
- PRO upgrade hints in backend UI
- Diff tracking for new and resolved remote issues between scans

### Changed
- Refactored PDF generation to use Fluid templates
- Improved placement and consistency of export actions across overview and detail modules
- Simplified remote CSV export columns by removing internal-only values
- Improved remote detail workflow and export visibility in the backend UI
- Updated remote report rendering and screenshot handling to avoid storing screenshots permanently in TYPO3 project files

### Fixed
- Fixed invalid docheader export button usage that caused LinkButton validation exceptions
- Fixed export action rendering in local page detail
- Fixed export action rendering in remote page detail
- Fixed stylesheet loading for generated PDF reports
- Fixed severity and status badge styling in local page PDF exports
- Fixed quality gate success flash so it is not shown when a page has zero remaining issues
- Added WCAG reference to the Duplicate ID rule hint
- Removed an unused dependency from PublishHook
- Cleaned remote export output to avoid exposing unusable internal screenshot identifiers in CSV files

## [1.0.0] - 2026-03-12

### Added
- CKEditor 5 accessibility highlighting
- Backend overview and page detail modules
- Ignore and unignore workflow
- CLI scans
- TYPO3 Scheduler integration
- Changed-only scan mode
- CSV export
- TCA-based field discovery for `tt_content`
- Settings module for enabled scan fields
- Quality gate warning mode for publish and unhide actions
- 21 WCAG 2.1 Level AA accessibility rules (16 RTE rules + 5 structured rules)