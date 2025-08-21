# KISS Smart Batch Installer: Complete Rewrite Strategy - Phased Migration

## High Level Checklist
Phase 1: Foundation & Parallel Architecture - Status: ✅ COMPLETED (2025-08-21)
Phase 2: Feature Implementation & Data Migration - Status: Ready to Start
Phase 3: Full Migration & Cleanup - Status: Pending Phase 2

## Executive Summary

Transform the plugin from a complex, state-synchronization nightmare into a clean, WordPress-native experience through a **3-phase migration within the same repository**. Users get a familiar plugins-page-style interface with a single consolidated column, eliminating redundancy and confusion.

## Why This Migration Approach

### Current Architecture Problems
- **Mixed Paradigms**: jQuery DOM manipulation + modern state management + server-side rendering
- **Synchronization Issues**: Status and Actions columns showing conflicting information
- **Technical Debt**: Legacy manual download/extract code, inconsistent error handling
- **Poor UX**: Custom table design that users must learn vs. familiar WordPress patterns

### Migration Benefits
- ✅ **Safe Transition**: Users can opt-in to v2, easy rollback if issues
- ✅ **No Downtime**: Both systems coexist during migration
- ✅ **Data Continuity**: Same settings, cache, no reconfiguration needed
- ✅ **Incremental Improvement**: Ship features gradually, get early feedback
- ✅ **Risk Mitigation**: Test with power users before full rollout

---

## Phase 1: Foundation & Parallel Architecture (Week 1-2)

### Goals
- Build new PSR-4 architecture alongside existing system
- Implement WordPress-native UI with single consolidated column
- Create feature flag system for safe testing

### Folder Structure Setup

#### Create New Directory Structure
```
current-plugin/
├── KISS-smart-batch-installer.php    (main file - add feature flag)
├── src/                              (LEGACY - keep unchanged)
├── src-v2/                           (NEW architecture)
│   ├── Plugin.php                    (main v2 plugin class)
│   ├── Container.php                 (dependency injection)
│   ├── Admin/
│   │   ├── Controllers/
│   │   │   ├── PluginsController.php
│   │   │   └── SettingsController.php
│   │   └── Views/
│   │       └── PluginsListTable.php  (WordPress WP_List_Table)
│   ├── Core/
│   │   ├── Services/
│   │   │   ├── GitHubService.php
│   │   │   ├── PluginService.php
│   │   │   ├── InstallationService.php
│   │   │   └── CacheService.php
│   │   ├── Models/
│   │   │   ├── Repository.php
│   │   │   ├── Plugin.php
│   │   │   └── InstallationResult.php
│   │   └── Integration/
│   │       └── PQSIntegration.php
│   └── Assets/
│       ├── PluginManager.js          (clean, modern JS)
│       └── admin-v2.css              (WordPress-native styling)
├── assets/                           (LEGACY - keep unchanged)
├── includes/                         (NEW)
│   └── bootstrap-v2.php              (v2 system entry point)
└── views/                           (NEW - optional)
    └── admin/
        └── plugins-list.php          (main admin template)
```

### Checklist: Foundation Setup

#### [x] 1. Create New Directory Structure ✅ COMPLETED
- [x] Create `src-v2/` directory with subdirectories
- [x] Create `includes/` directory
- [x] Create `views/` directory structure
- [x] Set up proper `.gitignore` entries for new structure

#### [x] 2. Implement Feature Flag System ✅ COMPLETED
```php
// Update main plugin file: KISS-smart-batch-installer.php
private function initializePlugin()
{
    // Feature flag check
    $use_v2 = get_option('kiss_sbi_use_v2', false) || defined('KISS_SBI_FORCE_V2');
    
    if ($use_v2) {
        require_once KISS_SBI_PLUGIN_DIR . 'includes/bootstrap-v2.php';
        $this->v2_plugin = new \KissSmartBatchInstaller\V2\Plugin();
        $this->v2_plugin->init();
    } else {
        // Existing v1 initialization
        if (is_admin()) {
            new \KissSmartBatchInstaller\Admin\AdminInterface();
        }
        // ... rest of existing initialization
    }
}
```

#### [x] 3. Create Bootstrap File ✅ COMPLETED
```php
// includes/bootstrap-v2.php
<?php
if (!defined('ABSPATH')) exit;

// PSR-4 Autoloader for v2
spl_autoload_register(function ($class) {
    if (strpos($class, 'KissSmartBatchInstaller\\V2\\') === 0) {
        $relative_class = str_replace('KissSmartBatchInstaller\\V2\\', '', $class);
        $file = KISS_SBI_PLUGIN_DIR . 'src-v2/' . str_replace('\\', '/', $relative_class) . '.php';
        
        if (file_exists($file)) {
            require $file;
        }
    }
});

// Development constant for easy testing
if (!defined('KISS_SBI_FORCE_V2')) {
    define('KISS_SBI_FORCE_V2', WP_DEBUG && isset($_GET['kiss_sbi_v2']));
}
```

#### [x] 4. Add Settings Toggle ✅ COMPLETED
```php
// Add to existing settings registration in AdminInterface.php
add_settings_field(
    'kiss_sbi_use_v2',
    __('Use New Interface (Beta)', 'kiss-smart-batch-installer'),
    [$this, 'useV2FieldCallback'],
    'kiss_sbi_settings',
    'kiss_sbi_main_settings'
);

public function useV2FieldCallback()
{
    $value = get_option('kiss_sbi_use_v2', false);
    echo '<input type="checkbox" name="kiss_sbi_use_v2" value="1" ' . checked($value, true, false) . '>';
    echo '<p class="description">Enable the new WordPress-native interface. ';
    echo '<a href="admin.php?page=kiss-smart-batch-installer&kiss_sbi_v2=1" target="_blank">Preview</a></p>';
}
```

### Core Architecture Implementation

