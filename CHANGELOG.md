# Changelog

## [1.3.1] - 2026-05-29

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