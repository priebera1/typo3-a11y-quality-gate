# TYPO3 Extension `a11y_quality_gate`

![TYPO3 13.4](https://img.shields.io/badge/TYPO3-13.4-orange.svg)
![PHP 8.2+](https://img.shields.io/badge/PHP-8.2+-blue.svg)
![License](https://img.shields.io/badge/License-GPL--2.0--or--later-green.svg)
![State](https://img.shields.io/badge/State-stable-brightgreen.svg)

Accessibility Quality Gate brings accessibility checks directly into the TYPO3 editorial workflow.

It combines CKEditor feedback, backend issue management, manual and automated scans, and configurable quality gate rules to help teams catch common accessibility problems earlier — before content goes live.

---

## Features

- CKEditor 5 inline highlighting for accessibility issues
- TYPO3 backend module with overview and page detail
- Issue tracking with ignore / unignore workflow
- Stable issue fingerprints across rescans
- Manual scans via CLI
- Automated scans via TYPO3 Scheduler
- Changed-only scan mode for incremental rescans
- Ruleset-based quality gate thresholds
- Warn mode on page unhide
- CSV export
- TCA-based field discovery for supported `tt_content` RTE and file fields
- Settings module for enabling and disabling scanned `tt_content` fields

---

## Requirements

- TYPO3 13.4 LTS
- PHP 8.2 or higher
- No additional external services required

---

## Screenshots

### Overview

![Overview module](Documentation/Images/overview.png)

### Page detail

![Page detail](Documentation/Images/page-detail.png)

### CKEditor inline highlighting

![CKEditor highlighting](Documentation/Images/ckeditor-inline-highlighting.png)

---

## Links

| | |
|---|---|
| **Repository** | https://github.com/priebera1/typo3-a11y-quality-gate |
| **TER** | https://extensions.typo3.org/extension/a11y_quality_gate/ |

---

## Compatibility

| Version | TYPO3 | PHP  | Support |
|---------|-------|------|---------|
| 1.x     | 13.4  | 8.2+ | features, bugfixes |

---

## Built-in rules

### RTE rules

| Rule ID | Severity | WCAG |
|---------|----------|------|
| `rte.img_alt_missing` | Critical | 1.1.1 Non-text Content |
| `rte.img_alt_is_filename` | Warning | 1.1.1 Non-text Content / Best Practice |
| `rte.empty_heading` | Warning | 1.3.1 Info and Relationships |
| `rte.empty_link` | Critical | 2.4.4 / 4.1.2 |
| `rte.button_label_missing` | Critical | 4.1.2 Name, Role, Value |
| `rte.table_missing_header` | Warning | 1.3.1 Info and Relationships |
| `rte.table_th_missing_scope` | Warning | 1.3.1 (H63) |
| `rte.table_missing_caption` | Info | 1.3.1 (H39) / Best Practice |
| `rte.duplicate_id` | Warning | Markup consistency / Best Practice |
| `rte.svg_missing_title` | Warning | 1.1.1 Non-text Content |
| `rte.iframe_missing_title` | Critical | 4.1.2 Name, Role, Value |
| `rte.image_in_link_missing_alt` | Critical | 1.1.1 / 2.4.4 |
| `rte.marquee_or_blink` | Critical | 2.2.2 Pause, Stop, Hide |
| `rte.non_descriptive_link` | Warning | 2.4.4 Link Purpose / Best Practice |

### Structured rules

| Rule ID | Severity | WCAG |
|---------|----------|------|
| `structured.file_reference_alt` | Critical | 1.1.1 Non-text Content |
| `structured.header_ctype_empty` | Warning | 1.3.1 Info and Relationships |
| `structured.header_link_no_text` | Critical | 2.4.4 / 4.1.2 |
| `structured.uploads_file_missing_description` | Warning | 2.4.4 Link Purpose |
| `structured.table_missing_caption` | Info | 1.3.1 (H39) / Best Practice |

---

## Installation

```bash
composer require priebera/typo3-a11y-quality-gate
```

Then:

1. Install and activate the extension in the TYPO3 Extension Manager
2. Apply database schema updates
3. Flush caches
4. Open the Accessibility Quality Gate module in the backend
5. Run **Re-scan TCA** in Settings once
6. Configure a Scheduler task or run scans manually via CLI

---

## Configuration

### Field settings

The extension supports TCA-based field discovery for selected `tt_content` RTE and file fields.

In the **Settings** module you can:

- refresh supported fields from TCA
- enable or disable individual fields
- control which fields are included in future scans

Changes are applied only after clicking **Save settings**.

If no field configuration exists yet, the extension falls back to its internal default field list.

At the moment, field discovery and field settings apply to `tt_content` only. Page-level metadata fields from `pages` are not part of the current FREE scanning scope.

---

## CLI usage

Scan a subtree:

```bash
./vendor/bin/typo3 a11y:scan --root-pid=1
```

Scan a single page:

```bash
./vendor/bin/typo3 a11y:scan --page-uid=42
```

Scan changed content only:

```bash
./vendor/bin/typo3 a11y:scan --root-pid=1 --changed-only
```

Scan a specific language:

```bash
./vendor/bin/typo3 a11y:scan --root-pid=1 --language=1
```

---

## Scheduler

The extension includes TYPO3 Scheduler support for:

- single-page scans
- subtree scans
- language-specific scans
- changed-only rescans

---

## Quality Gate

A default ruleset is created automatically on first use.

By default:

- critical issues are checked
- warning threshold is disabled
- publish mode is set to warn

This means editors are not spammed with warnings on fresh installations, while critical issues can still trigger quality gate feedback.

Rulesets can be configured in the backend via the **Accessibility Ruleset** record.

| Field | Description |
|-------|-------------|
| `threshold_critical` | Maximum allowed open critical issues |
| `threshold_warning` | Maximum allowed open warnings (`-1` disables warning checks) |
| `publish_mode` | `0` = disabled, `1` = warn on unhide |

For site-specific rulesets, set `site_identifier` to match the TYPO3 site configuration identifier.

---

## Backend User TSconfig

Visibility of editor-facing scan actions can be controlled via Backend User TSconfig or Backend Group TSconfig:

```tsconfig
options.a11y_quality_gate {
    showToolbarItem = 1
    showScanAll = 1
    showScanNow = 1
}
```

| Option | Default | Description |
|--------|---------|-------------|
| `showToolbarItem` | `1` | Show the accessibility indicator in the CKEditor toolbar |
| `showScanAll` | `1` | Show the "Scan all" button in the overview module |
| `showScanNow` | `1` | Show the "Scan now" button on the page detail view |

Set any option to `0` to hide it for a specific user or group. This is useful when restricting scan actions to integrators or administrators only, while still allowing editors to view and manage issues.

---

## What this extension is for

Accessibility Quality Gate is designed as a TYPO3-native editorial quality layer.

It helps editors and integrators detect common accessibility issues in content fields and manage them directly inside TYPO3.

It is not a full frontend accessibility audit tool and does not replace rendered-page testing, contrast checks, keyboard-flow testing, or a professional WCAG audit.

---

## Disclaimer

This extension detects common accessibility issues through static analysis of TYPO3 content fields. Some rules reflect best practices and may not always correspond to a hard WCAG 2.1 failure in every context.

All findings should be reviewed in context.

---

## License

GPL-2.0-or-later