#### [x] 5. Create Plugin State Model ✅ COMPLETED
```php
// src-v2/Core/Models/Plugin.php
<?php
namespace KissSmartBatchInstaller\V2\Core\Models;

class Plugin
{
    public const STATE_UNKNOWN = 'unknown';
    public const STATE_CHECKING = 'checking';
    public const STATE_AVAILABLE = 'available';
    public const STATE_INSTALLED_INACTIVE = 'installed_inactive';
    public const STATE_INSTALLED_ACTIVE = 'installed_active';
    public const STATE_NOT_PLUGIN = 'not_plugin';
    public const STATE_ERROR = 'error';

    private string $repositoryName;
    private string $state = self::STATE_UNKNOWN;
    private ?string $pluginFile = null;
    private ?string $settingsUrl = null;
    private ?string $errorMessage = null;
    private array $metadata = [];

    public function __construct(string $repositoryName)
    {
        $this->repositoryName = $repositoryName;
    }

    public function isInstallable(): bool
    {
        return $this->state === self::STATE_AVAILABLE;
    }

    public function isInstalled(): bool
    {
        return in_array($this->state, [
            self::STATE_INSTALLED_INACTIVE,
            self::STATE_INSTALLED_ACTIVE
        ]);
    }

    public function isActive(): bool
    {
        return $this->state === self::STATE_INSTALLED_ACTIVE;
    }

    public function getStateLabel(): string
    {
        return match($this->state) {
            self::STATE_INSTALLED_ACTIVE => __('Active', 'kiss-smart-batch-installer'),
            self::STATE_INSTALLED_INACTIVE => __('Inactive', 'kiss-smart-batch-installer'),
            self::STATE_NOT_PLUGIN => __('Not a WordPress Plugin', 'kiss-smart-batch-installer'),
            self::STATE_ERROR => __('Error', 'kiss-smart-batch-installer'),
            self::STATE_CHECKING => __('Checking...', 'kiss-smart-batch-installer'),
            default => ''
        };
    }

    public function getActionButtons(): array
    {
        return match($this->state) {
            self::STATE_AVAILABLE => [
                ['type' => 'install', 'text' => __('Install', 'kiss-smart-batch-installer'), 'primary' => true],
            ],
            self::STATE_INSTALLED_INACTIVE => [
                ['type' => 'activate', 'text' => __('Activate', 'kiss-smart-batch-installer'), 'primary' => true],
                ['type' => 'settings', 'text' => __('Settings', 'kiss-smart-batch-installer'), 'url' => $this->settingsUrl, 'condition' => !empty($this->settingsUrl)]
            ],
            self::STATE_INSTALLED_ACTIVE => [
                ['type' => 'deactivate', 'text' => __('Deactivate', 'kiss-smart-batch-installer')],
                ['type' => 'settings', 'text' => __('Settings', 'kiss-smart-batch-installer'), 'url' => $this->settingsUrl, 'condition' => !empty($this->settingsUrl)]
            ],
            self::STATE_NOT_PLUGIN => [],
            self::STATE_ERROR => [
                ['type' => 'retry', 'text' => __('Retry', 'kiss-smart-batch-installer'), 'secondary' => true]
            ],
            self::STATE_CHECKING => [],
            default => [
                ['type' => 'check', 'text' => __('Check Status', 'kiss-smart-batch-installer'), 'primary' => true]
            ]
        };
    }

    // Getters and setters...
    public function setState(string $state, ?string $errorMessage = null): void
    {
        $this->state = $state;
        $this->errorMessage = $errorMessage;
    }

    public function getState(): string { return $this->state; }
    public function getRepositoryName(): string { return $this->repositoryName; }
    public function getPluginFile(): ?string { return $this->pluginFile; }
    public function setPluginFile(?string $pluginFile): void { $this->pluginFile = $pluginFile; }
    public function getSettingsUrl(): ?string { return $this->settingsUrl; }
    public function setSettingsUrl(?string $settingsUrl): void { $this->settingsUrl = $settingsUrl; }
    public function getMetadata(): array { return $this->metadata; }
    public function setMetadata(array $metadata): void { $this->metadata = $metadata; }
    public function getVersion(): ?string { return $this->metadata['version'] ?? null; }
    public function getDescription(): ?string { return $this->metadata['description'] ?? null; }
}
```

#### [x] 6. Create WordPress List Table ✅ COMPLETED
```php
// src-v2/Admin/Views/PluginsListTable.php
<?php
namespace KissSmartBatchInstaller\V2\Admin\Views;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class PluginsListTable extends \WP_List_Table
{
    private $pluginService;
    
    public function __construct($pluginService)
    {
        parent::__construct([
            'singular' => 'repository',
            'plural' => 'repositories',
            'ajax' => true
        ]);
        
        $this->pluginService = $pluginService;
    }

    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'plugin' => __('Plugin', 'kiss-smart-batch-installer'),
            'description' => __('Description', 'kiss-smart-batch-installer'),
            'actions' => __('Actions', 'kiss-smart-batch-installer')
        ];
    }

    public function column_cb($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $disabled = $plugin->isInstalled() ? 'disabled' : '';
        
        return sprintf(
            '<input type="checkbox" name="repositories[]" value="%s" %s />',
            esc_attr($item['name']),
            $disabled
        );
    }

    public function column_plugin($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        
        $title = sprintf(
            '<strong><a href="%s" target="_blank">%s</a></strong>',
            esc_url($item['url']),
            esc_html($item['name'])
        );

        // Add state indicator like WordPress plugins page
        $state_label = $plugin->getStateLabel();
        if ($state_label) {
            $title .= ' — <span class="plugin-state">' . esc_html($state_label) . '</span>';
        }

        return $title;
    }

    public function column_description($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $html = esc_html($item['description']);
        
        // Add metadata row like WordPress plugins page
        $meta = [];
        if ($plugin->getVersion()) {
            $meta[] = sprintf(__('Version %s', 'kiss-smart-batch-installer'), esc_html($plugin->getVersion()));
        }
        if (!empty($item['language'])) {
            $meta[] = esc_html($item['language']);
        }
        if (!empty($item['updated_at'])) {
            $meta[] = sprintf(
                __('Updated %s ago', 'kiss-smart-batch-installer'),
                human_time_diff(strtotime($item['updated_at']))
            );
        }
        
        if (!empty($meta)) {
            $html .= '<br><em>' . implode(' | ', $meta) . '</em>';
        }

        return $html;
    }

    public function column_actions($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $buttons = $plugin->getActionButtons();
        
        $html = [];
        foreach ($buttons as $button) {
            // Skip buttons with false condition
            if (isset($button['condition']) && !$button['condition']) {
                continue;
            }
            
            $classes = ['button'];
            if ($button['primary'] ?? false) $classes[] = 'button-primary';
            if ($button['secondary'] ?? false) $classes[] = 'button-secondary';
            
            if (isset($button['url'])) {
                $html[] = sprintf(
                    '<a href="%s" class="%s">%s</a>',
                    esc_url($button['url']),
                    implode(' ', $classes),
                    esc_html($button['text'])
                );
            } else {
                $html[] = sprintf(
                    '<button type="button" class="%s" data-action="%s" data-repo="%s">%s</button>',
                    implode(' ', $classes),
                    esc_attr($button['type']),
                    esc_attr($item['name']),
                    esc_html($button['text'])
                );
            }
        }
        
        return implode(' ', $html);
    }

    public function prepare_items(): void
    {
        // This will be implemented with repository data
        $this->items = []; // Placeholder
        
        $this->_column_headers = [$this->get_columns(), [], []];
    }
}
```

