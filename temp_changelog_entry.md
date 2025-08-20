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

