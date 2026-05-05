# Changelog

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