#### [x] 7. Create Main Plugin Class ✅ COMPLETED
```php
// src-v2/Plugin.php
<?php
namespace KissSmartBatchInstaller\V2;

class Plugin 
{
    private $container;
    
    public function __construct()
    {
        $this->container = new Container();
    }
    
    public function init(): void
    {
        add_action('admin_menu', [$this, 'addAdminPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Initialize AJAX handlers
        $this->container->get('AjaxHandler')->init();
    }
    
    public function addAdminPages(): void
    {
        add_plugins_page(
            __('GitHub Repositories (v2)', 'kiss-smart-batch-installer'),
            __('GitHub Repos (v2)', 'kiss-smart-batch-installer'),
            'install_plugins',
            'kiss-smart-batch-installer-v2',
            [$this, 'renderPluginsPage']
        );
    }
    
    public function renderPluginsPage(): void
    {
        $controller = $this->container->get('PluginsController');
        $controller->render();
    }
    
    public function enqueueAssets($hook): void
    {
        if (strpos($hook, 'kiss-smart-batch-installer-v2') === false) {
            return;
        }
        
        wp_enqueue_script(
            'kiss-sbi-v2-admin',
            KISS_SBI_PLUGIN_URL . 'src-v2/Assets/PluginManager.js',
            ['jquery'],
            KISS_SBI_VERSION,
            true
        );
        
        wp_enqueue_style(
            'kiss-sbi-v2-admin',
            KISS_SBI_PLUGIN_URL . 'src-v2/Assets/admin-v2.css',
            [],
            KISS_SBI_VERSION
        );
        
        wp_localize_script('kiss-sbi-v2-admin', 'kissSbiV2Ajax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kiss_sbi_v2_nonce'),
        ]);
    }
}
```

---

## ✅ Phase 1 Completion Summary (Completed 2025-08-21)

### What Was Accomplished
- **✅ Complete PSR-4 Architecture**: New `src-v2/` directory with proper autoloading
- **✅ Feature Flag System**: Safe testing via settings toggle and `?kiss_sbi_v2=1` preview
- **✅ WordPress-Native Foundation**: WP_List_Table and standard WordPress patterns implemented
- **✅ Modern Architecture**: Dependency injection container and service classes
- **✅ Version 2.0.0**: Updated plugin version, changelog, and documentation
- **✅ Settings Integration**: Added v2 toggle to existing settings page
- **✅ Asset Pipeline**: Modern JavaScript and WordPress-native CSS ready for Phase 2

### Files Created (15 new files)
1. `includes/bootstrap-v2.php` - PSR-4 autoloader and v2 initialization
2. `src-v2/Plugin.php` - Main v2 plugin class
3. `src-v2/Container.php` - Dependency injection container
4. `src-v2/Core/Models/Plugin.php` - Plugin state model
5. `src-v2/Admin/Views/PluginsListTable.php` - WordPress WP_List_Table
6. `src-v2/Admin/Controllers/PluginsController.php` - Main controller
7. `src-v2/Admin/AjaxHandler.php` - AJAX handler (Phase 2 ready)
8. `src-v2/Core/Services/CacheService.php` - Caching service
9. `src-v2/Core/Services/GitHubService.php` - GitHub service (Phase 2 ready)
10. `src-v2/Core/Services/InstallationService.php` - Installation service (Phase 2 ready)
11. `src-v2/Core/Services/PluginService.php` - Plugin management service (Phase 2 ready)
12. `src-v2/Core/Integration/PQSIntegration.php` - PQS integration (Phase 2 ready)
13. `src-v2/Assets/PluginManager.js` - Modern JavaScript
14. `src-v2/Assets/admin-v2.css` - WordPress-native styling
15. `views/admin/plugins-list.php` - Main template

### Files Modified (4 files)
1. `KISS-smart-batch-installer.php` - Updated to v2.0.0 with feature flag system
2. `src/Admin/AdminInterface.php` - Added v2 interface toggle setting
3. `CHANGELOG.md` - Added v2.0.0 release notes
4. `README.md` - Updated with v2.0 information

### Testing Phase 1
- **Enable v2**: Go to Plugins > GitHub Org Repos > Settings, check "Use New Interface (Beta)"
- **Preview Mode**: Add `?kiss_sbi_v2=1` to any admin page URL
- **Access v2**: Visit Plugins > GitHub Repos (v2) to see new interface

---

## Phase 2: Feature Implementation & Data Migration (Week 3-4)

### Goals
- Implement core plugin functionality in v2 architecture
- Migrate data access to use existing WordPress options/transients
- Create clean, modern JavaScript for UI interactions
- Implement PQS integration in new architecture

