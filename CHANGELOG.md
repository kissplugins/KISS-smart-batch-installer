# Changelog

All notable changes to this project will be documented in this file.


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

