# GitHub Organization Repository Manager - WordPress Plugin PRD

## Product Overview

A simplified WordPress plugin that scrapes a GitHub organization's repositories page (pre-sorted by GitHub), extracts the top 15 most recently updated repositories, identifies WordPress plugins, and provides a streamlined batch installation interface using the main branch of each repository.

## Problem Statement

Managing multiple WordPress plugins across different sites is time-consuming when:
- Manually checking which plugins have recent updates
- Installing multiple plugins one-by-one on new sites
- GitHub's interface isn't optimized for WordPress plugin management
- Need a WordPress-native interface for plugin deployment

## Target Users

- **Primary**: WordPress developers/agencies managing multiple plugins across client sites
- **Secondary**: Organizations with internal WordPress plugin libraries
- **Tertiary**: Individual developers with multiple WordPress projects

## Core Features

### 1. GitHub Organization Scraping
- Scrape GitHub organization repositories page (/{org}?tab=repositories)
- Extract top 15 repositories (leveraging GitHub's built-in sorting by recent activity)
- Parse repository names and basic metadata from HTML
- Cache results for performance (configurable TTL)

### 2. Plugin Detection & Validation
- For each repository, check main branch for WordPress plugin structure
- Look for primary plugin file with WordPress header
- Extract plugin metadata (name, description, version)
- Filter out non-WordPress repositories

### 3. Simplified Installation Interface
- Display filtered WordPress plugins in a clean list
- Show plugin name, description, and last update info
- Bulk selection checkboxes for batch installation
- Install directly from main branch ZIP download

### 4. Streamlined Installation Process
- Download repository as ZIP from GitHub's archive endpoint
- Extract and validate plugin structure
- Install to WordPress plugins directory
- Optional automatic activation

## Technical Requirements

### WordPress Compatibility
- WordPress 5.0+ support
- PHP 7.4+ compatibility
- Multisite network compatibility
- Admin interface integration

### GitHub Integration (Simplified)
- No API token required - uses public scraping
- Scrape organization repositories page HTML
- Rate limiting via caching (avoid repeated requests)
- Parse repository list from DOM elements

### Data Management
- Lightweight local caching of repository data
- Configurable cache duration (default: 1 hour)
- No complex database schema needed
- WordPress transients for temporary storage

### Security Considerations
- Secure API token storage
- Input validation and sanitization
- Capability checks for installation permissions
- Safe plugin/theme installation process

## User Interface Design

### Main Dashboard Page
- Repository grid/list view with sorting options
- Search and filter functionality
- Bulk selection checkboxes
- Installation progress indicators

### Configuration Page
- GitHub organization name input (e.g., "kissplugins")
- Cache duration setting
- Number of repositories to check (default: 15)
- Simple refresh button to clear cache

### Installation Modal
- Selected plugins/themes review
- Installation options (activate, network activate)
- Progress tracking with detailed logs
- Success/error reporting

## Success Metrics

- Successful GitHub API connections
- Repository discovery accuracy (% of actual WP plugins found)
- Installation success rate
- Time saved in plugin deployment process
- User adoption and retention

## Technical Architecture

### Data Flow
1. Admin enters GitHub organization name (e.g., "kissplugins")
2. Plugin scrapes https://github.com/{org}?tab=repositories page
3. Extracts top 15 repository names from HTML
4. For each repo, fetches main branch to check for WordPress plugin structure
5. Caches valid WordPress plugins list
6. Displays filtered list in admin interface
7. Handles batch installation via GitHub's archive download URLs

### Database Schema
- WordPress transients for temporary caching
- Single option for plugin configuration
- No custom database tables required

### API Endpoints
- GitHub organization repositories page (public HTML)
- GitHub archive download URLs (/{org}/{repo}/archive/refs/heads/main.zip)
- GitHub raw content API for plugin header detection

## Edge Cases & Considerations

### Repository Detection
- Private repositories (require token permissions)
- Repositories with multiple plugins/themes
- Non-standard plugin/theme structures
- Archived or deprecated repositories

### Installation Challenges
- Plugin dependencies (not handled in MVP)
- Plugin folder naming conflicts
- Main branch stability vs. releases
- Plugin activation after installation

### Rate Limiting
- GitHub doesn't rate limit public page access heavily
- Caching prevents excessive requests
- No API authentication required
- Simple backoff strategy if needed

## Future Enhancements

### Phase 2 Features
- Webhook integration for real-time updates
- Plugin update notifications
- Dependency management
- Custom installation profiles

### Phase 3 Features
- Support for private repositories
- Integration with WordPress.org plugin directory
- Automated testing before installation
- Plugin conflict detection

## Risk Assessment

### High Priority Risks
- GitHub HTML structure changes breaking scraper
- Main branch instability compared to releases
- Plugin conflicts during installation

### Mitigation Strategies
- Robust HTML parsing with fallbacks
- Clear warnings about main branch usage
- Individual plugin installation with error handling

## Implementation Timeline

### Phase 1 (MVP) - 2-3 weeks
- GitHub organization page scraping
- WordPress plugin detection
- Basic installation functionality
- Simple admin interface

### Phase 2 (Enhanced) - 2-3 weeks
- Advanced filtering and search
- Batch installation improvements
- Configuration enhancements

### Phase 3 (Polish) - 1-2 weeks
- UI/UX improvements
- Performance optimizations
- Documentation and testing

## Open Questions

1. Should we limit to exactly 15 repositories or make it configurable?
2. How do we handle repositories where the plugin file isn't in the root?
3. Should we show a warning when installing from main branch vs releases?
4. Do we need any validation of plugin compatibility before installation?
5. Should we support custom branch selection or stick with main/master?

## Definition of Done

- Plugin successfully scrapes GitHub organization repositories page
- Accurately identifies top 15 most recently updated repositories
- Detects WordPress plugins from repository structure
- Successfully installs selected plugins from main branch
- Includes basic error handling for common scenarios
- Simple, intuitive admin interface
- Works with public GitHub organizations without authentication