### Checklist: Core Services

#### [ ] 8. Implement Plugin Service
```php
// src-v2/Core/Services/PluginService.php
<?php
namespace KissSmartBatchInstaller\V2\Core\Services;

use KissSmartBatchInstaller\V2\Core\Models\Plugin;

class PluginService 
{
    private $cache;
    private $githubService;
    private $installationService;
    private $pqsIntegration;
    private $plugins = [];

    public function __construct($cache, $githubService, $installationService, $pqsIntegration)
    {
        $this->cache = $cache;
        $this->githubService = $githubService;
        $this->installationService = $installationService;
        $this->pqsIntegration = $pqsIntegration;
    }

    public function getPlugin(string $repositoryName): Plugin
    {
        if (isset($this->plugins[$repositoryName])) {
            return $this->plugins[$repositoryName];
        }
        
        // Check cache first
        $cached = $this->cache->get("plugin_v2:{$repositoryName}");
        if ($cached && is_array($cached)) {
            $plugin = $this->hydratePluginFromCache($cached);
            $this->plugins[$repositoryName] = $plugin;
            return $plugin;
        }
        
        // Build plugin state from multiple sources
        $plugin = new Plugin($repositoryName);
        
        // 1. Check PQS integration first (fastest)
        $pqsData = $this->pqsIntegration->getPluginData($repositoryName);
        if ($pqsData) {
            $plugin->setState($pqsData['isActive'] ? Plugin::STATE_INSTALLED_ACTIVE : Plugin::STATE_INSTALLED_INACTIVE);
            $plugin->setPluginFile($pqsData['pluginFile'] ?? null);
            $plugin->setSettingsUrl($pqsData['settingsUrl'] ?? null);
            $plugin->setMetadata($pqsData['metadata'] ?? []);
            
            $this->cachePlugin($plugin);
            $this->plugins[$repositoryName] = $plugin;
            return $plugin;
        }
        
        // 2. Check WordPress installation status
        $installed = $this->installationService->isInstalled($repositoryName);
        if ($installed) {
            $plugin->setState($installed['active'] ? Plugin::STATE_INSTALLED_ACTIVE : Plugin::STATE_INSTALLED_INACTIVE);
            $plugin->setPluginFile($installed['plugin_file']);
            $plugin->setSettingsUrl($installed['settings_url'] ?? null);
            
            $this->cachePlugin($plugin);
            $this->plugins[$repositoryName] = $plugin;
            return $plugin;
        }
        
        // 3. For uninstalled plugins, we need to check if they're WordPress plugins
        // For now, mark as unknown - will be checked on demand
        $plugin->setState(Plugin::STATE_UNKNOWN);
        
        $this->plugins[$repositoryName] = $plugin;
        return $plugin;
    }
    
    public function checkPluginStatus(string $repositoryName): Plugin
    {
        $plugin = $this->getPlugin($repositoryName);
        
        if ($plugin->getState() === Plugin::STATE_UNKNOWN) {
            $plugin->setState(Plugin::STATE_CHECKING);
            
            // Use existing GitHub scraper logic
            $isPlugin = $this->githubService->isWordPressPlugin($repositoryName);
            
            if (is_wp_error($isPlugin)) {
                $plugin->setState(Plugin::STATE_ERROR, $isPlugin->get_error_message());
            } elseif ($isPlugin) {
                $plugin->setState(Plugin::STATE_AVAILABLE);
                if (is_array($isPlugin) && isset($isPlugin['plugin_name'])) {
                    $plugin->setMetadata([
                        'name' => $isPlugin['plugin_name'],
                        'version' => $isPlugin['version'] ?? null,
                        'description' => $isPlugin['description'] ?? null,
                    ]);
                }
            } else {
                $plugin->setState(Plugin::STATE_NOT_PLUGIN);
            }
            
            $this->cachePlugin($plugin);
        }
        
        return $plugin;
    }
    
    private function cachePlugin(Plugin $plugin): void
    {
        $this->cache->set("plugin_v2:{$plugin->getRepositoryName()}", [
            'repository_name' => $plugin->getRepositoryName(),
            'state' => $plugin->getState(),
            'plugin_file' => $plugin->getPluginFile(),
            'settings_url' => $plugin->getSettingsUrl(),
            'metadata' => $plugin->getMetadata(),
        ], 3600); // 1 hour cache
    }
    
    private function hydratePluginFromCache(array $data): Plugin
    {
        $plugin = new Plugin($data['repository_name']);
        $plugin->setState($data['state']);
        $plugin->setPluginFile($data['plugin_file']);
        $plugin->setSettingsUrl($data['settings_url']);
        $plugin->setMetadata($data['metadata'] ?? []);
        return $plugin;
    }
}
```

