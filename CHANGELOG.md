# Changelog

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