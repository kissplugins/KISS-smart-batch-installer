# KISS Smart Batch Installer (SBI) (SBI)

A WordPress plugin that allows you to manage and batch install WordPress plugins directly from your GitHub organization's most recently updated repositories.

## Features

- **Automatic Repository Discovery**: Scrapes your GitHub organization's repositories page to find the most recently updated repos
- **WordPress Plugin Detection**: Automatically identifies which repositories contain WordPress plugins
- **Batch Installation**: Install multiple plugins at once directly from GitHub
- **Simple Configuration**: Just enter your GitHub organization name - no API tokens required
- **Repository List Caching**: Configurable caching of the GitHub repository list (reduces GitHub requests). SBI does not include a built‑in plugin cache—use PQS.
- **Plugin Activation**: Optional automatic activation after installation

## Installation

1. Download the plugin files
2. Upload to your WordPress `/wp-content/plugins/` directory
3. Activate the plugin through the WordPress admin
4. Go to **Plugins > GitHub Org Repos** to configure

## Configuration

1. Navigate to **Plugins > GitHub Org Repos > Settings**
2. Enter your GitHub organization name (e.g., "kissplugins")
3. Configure cache duration and repository limit as needed
4. Save settings

## Usage

1. Go to **Plugins > GitHub Org Repos**
2. Click "Check" next to repositories to scan for WordPress plugins
3. Select the plugins you want to install using checkboxes
4. Optionally enable "Activate plugins after installation"
5. Click "Install Selected" for batch installation, or "Install" for individual plugins


## Using PQS Cache (Recommended)

PQS (Plugin Quick Search) is a separate plugin that builds a fast, local cache of all installed plugins. KISS Smart Batch Installer can read this cache to speed up plugin detection and status checks.

How to enable:
1. Install and activate Plugin Quick Search: https://github.com/kissplugins/KISS-Plugin-Quick-Search
2. In WordPress, go to Plugins → Plugin Quick Search and click "Rebuild PQS".
3. Return to Plugins → GitHub Org Repos. The status pill should show "PQS: Using Cache". If it says "Not Using", open the Self Tests page to troubleshoot.

Self Tests:
- Go to Plugins → GitHub Org Repos → Self Tests.
- You should see "PQS Cache plugin found" and "PQS Cache used" rows with details.

Notes:
- Read-only: SBI never writes to the PQS cache; it only reads from it.
- Fallback: Even if the PQS script isn’t loaded on the main SBI screen, SBI can detect the cache via localStorage and will mark "Using" when entries exist.
- No built‑in plugin cache: SBI no longer maintains its own plugin cache. Any reference to “cache” in this README refers to the GitHub repository scraping cache only.

## Requirements

- WordPress 5.0 or higher
- PHP 7.4 or higher
- ZipArchive PHP extension
- Public GitHub organization with public repositories
- WordPress plugins must have proper plugin headers

## File Structure

```
github-org-repo-manager/
├── github-org-repo-manager.php     # Main plugin file
├── src/                            # PSR-4 autoloaded classes
│   ├── Admin/
│   │   └── AdminInterface.php      # Admin interface handling
│   └── Core/
│       ├── GitHubScraper.php       # GitHub scraping functionality
│       └── PluginInstaller.php     # Plugin installation logic
├── assets/
│   ├── admin.js                    # Admin JavaScript
│   └── admin.css                   # Admin styles
├── languages/                      # Translation files
└── README.md                       # This file
```

## Security Features

- **Nonce Verification**: All AJAX requests are protected with WordPress nonces
- **Capability Checks**: Only users with `install_plugins` capability can install plugins
- **Input Sanitization**: All user inputs are properly sanitized
- **File Validation**: Downloaded files are validated before extraction
- **Safe Extraction**: Plugin files are safely extracted to WordPress plugins directory

## Performance Optimizations

- **Intelligent Caching**: Repository data is cached to reduce GitHub requests
- **Lazy Plugin Detection**: Plugins are only scanned when requested
- **Efficient HTML Parsing**: Uses DOMDocument for robust HTML parsing
- **Sequential Installation**: Batch installations are performed sequentially to prevent timeouts

## Limitations

- Only works with public GitHub organizations and repositories
- Installs from the main branch (not releases)
- Does not handle plugin dependencies
- Requires plugins to have standard WordPress plugin headers
- Limited to repositories that contain WordPress plugins in their root directory

## Troubleshooting

### Common Issues

**"No repositories found"**
- Verify your GitHub organization name is correct
- Ensure the organization and repositories are public
- Check that repositories exist and contain files

**"Could not find main plugin file"**
- Ensure your plugin has a proper WordPress plugin header
- Check that the main plugin file is in the repository root
- Verify the plugin file follows WordPress naming conventions

**"Failed to download plugin"**
- Check your server's internet connectivity
- Verify the repository exists and is accessible
- Ensure your server can download files via HTTP

### Debug Mode

To enable debug mode, add this to your `wp-config.php`:

```php
define('WP_DEBUG', true);
define('WP_DEBUG_LOG', true);
```

Error logs will be written to `/wp-content/debug.log`.

## Development

### PSR-4 Autoloading

The plugin uses PSR-4 autoloading for better code organization:

- Namespace: `GitHubOrgRepoManager`
- Base directory: `src/`

### Hooks and Filters

The plugin provides several hooks for customization:

```php
// Modify cache duration
add_filter('gorm_cache_duration', function($duration) {
    return 7200; // 2 hours
});

// Modify repository limit
add_filter('gorm_repository_limit', function($limit) {
    return 25; // Check top 25 repos
});

// Plugin detection patterns
add_filter('gorm_plugin_file_patterns', function($patterns) {
    $patterns[] = 'custom-plugin.php';
    return $patterns;
});
```

## Contributing

### 1.1.0
- New: SBI self-updater using WordPress Plugin_Upgrader (updates from main branch)
- New: Automatically pin SBI repo to the top when org = kissplugins
- UI: SBI row shows ✓ WordPress Plugin, Already Activated, and Update button if newer version is available
- Docs: Added “Using PQS Cache (Recommended)” and clarified SBI has no built-in plugin cache for plugins (only repository list caching)


1. Fork the repository
2. Create a feature branch
3. Follow WordPress coding standards
4. Add appropriate documentation
5. Submit a pull request

## License

This plugin is licensed under the GPL v2 or later.

## Changelog

### 1.0.1
- Fix: Prevent 500 errors during status checks by loading WordPress plugin functions in AJAX
- Fix: Correctly detect already-activated plugins (uses get_plugins and network-activation check)
- Change: UI now shows "Already Activated" for active plugins and "Activate →" for inactive installed plugins
- Change: Added subtle CSS styling for the new status label

### 1.0.0
- Initial release
- GitHub organization repository scraping
- WordPress plugin detection
- Batch plugin installation
- Admin interface with AJAX functionality
- Configurable caching and settings
