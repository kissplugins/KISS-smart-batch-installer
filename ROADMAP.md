# GitHub Organization Repository Manager - Developer Roadmap and Checklist

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
- Input validation and sanitization
- Capability checks for installation permissions
- Safe plugin installation process using WordPress core APIs
- Nonce verification for all AJAX requests

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

### Recently delivered (v1.1.0)
- Self-updater for SBI via Plugin_Upgrader; updates from main branch ZIP
- Pin SBI repo to top when org = kissplugins; show update CTA when newer exists
- PQS integration docs and UI indicator improvements

## WordPress Core API Migration - CRITICAL PRIORITY

### Current State Analysis
The plugin currently uses a mix of custom file operations and WordPress core APIs. To ensure security, compatibility, and maintainability, we need to fully migrate to WordPress core functions.

### High Priority Security & Compatibility Issues

#### 1. Custom File Operations (HIGH RISK)
**Current Issues:**
- `PluginInstaller.php` uses custom ZIP extraction with `file_put_contents()`
- Manual directory creation and file writing bypasses WordPress filesystem abstractions
- No handling of filesystem credentials or permissions
- Potential security vulnerabilities in file handling

**Required Changes:**
- Replace entire custom installation logic with `Plugin_Upgrader`
- Use `WP_Filesystem` APIs for all file operations
- Implement proper error handling and cleanup

#### 2. Inconsistent Error Handling
**Current Issues:**
- Mix of WP_Error and custom error handling
- AJAX responses don't consistently use WordPress patterns
- Some failures leave partial installations

**Required Changes:**
- Standardize on WP_Error for all error conditions
- Use `WP_Ajax_Upgrader_Skin` for consistent AJAX feedback
- Implement proper rollback mechanisms

### Detailed Migration Checklist

#### Phase 1: Core Installation Migration (CRITICAL - Week 1)
- [x] ✅ **Activation via WordPress core**
  - Using `activate_plugin()`, `is_plugin_active()`, `is_plugin_active_for_network()` in AJAX/install flows
  - **Status:** COMPLETE

- [x] ✅ **Self-update via core Upgrader**
  - `Core/SelfUpdater` uses `Plugin_Upgrader` + `WP_Ajax_Upgrader_Skin` and handles reactivation
  - **Status:** COMPLETE and serves as reference implementation

- [x] ✅ **Non-plugin regression self test**
  - Self Tests page includes checks for NHK-plugin-framework (known non-plugin)
  - **Status:** COMPLETE

- [x] 🔥 **CRITICAL: Migrate GitHub install path to Plugin_Upgrader**
  - **Current:** `Core/PluginInstaller->installPlugin()` uses custom download/extract logic
  - **Required:** Replace with `Plugin_Upgrader->install($zipUrl)` pattern from SelfUpdater
  - **Files to modify:** `src/Core/PluginInstaller.php`
  - **Priority:** HIGH - Security and compatibility risk
  - **Implementation:**
    ```php
    // Replace downloadPlugin() and extractPlugin() methods with:
    include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
    $skin = new WP_Ajax_Upgrader_Skin();
    $upgrader = new Plugin_Upgrader($skin);
    $zip_url = "https://github.com/{$org}/{$repo}/archive/refs/heads/main.zip";
    $result = $upgrader->install($zip_url);
    ```

- [ ] 🔥 **CRITICAL: Implement upgrader_source_selection filter**
  - **Current:** Custom extraction assumes GitHub ZIP structure
  - **Required:** Mirror SelfUpdater's `fixSourceDirectory()` method for GitHub repos
  - **Purpose:** Handle GitHub's "repo-main" directory structure
  - **Priority:** HIGH - Prevents installation failures

#### Phase 2: File System Migration (HIGH - Week 2)
- [ ] **Audit and replace manual file operations**
  - **Current:** `file_put_contents()`, `unlink()`, custom directory removal
  - **Required:** Use `WP_Filesystem` APIs or let Upgrader handle all file operations
  - **Files to audit:** `src/Core/PluginInstaller.php` (removeDirectory, extractPlugin methods)
  - **Priority:** MEDIUM-HIGH - Security best practice

- [ ] **Filesystem credentials handling**
  - **Current:** No credential handling for protected filesystems
  - **Required:** Let `Plugin_Upgrader` handle FTP/SSH credentials automatically
  - **Benefit:** Works in shared hosting environments requiring credentials

#### Phase 3: Error Handling & UX (MEDIUM - Week 2-3)
- [ ] **Surface Upgrader errors in UI**
  - **Current:** Custom error messages in AJAX responses
  - **Required:** Capture and display `WP_Ajax_Upgrader_Skin` messages
  - **Files to modify:** `assets/admin.js`, AJAX handlers in PluginInstaller
  - **Implementation:** Parse upgrader feedback and display in progress tracking

- [ ] **Post-install validation using get_plugins()**
  - **Current:** Custom plugin file detection after installation
  - **Required:** Use `get_plugins()` to verify successful installation
  - **Benefit:** More reliable than custom file scanning

#### Phase 4: Multisite & Network Features (LOW - Week 3-4)
- [ ] **Network activation support**
  - **Current:** Basic activation only
  - **Required:** Add "Network Activate" option in UI
  - **Implementation:** Use `is_plugin_active_for_network()` and network activation API
  - **Files to modify:** `src/Admin/AdminInterface.php`, `assets/admin.js`

- [ ] **Multisite compatibility testing**
  - **Current:** Basic multisite declaration
  - **Required:** Test and verify all functions work in multisite context
  - **Priority:** LOW unless targeting multisite users