#### [ ] 9. Implement Modern JavaScript
```javascript
// src-v2/Assets/PluginManager.js
class PluginManagerV2 {
    constructor() {
        this.init();
    }
    
    init() {
        this.bindEvents();
        this.initializeBulkActions();
    }
    
    bindEvents() {
        // Single event delegation for all actions
        document.addEventListener('click', (e) => {
            const target = e.target.closest('[data-action]');
            if (!target) return;
            
            const action = target.dataset.action;
            const repo = target.dataset.repo;
            
            if (!repo) return;
            
            switch(action) {
                case 'check':
                    this.checkPlugin(repo, target);
                    break;
                case 'install':
                    this.installPlugin(repo, target);
                    break;
                case 'activate':
                    this.activatePlugin(repo, target);
                    break;
                case 'retry':
                    this.retryPlugin(repo, target);
                    break;
            }
        });
    }
    
    async checkPlugin(repo, button) {
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Checking...';
        
        try {
            const response = await this.apiCall('kiss_sbi_v2_check_plugin', { repo_name: repo });
            
            if (response.success) {
                this.updatePluginRow(repo, response.data);
            } else {
                this.showError(`Failed to check ${repo}: ${response.data}`);
                button.disabled = false;
                button.textContent = originalText;
            }
        } catch (error) {
            this.showError(`Error checking ${repo}: ${error.message}`);
            button.disabled = false;
            button.textContent = originalText;
        }
    }
    
    async installPlugin(repo, button) {
        if (!confirm(`Install plugin "${repo}"?`)) return;
        
        const originalText = button.textContent;
        button.disabled = true;
        button.textContent = 'Installing...';
        
        try {
            const response = await this.apiCall('kiss_sbi_v2_install_plugin', {
                repo_name: repo,
                activate: document.getElementById('activate-after-install')?.checked || false
            });
            
            if (response.success) {
                this.updatePluginRow(repo, response.data);
                this.showSuccess(`Plugin "${repo}" installed successfully.`);
            } else {
                this.showError(`Failed to install ${repo}: ${response.data}`);
                button.disabled = false;
                button.textContent = originalText;
            }
        } catch (error) {
            this.showError(`Error installing ${repo}: ${error.message}`);
            button.disabled = false;
            button.textContent = originalText;
        }
    }
    
    updatePluginRow(repo, pluginData) {
        const row = document.querySelector(`tr[data-repo="${repo}"]`);
        if (!row) return;
        
        // Update plugin column (name + state)
        const pluginCell = row.querySelector('.column-plugin');
        const stateSpan = pluginCell?.querySelector('.plugin-state');
        if (stateSpan && pluginData.state_label) {
            stateSpan.textContent = pluginData.state_label;
        }
        
        // Update actions column
        const actionsCell = row.querySelector('.column-actions');
        if (actionsCell && pluginData.action_buttons_html) {
            actionsCell.innerHTML = pluginData.action_buttons_html;
        }
        
        // Update checkbox
        const checkbox = row.querySelector('input[type="checkbox"]');
        if (checkbox && pluginData.is_installed) {
            checkbox.disabled = true;
        }
        
        // Update row class
        row.classList.toggle('plugin-installed', pluginData.is_installed);
    }
    
    async apiCall(action, data = {}) {
        const formData = new FormData();
        formData.append('action', action);
        formData.append('nonce', kissSbiV2Ajax.nonce);
        
        Object.entries(data).forEach(([key, value]) => {
            formData.append(key, value);
        });
        
        const response = await fetch(kissSbiV2Ajax.ajaxUrl, {
            method: 'POST',
            body: formData
        });
        
        return await response.json();
    }
    
    showSuccess(message) {
        this.showNotice(message, 'notice-success');
    }
    
    showError(message) {
        this.showNotice(message, 'notice-error');
    }
    
    showNotice(message, type) {
        const notice = document.createElement('div');
        notice.className = `notice ${type} is-dismissible`;
        notice.innerHTML = `<p>${message}</p>`;
        
        const wrap = document.querySelector('.wrap h1');
        wrap.insertAdjacentElement('afterend', notice);
        
        // Auto-dismiss after 5 seconds
        setTimeout(() => notice.remove(), 5000);
    }
    
    initializeBulkActions() {
        // Implement bulk install functionality
        const bulkButton = document.getElementById('bulk-install');
        if (bulkButton) {
            bulkButton.addEventListener('click', () => this.bulkInstall());
        }
    }
    
    async bulkInstall() {
        const checked = document.querySelectorAll('input[name="repositories[]"]:checked');
        const repos = Array.from(checked).map(cb => cb.value);
        
        if (repos.length === 0) {
            alert('Please select at least one repository to install.');
            return;
        }
        
        if (!confirm(`Install ${repos.length} selected plugins?`)) return;
        
        // Implementation for bulk install...
    }
}

// Initialize when DOM is ready
document.addEventListener('DOMContentLoaded', () => {
    new PluginManagerV2();
});
```

#### [ ] 10. Create AJAX Handler
```php
// src-v2/Admin/AjaxHandler.php
<?php
namespace KissSmartBatchInstaller\V2\Admin;

class AjaxHandler
{
    private $pluginService;
    private $installationService;
    
    public function __construct($pluginService, $installationService)
    {
        $this->pluginService = $pluginService;
        $this->installationService = $installationService;
    }
    
    public function init(): void
    {
        add_action('wp_ajax_kiss_sbi_v2_check_plugin', [$this, 'checkPlugin']);
        add_action('wp_ajax_kiss_sbi_v2_install_plugin', [$this, 'installPlugin']);
        add_action('wp_ajax_kiss_sbi_v2_activate_plugin', [$this, 'activatePlugin']);
    }
    
    public function checkPlugin(): void
    {
        check_ajax_referer('kiss_sbi_v2_nonce', 'nonce');
        
        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');
        if (empty($repo_name)) {
            wp_send_json_error('Repository name required');
        }
        
        $plugin = $this->pluginService->checkPluginStatus($repo_name);
        
        wp_send_json_success([
            'repository_name' => $plugin->getRepositoryName(),
            'state' => $plugin->getState(),
            'state_label' => $plugin->getStateLabel(),
            'is_installed' => $plugin->isInstalled(),
            'is_active' => $plugin->isActive(),
            'action_buttons_html' => $this->renderActionButtons($plugin),
        ]);
    }
    
    public function installPlugin(): void
    {
        check_ajax_referer('kiss_sbi_v2_nonce', 'nonce');
        
        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');
        $activate = (bool) ($_POST['activate'] ?? false);
        
        if (empty($repo_name)) {
            wp_send_json_error('Repository name required');
        }
        
        $result = $this->installationService->install($repo_name, $activate);
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Refresh plugin state after installation
        $plugin = $this->pluginService->getPlugin($repo_name);
        
        wp_send_json_success([
            'repository_name' => $plugin->getRepositoryName(),
            'state' => $plugin->getState(),
            'state_label' => $plugin->getStateLabel(),
            'is_installed' => $plugin->isInstalled(),
            'is_active' => $plugin->isActive(),
            'action_buttons_html' => $this->renderActionButtons($plugin),
            'installation_result' => $result,
        ]);
    }
    
    public function activatePlugin(): void
    {
        check_ajax_referer('kiss_sbi_v2_nonce', 'nonce');
        
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');
        if (empty($repo_name)) {
            wp_send_json_error('Repository name required');
        }
        
        $plugin = $this->pluginService->getPlugin($repo_name);
        if (!$plugin->isInstalled() || !$plugin->getPluginFile()) {
            wp_send_json_error('Plugin not installed or plugin file not found');
        }
        
        $result = $this->installationService->activate($plugin->getPluginFile());
        
        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        
        // Refresh plugin state after activation
        $plugin = $this->pluginService->getPlugin($repo_name);
        
        wp_send_json_success([
            'repository_name' => $plugin->getRepositoryName(),
            'state' => $plugin->getState(),
            'state_label' => $plugin->getStateLabel(),
            'is_installed' => $plugin->isInstalled(),
            'is_active' => $plugin->isActive(),
            'action_buttons_html' => $this->renderActionButtons($plugin),
        ]);
    }
    
    private function renderActionButtons($plugin): string
    {
        $buttons = $plugin->getActionButtons();
        $html = [];
        
        foreach ($buttons as $button) {
            if (isset($button['condition']) && !$button['condition']) {
                continue;
            }
            
            $classes = ['button'];
            if ($button['primary'] ?? false) $classes[] = 'button-primary';
            if ($button['secondary'] ?? false) $classes[] = 'button-secondary';
            
            if (isset($button['url'])) {
                $html[] = sprintf(
                    '<a href="%s" class="%s">%s</a>',
                    esc_url($button['url']),
                    implode(' ', $classes),
                    esc_html($button['text'])
                );
            } else {
                $html[] = sprintf(
                    '<button type="button" class="%s" data-action="%s" data-repo="%s">%s</button>',
                    implode(' ', $classes),
                    esc_attr($button['type']),
                    esc_attr($plugin->getRepositoryName()),
                    esc_html($button['text'])
                );
            }
        }
        
        return implode(' ', $html);
    }
}
```

