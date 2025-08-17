# Changelog

All notable changes to this project will be documented in this file.

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