#### Phase 5: Safety & Rollback (LOW - Week 4)
- [ ] **Installation rollback mechanisms**
  - **Current:** Basic cleanup on failure
  - **Required:** Proper rollback if activation fails
  - **Implementation:** Capture pre-installation state, restore on failure

- [ ] **Conflict detection**
  - **Current:** Basic "plugin exists" check
  - **Required:** Check for plugin conflicts before installation
  - **Implementation:** Analyze plugin dependencies and conflicts

#### Phase 6: Documentation & Testing (ONGOING)
- [x] ✅ **Security/permissions checks**
  - Nonces and capability checks in all AJAX endpoints verified
  - **Status:** COMPLETE

- [ ] **Update documentation**
  - **Required:** Document new Upgrader-based installation process
  - **Files:** README.md, inline code comments
  - **Include:** Troubleshooting guide for common Upgrader issues

### Security Audit Results

#### Current Security Status: ⚠️ NEEDS ATTENTION

**Strengths:**
- ✅ Proper nonce verification on all AJAX endpoints
- ✅ Capability checks (`install_plugins`, `activate_plugins`)
- ✅ Input sanitization using `sanitize_text_field()`, `sanitize_title()`
- ✅ Rate limiting through caching mechanisms

**Critical Issues to Address:**
- 🔥 **File Operations:** Custom file handling bypasses WordPress security layers
- 🔥 **ZIP Extraction:** Manual ZIP extraction could be exploited with malicious archives
- ⚠️ **Error Disclosure:** Some error messages may leak server information
- ⚠️ **GitHub Dependencies:** Reliance on GitHub infrastructure without fallbacks

**Immediate Actions Required:**
1. **Replace custom file operations with Plugin_Upgrader** (Critical - Week 1)
2. **Implement proper ZIP validation** through Upgrader (Critical - Week 1)
3. **Sanitize error messages** in AJAX responses (High - Week 2)
4. **Add installation source validation** (Medium - Week 2)

### Implementation Strategy

#### Week 1: Critical Security Migration
1. **Day 1-2:** Implement Plugin_Upgrader in installPlugin() method
2. **Day 3-4:** Add upgrader_source_selection filter for GitHub repos
3. **Day 5:** Test and validate new installation flow
4. **Weekend:** Code review and security testing

#### Week 2: Polish and Error Handling
1. **Day 1-2:** Implement proper error handling and UI feedback
2. **Day 3-4:** Audit remaining file operations
3. **Day 5:** Integration testing with various plugin types

#### Week 3-4: Advanced Features and Documentation
1. **Week 3:** Network/multisite support if needed
2. **Week 4:** Documentation updates and final testing

### Testing Strategy

#### Required Test Cases
1. **Basic Installation:** Single plugin install/activate cycle
2. **Batch Installation:** Multiple plugins with mixed success/failure
3. **Network Installation:** Multisite environment testing
4. **Error Conditions:** Network failures, malformed plugins, permission issues
5. **Rollback Testing:** Failed installations and cleanup
6. **Security Testing:** Malicious ZIP files, permission bypasses

#### Regression Testing
- [ ] Verify all existing functionality works with new Upgrader-based approach
- [ ] Test PQS integration continues working
- [ ] Validate self-update mechanism (already using Upgrader)
- [ ] Confirm cache management and repository detection

### Risk Assessment & Mitigation

#### High Priority Risks
- **Custom file operations create security vulnerabilities**
  - *Mitigation:* Immediate migration to Plugin_Upgrader
- **Installation failures leave partial state**
  - *Mitigation:* Use Upgrader's built-in cleanup and rollback
- **Filesystem permission issues in hosting environments**
  - *Mitigation:* Let WordPress handle credentials through Upgrader

#### Medium Priority Risks
- **GitHub service availability**
  - *Mitigation:* Better error handling and retry mechanisms
- **Plugin compatibility issues**
  - *Mitigation:* Enhanced validation and testing
- **User capability edge cases**
  - *Mitigation:* More granular permission checks

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

## Definition of Done - WordPress Core Migration

### Critical Requirements (Must Complete)
- [ ] All plugin installations use `Plugin_Upgrader` instead of custom code
- [ ] File operations use WordPress core APIs (`WP_Filesystem` or Upgrader)
- [ ] Error handling uses `WP_Error` consistently
- [ ] AJAX responses follow WordPress patterns
- [ ] Security audit passes with no critical issues

### Quality Requirements
- [ ] All existing functionality preserved
- [ ] Performance equal or better than current implementation
- [ ] User experience maintained or improved
- [ ] Code follows WordPress coding standards
- [ ] Comprehensive test coverage

### Documentation Requirements
- [ ] Updated README with new architecture details
- [ ] Inline code documentation for all major functions
- [ ] Troubleshooting guide for common issues
- [ ] Security considerations documented

## Open Questions

1. Should we implement plugin dependency checking before installation?
2. How should we handle plugins that require specific WordPress versions?
3. Should we add integration with WordPress.org plugin directory for updates?
4. Do we need support for installing from specific GitHub releases vs. main branch?
5. Should we implement automatic plugin updates from GitHub?

## Definition of Done

- Plugin successfully uses WordPress core APIs for all installation operations
- Security audit passes with no critical vulnerabilities
- All existing functionality preserved through migration
- Comprehensive error handling and user feedback
- Works reliably across different hosting environments
- Performance maintains or improves current benchmarks