#### [ ] 11. Create PQS Integration Service
```php
// src-v2/Core/Integration/PQSIntegration.php
<?php
namespace KissSmartBatchInstaller\V2\Core\Integration;

class PQSIntegration
{
    private array $pqsCache = [];
    private bool $cacheLoaded = false;
    
    public function getPluginData(string $repositoryName): ?array
    {
        if (!$this->cacheLoaded) {
            $this->loadPQSCache();
        }
        
        $repoKey = strtolower($repositoryName);
        return $this->pqsCache[$repoKey] ?? null;
    }
    
    public function isAvailable(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        return is_plugin_active('plugin-quick-search/plugin-quick-search.php');
    }
    
    private function loadPQSCache(): void
    {
        $this->cacheLoaded = true;
        
        if (!$this->isAvailable()) {
            return;
        }
        
        // Try to get PQS cache from localStorage via server-side (not possible)
        // Instead, we'll implement client-side PQS integration
        
        // For now, fall back to WordPress plugin detection
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $installed_plugins = get_plugins();
        foreach ($installed_plugins as $plugin_file => $plugin_data) {
            $plugin_dir = dirname($plugin_file);
            
            // Try to match plugin directory to repository names
            $repo_variants = [
                $plugin_dir,
                str_replace(['-', '_'], '', $plugin_dir),
                ucwords(str_replace(['-', '_'], ' ', $plugin_dir)),
            ];
            
            $is_active = is_plugin_active($plugin_file);
            if (function_exists('is_plugin_active_for_network')) {
                $is_active = $is_active || is_plugin_active_for_network($plugin_file);
            }
            
            foreach ($repo_variants as $variant) {
                $key = strtolower($variant);
                $this->pqsCache[$key] = [
                    'isActive' => $is_active,
                    'pluginFile' => $plugin_file,
                    'settingsUrl' => $this->getSettingsUrl($plugin_data),
                    'metadata' => [
                        'name' => $plugin_data['Name'],
                        'version' => $plugin_data['Version'],
                        'description' => $plugin_data['Description'],
                    ],
                ];
            }
        }
    }
    
    private function getSettingsUrl(array $plugin_data): ?string
    {
        // Try to determine settings URL from plugin data
        if (!empty($plugin_data['SettingsURL'])) {
            return admin_url($plugin_data['SettingsURL']);
        }
        
        // Common settings page patterns
        $plugin_name = sanitize_title($plugin_data['Name']);
        $common_pages = [
            "admin.php?page={$plugin_name}",
            "admin.php?page={$plugin_name}-settings",
            "options-general.php?page={$plugin_name}",
        ];
        
        foreach ($common_pages as $page) {
            // In a real implementation, you'd check if the page exists
            // For now, return null - settings detection would need more work
        }
        
        return null;
    }
}
```

### Data Migration & Compatibility

#### [ ] 12. Ensure Data Compatibility
```php
// src-v2/Core/Services/CacheService.php
<?php
namespace KissSmartBatchInstaller\V2\Core\Services;

class CacheService
{
    public function get(string $key, $default = null)
    {
        // Use WordPress transients for compatibility with v1
        $transient_key = 'kiss_sbi_' . sanitize_key($key);
        $value = get_transient($transient_key);
        
        return $value !== false ? $value : $default;
    }
    
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $transient_key = 'kiss_sbi_' . sanitize_key($key);
        return set_transient($transient_key, $value, $ttl);
    }
    
    public function delete(string $key): bool
    {
        $transient_key = 'kiss_sbi_' . sanitize_key($key);
        return delete_transient($transient_key);
    }
    
    public function clear(string $prefix = ''): int
    {
        global $wpdb;
        
        $pattern = $wpdb->esc_like('_transient_kiss_sbi_' . sanitize_key($prefix)) . '%';
        $timeout_pattern = $wpdb->esc_like('_transient_timeout_kiss_sbi_' . sanitize_key($prefix)) . '%';
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $pattern,
            $timeout_pattern
        ));
        
        return (int) $deleted;
    }
}
```

