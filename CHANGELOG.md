# Changelog

All notable changes to this project will be documented in this file.


## [1.1.3] - 2025-08-24

### Added
- SBI Quick Search overlay on the SBI page, opened with Cmd/Ctrl+Shift+P
  - Searches the currently loaded SBI GitHub repository list
  - Hyphen/underscore/space tolerant matching
  - Enter highlights the matched row with a red outline and focuses its checkbox
  - Minimal PQS-like modal styling for consistency
- New helper API: `window.kissSbiFocusRowRed(key)` for red-box selection highlight

### Changed
- Keyboard shortcuts: On the SBI page, the combo now opens the in-page SBI Quick Search (no redirect)
- Plugins page behavior unchanged: continue deferring to PQS and/or calling `PQS.open()` when available
- Enqueue `assets/sbi-quick-search.js` from `AdminInterface` on the SBI screen
- Minor CSS additions for the red highlight outline

### Docs
- Added `projectlog.md` to summarize today’s work and decisions

## [1.1.2] - 2025-08-23

### Added
- **Keyboard Shortcut Integration**: Added Cmd/Ctrl+Shift+P keyboard shortcut to navigate to Smart Batch Installer
  - Works from any WordPress admin page
  - Integrates with PQS cache system for unified experience
  - Prevents navigation if already on Smart Batch Installer page
  - Provides console logging for debugging

### Enhanced
### Enhanced
- **PQS Cache Integration**: Improved integration with Plugin Quick Search cache system
  - Unified keyboard shortcut experience across both plugins
  - Better cache status detection and management
  - Enhanced debugging and logging capabilities
- **Developer Documentation**: Updated DEVELOPER-KEYCOMBO.md with coordination system
  - Synchronized documentation across both PQS and SBI repositories
  - Added comprehensive coordination system examples
  - Enhanced integration checklist and testing procedures

## [1.1.1] - 2025-08-20
### Changed
- Core install path migrated to WordPress Plugin_Upgrader for GitHub installs (PluginInstaller)
- Implemented scoped upgrader_source_selection for repo ZIPs to normalize extracted directory names
- Verbose Upgrader error logs surfaced in UI for single and batch installs; logs available via console
- Self Tests: added Upgrader dry-run (non-destructive HEAD check for main.zip)
- Cleaned up legacy manual download/extract helpers; rely on Upgrader and WP_Filesystem

### Security/Compatibility
- Prefer WP_Filesystem operations during Upgrader filters (rename/move/delete) and fallback to direct methods only if necessary
- Improved compatibility with environments requiring filesystem credentials
- Post-install validation now prefers WordPress plugin registry (get_plugins) with file scan fallback


## [1.1.0] - 2025-08-20
### Added
- Self-updater for SBI using WordPress Plugin_Upgrader (downloads main.zip and overwrites safely)
- Pin SBI repo to the top of the list when org = kissplugins
- Admin UI: SBI row always shows ✓ WordPress Plugin and Already Activated; shows Update button when newer version exists
- PQS documentation section in README and clarification that SBI has no built-in plugin metadata cache (only repository list caching)

### Fixed
- Minor admin JS robustness around status checks

## [1.0.1] - 2025-08-17
### Added
- Status UI updates:
  - Show "Already Activated" (grayed, italic) when a plugin is already active
  - Use "Activate →" for installed-but-inactive plugins to improve affordance

### Fixed
- Robust active status detection using WordPress get_plugins(), with multisite network-activation check
- Prevented 500 errors during status checks by ensuring wp-admin/includes/plugin.php is loaded in AJAX context
- Improved reliability when plugin folder name casing differs from repository name

### Changed
- Minor CSS to style Already Activated status

## [1.0.0] - 2025-08-17
- Initial release
- GitHub organization repository scraping
- WordPress plugin detection
- Batch plugin installation
- Admin interface with AJAX functionality
- Configurable caching and settings