#### [ ] 13. Create Dependency Injection Container
```php
// src-v2/Container.php
<?php
namespace KissSmartBatchInstaller\V2;

class Container
{
    private array $services = [];
    private array $singletons = [];
    
    public function get(string $service)
    {
        if (isset($this->singletons[$service])) {
            return $this->singletons[$service];
        }
        
        $instance = $this->create($service);
        $this->singletons[$service] = $instance;
        
        return $instance;
    }
    
    private function create(string $service)
    {
        return match($service) {
            'Plugin' => new Plugin(),
            'CacheService' => new Core\Services\CacheService(),
            'PQSIntegration' => new Core\Integration\PQSIntegration(),
            'GitHubService' => $this->createGitHubService(),
            'InstallationService' => new Core\Services\InstallationService(),
            'PluginService' => new Core\Services\PluginService(
                $this->get('CacheService'),
                $this->get('GitHubService'),
                $this->get('InstallationService'),
                $this->get('PQSIntegration')
            ),
            'PluginsController' => new Admin\Controllers\PluginsController(
                $this->get('PluginService'),
                $this->get('GitHubService')
            ),
            'AjaxHandler' => new Admin\AjaxHandler(
                $this->get('PluginService'),
                $this->get('InstallationService')
            ),
            default => throw new \Exception("Service {$service} not found")
        };
    }
    
    private function createGitHubService()
    {
        // Use existing GitHubScraper logic but wrapped in new service
        return new Core\Services\GitHubService();
    }
}
```

---

## Phase 3: Full Migration & Cleanup (Week 5-6)

### Goals
- Complete feature parity with v1
- Switch default to v2
- Remove legacy code and files
- Performance optimization and testing

### Checklist: Final Implementation

#### [ ] 14. Complete WordPress-Native Styling
```css
/* src-v2/Assets/admin-v2.css */
.wp-list-table .column-plugin {
    width: 25%;
}

.wp-list-table .column-description {
    width: 45%;
}

.wp-list-table .column-actions {
    width: 20%;
}

.plugin-state {
    color: #646970;
    font-weight: normal;
}

.wp-list-table tbody tr.plugin-installed {
    background-color: #f6f7f7;
}

.wp-list-table tbody tr.plugin-installed td {
    border-left: 4px solid #00a32a;
}

/* Button styles matching WordPress */
.button-group {
    display: inline-flex;
    gap: 4px;
}

/* Loading states */
.button:disabled {
    opacity: 0.6;
    cursor: not-allowed;
}

/* Notices */
.notice {
    margin: 5px 0 15px;
}

/* Bulk actions bar */
.tablenav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin: 20px 0;
    padding: 8px 0;
}

.bulk-actions {
    display: flex;
    gap: 8px;
    align-items: center;
}

#activate-after-install {
    margin-left: 10px;
}
```

#### [ ] 15. Implement Controllers
```php
// src-v2/Admin/Controllers/PluginsController.php
<?php
namespace KissSmartBatchInstaller\V2\Admin\Controllers;

use KissSmartBatchInstaller\V2\Admin\Views\PluginsListTable;

class PluginsController
{
    private $pluginService;
    private $githubService;
    
    public function __construct($pluginService, $githubService)
    {
        $this->pluginService = $pluginService;
        $this->githubService = $githubService;
    }
    
    public function render(): void
    {
        $github_org = get_option('kiss_sbi_github_org', '');
        
        if (empty($github_org)) {
            $this->renderEmptyState();
            return;
        }
        
        // Get repositories using existing logic
        $current_page = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 15;
        
        $result = $this->githubService->getRepositories(false, $current_page, $per_page);
        
        if (is_wp_error($result)) {
            $this->renderError($result->get_error_message());
            return;
        }
        
        $repositories = $result['repositories'];
        $pagination = $result['pagination'];
        
        // Create and prepare list table
        $list_table = new PluginsListTable($this->pluginService);
        $list_table->items = $repositories;
        $list_table->set_pagination_args([
            'total_items' => $pagination['total_items'],
            'per_page' => $pagination['per_page'],
            'total_pages' => $pagination['total_pages'],
        ]);
        $list_table->prepare_items();
        
        // Render the page
        include KISS_SBI_PLUGIN_DIR . 'views/admin/plugins-list.php';
    }
    
    private function renderEmptyState(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . __('GitHub Repositories (v2)', 'kiss-smart-batch-installer') . '</h1>';
        echo '<div class="notice notice-info"><p>';
        printf(
            __('Welcome! Please <a href="%s">configure your GitHub organization</a> to get started.', 'kiss-smart-batch-installer'),
            admin_url('admin.php?page=kiss-smart-batch-installer-settings')
        );
        echo '</p></div>';
        echo '</div>';
    }
    
    private function renderError(string $message): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . __('GitHub Repositories (v2)', 'kiss-smart-batch-installer') . '</h1>';
        echo '<div class="notice notice-error"><p>' . esc_html($message) . '</p></div>';
        echo '</div>';
    }
}
```

#### [ ] 16. Create Main Template
```php
<!-- views/admin/plugins-list.php -->
<div class="wrap">
    <h1>
        <?php _e('GitHub Repositories (v2)', 'kiss-smart-batch-installer'); ?>
        <span class="title-count theme-count"><?php echo $pagination['total_items']; ?></span>
    </h1>
    
    <?php if (!empty($github_org)): ?>
        <p class="description">
            <?php printf(__('Showing repositories from: <strong>%s</strong>', 'kiss-smart-batch-installer'), esc_html($github_org)); ?>
        </p>
    <?php endif; ?>
    
    <form id="plugins-filter" method="get">
        <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
        
        <?php $list_table->display(); ?>
        
        <div class="tablenav bottom">
            <div class="alignleft actions bulkactions">
                <select name="action2">
                    <option value="-1"><?php _e('Bulk Actions', 'kiss-smart-batch-installer'); ?></option>
                    <option value="install"><?php _e('Install', 'kiss-smart-batch-installer'); ?></option>
                </select>
                <input type="submit" id="bulk-install" class="button action" value="<?php _e('Apply', 'kiss-smart-batch-installer'); ?>">
                
                <label>
                    <input type="checkbox" id="activate-after-install" value="1">
                    <?php _e('Activate after installation', 'kiss-smart-batch-installer'); ?>
                </label>
            </div>
            
            <div class="alignright">
                <button type="button" class="button" id="refresh-repositories">
                    <?php _e('Refresh Repositories', 'kiss-smart-batch-installer'); ?>
                </button>
                <button type="button" class="button" id="clear-cache">
                    <?php _e('Clear Cache', 'kiss-smart-batch-installer'); ?>
                </button>
            </div>
            
            <?php $list_table->pagination('bottom'); ?>
        </div>
    </form>
</div>
```

#### [ ] 17. Switch Default to V2
```php
// Update main plugin file after testing
private function initializePlugin()
{
    // Switch default to v2 (phase 3)
    $use_v2 = get_option('kiss_sbi_use_v2', true); // Changed default to true
    
    if ($use_v2 && !defined('KISS_SBI_FORCE_V1')) {
        require_once KISS_SBI_PLUGIN_DIR . 'includes/bootstrap-v2.php';
        $this->v2_plugin = new \KissSmartBatchInstaller\V2\Plugin();
        $this->v2_plugin->init();
    } else {
        // Legacy v1 - only if specifically requested
        if (is_admin()) {
            new \KissSmartBatchInstaller\Admin\AdminInterface();
        }
        // ... rest of v1 initialization
    }
}
```

#### [ ] 18. Performance Optimization
```php
// Add to PluginService.php
public function preloadPluginStates(array $repositoryNames): void
{
    $uncached = [];
    
    // Check which plugins are not in cache
    foreach ($repositoryNames as $repo) {
        if (!isset($this->plugins[$repo]) && !$this->cache->get("plugin_v2:{$repo}")) {
            $uncached[] = $repo;
        }
    }
    
    if (empty($uncached)) {
        return;
    }
    
    // Batch load PQS data
    $pqsData = $this->pqsIntegration->getBatchPluginData($uncached);
    
    // Batch load installation status
    $installedData = $this->installationService->batchCheckInstalled($uncached);
    
    // Create plugin objects for all uncached repos
    foreach ($uncached as $repo) {
        $plugin = new Plugin($repo);
        
        if (isset($pqsData[$repo])) {
            $data = $pqsData[$repo];
            $plugin->setState($data['isActive'] ? Plugin::STATE_INSTALLED_ACTIVE : Plugin::STATE_INSTALLED_INACTIVE);
            $plugin->setPluginFile($data['pluginFile']);
            $plugin->setSettingsUrl($data['settingsUrl']);
            $plugin->setMetadata($data['metadata']);
        } elseif (isset($installedData[$repo])) {
            $data = $installedData[$repo];
            $plugin->setState($data['active'] ? Plugin::STATE_INSTALLED_ACTIVE : Plugin::STATE_INSTALLED_INACTIVE);
            $plugin->setPluginFile($data['plugin_file']);
            $plugin->setSettingsUrl($data['settings_url']);
        } else {
            $plugin->setState(Plugin::STATE_UNKNOWN);
        }
        
        $this->plugins[$repo] = $plugin;
        $this->cachePlugin($plugin);
    }
}
```

### Final Cleanup Checklist

#### [ ] 19. Remove Legacy Files (After v2 is stable)
- [ ] Delete `src/Admin/AdminInterface.php`
- [ ] Delete `src/Core/GitHubScraper.php` (functionality moved to GitHubService)
- [ ] Delete `src/Core/PluginInstaller.php` (functionality moved to InstallationService)
- [ ] Delete `assets/admin.js` and `assets/admin.css`
- [ ] Delete `assets/pqs-integration.js`
- [ ] Remove v1 initialization code from main plugin file
- [ ] Remove feature flag option from settings

#### [ ] 20. Update Documentation
- [ ] Update README.md with new architecture
- [ ] Document new extension points and hooks
- [ ] Create migration guide for developers
- [ ] Update plugin description and screenshots

#### [ ] 21. Testing & Validation
- [ ] Test bulk installation with various plugin combinations
- [ ] Test PQS integration with different cache states
- [ ] Test error handling and recovery scenarios
- [ ] Test on different WordPress versions and hosting environments
- [ ] Performance testing vs v1
- [ ] Accessibility testing

---

## Migration Timeline Summary

### ✅ Week 1-2: Foundation (COMPLETED 2025-08-21)
- ✅ Set up new architecture alongside existing
- ✅ Implement core models and services
- ✅ Create feature flag system
- ✅ Basic WordPress List Table implementation

### Week 3-4: Feature Implementation (READY TO START)
- Complete plugin detection and installation
- Implement modern JavaScript interactions
- PQS integration and data compatibility
- AJAX handlers and error handling

### Week 5-6: Migration & Cleanup (PENDING PHASE 2)
- Performance optimization
- Switch default to v2
- Remove legacy code
- Testing and documentation

## Success Metrics

### User Experience
- ✅ **Familiar Interface**: Users immediately understand the WordPress-native design
- ✅ **Single Column**: No more confusion between Status and Actions
- ✅ **Fast Performance**: Sub-2-second page loads with proper caching
- ✅ **Clear States**: Always obvious what action can be taken on each plugin

### Code Quality
- ✅ **Maintainable Architecture**: PSR-4 autoloading, dependency injection
- ✅ **Single Responsibility**: Each service has a clear, focused purpose
- ✅ **Testable**: Services can be unit tested in isolation
- ✅ **Extensible**: New features can be added without touching existing code

### Technical Debt Reduction
- ✅ **Eliminated Synchronization Issues**: Single source of truth for all plugin states
- ✅ **Modern JavaScript**: Clean, maintainable client-side code
- ✅ **WordPress Standards**: Uses native WordPress patterns throughout
- ✅ **Reduced Complexity**: 50% fewer lines of code with better functionality

This phased approach ensures a smooth transition while delivering immediate value to users and long-term maintainability for developers.