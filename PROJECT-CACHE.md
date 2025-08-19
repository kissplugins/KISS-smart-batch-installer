# PQS Cache Integration Plan for KISS Smart Batch Installer

## Integration Approach

### 1. Modify AdminInterface.php

Add PQS cache integration to the main admin interface:

```php
// In src/Admin/AdminInterface.php - enqueueAssets method
public function enqueueAssets($hook)
{
    if (strpos($hook, 'kiss-smart-batch-installer') === false) {
        return;
    }

    // Existing script enqueue...
    wp_enqueue_script(
        'kiss-sbi-admin',
        KISS_SBI_PLUGIN_URL . 'assets/admin.js',
        ['jquery'],
        KISS_SBI_VERSION,
        true
    );

    // NEW: Add PQS cache integration script
    wp_enqueue_script(
        'kiss-sbi-pqs-integration',
        KISS_SBI_PLUGIN_URL . 'assets/pqs-integration.js',
        ['kiss-sbi-admin'],
        KISS_SBI_VERSION,
        true
    );

    // Add PQS integration data to localized script
    wp_localize_script('kiss-sbi-admin', 'kissSbiAjax', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce' => wp_create_nonce('kiss_sbi_admin_nonce'),
        'hasPQS' => is_plugin_active('plugin-quick-search/plugin-quick-search.php'),
        'strings' => [
            'installing' => __('Installing...', 'kiss-smart-batch-installer'),
            'installed' => __('Installed', 'kiss-smart-batch-installer'),
            'error' => __('Error', 'kiss-smart-batch-installer'),
            'scanning' => __('Scanning...', 'kiss-smart-batch-installer'),
            'pqsCacheFound' => __('Using Plugin Quick Search cache...', 'kiss-smart-batch-installer'),
            'confirmBatch' => __('Install selected plugins?', 'kiss-smart-batch-installer'),
            'noSelection' => __('Please select at least one plugin.', 'kiss-smart-batch-installer')
        ]
    ]);
}
```

### 2. Create PQS Integration JavaScript

New file: `assets/pqs-integration.js`

```javascript
jQuery(document).ready(function($) {
    'use strict';

    // PQS Cache Integration
    const PQSCacheIntegration = {
        
        init: function() {
            if (window.pqsCacheStatus && kissSbiAjax.hasPQS) {
                this.integrateWithPQSCache();
            }
        },

        integrateWithPQSCache: function() {
            const cacheStatus = window.pqsCacheStatus();
            
            if (cacheStatus === 'fresh') {
                console.log('KISS SBI: PQS cache available, pre-scanning installed plugins');
                this.scanInstalledPlugins();
            }

            // Listen for PQS cache updates
            document.addEventListener('pqs-cache-rebuilt', () => {
                console.log('KISS SBI: PQS cache rebuilt, rescanning installed plugins');
                this.scanInstalledPlugins();
            });
        },

        scanInstalledPlugins: function() {
            try {
                const pluginData = JSON.parse(localStorage.getItem('pqs_plugin_cache') || '[]');
                const installedPlugins = new Map();
                
                // Build map of installed plugins by name/slug
                pluginData.forEach(plugin => {
                    const slugs = [
                        plugin.nameLower.replace(/\s+/g, '-'),
                        plugin.name.toLowerCase().replace(/\s+/g, '-'),
                        plugin.name.toLowerCase().replace(/[^a-z0-9]/g, '-')
                    ];
                    
                    slugs.forEach(slug => {
                        installedPlugins.set(slug, {
                            name: plugin.name,
                            isActive: plugin.isActive,
                            settingsUrl: plugin.settingsUrl
                        });
                    });
                });

                // Update repository table with installation status
                this.updateRepositoryTable(installedPlugins);
                
            } catch (error) {
                console.warn('KISS SBI: Failed to read PQS cache:', error);
            }
        },

        updateRepositoryTable: function(installedPlugins) {
            $('.wp-list-table tbody tr').each(function() {
                const $row = $(this);
                const repoName = $row.data('repo');
                
                if (!repoName) return;
                
                // Generate possible plugin slugs from repo name
                const possibleSlugs = [
                    repoName.toLowerCase(),
                    repoName.toLowerCase().replace(/[^a-z0-9]/g, '-'),
                    repoName.toLowerCase().replace(/[-_]/g, '')
                ];
                
                let installedPlugin = null;
                for (const slug of possibleSlugs) {
                    if (installedPlugins.has(slug)) {
                        installedPlugin = installedPlugins.get(slug);
                        break;
                    }
                }
                
                if (installedPlugin) {
                    // Mark as installed
                    const $statusCell = $row.find('.kiss-sbi-plugin-status');
                    const $installButton = $row.find('.kiss-sbi-install-single');
                    
                    $statusCell.html(
                        '<span class="kiss-sbi-plugin-yes">✓ Installed' + 
                        (installedPlugin.isActive ? ' (Active)' : ' (Inactive)') + 
                        '</span>'
                    ).addClass('is-installed');
                    
                    $installButton.text('Installed')
                                 .addClass('button-disabled')
                                 .prop('disabled', true);
                    
                    // Add settings link if available
                    if (installedPlugin.settingsUrl) {
                        $installButton.after(
                            ' <a href="' + installedPlugin.settingsUrl + '" class="button button-small">Settings</a>'
                        );
                    }
                    
                    // Disable checkbox for installed plugins
                    $row.find('.kiss-sbi-repo-checkbox').prop('disabled', true);
                }
            });
            
            // Update batch install button state
            if (typeof updateBatchInstallButton === 'function') {
                updateBatchInstallButton();
            }
        }
    };

    // Initialize PQS integration
    PQSCacheIntegration.init();

    // Make available globally for other scripts
    window.KissSbiPQSIntegration = PQSCacheIntegration;
});
```

### 3. Enhance CSS for Installation Status

Add to `assets/admin.css`:

```css
/* Installation Status Indicators */
.kiss-sbi-plugin-status.is-installed {
    background-color: #d4edda;
    border-radius: 4px;
    padding: 5px 10px;
}

.kiss-sbi-plugin-status.is-installed .kiss-sbi-plugin-yes {
    color: #155724;
    font-weight: 600;
}

.wp-list-table tbody tr.plugin-installed {
    background-color: #f8f9fa;
    opacity: 0.8;
}

.wp-list-table tbody tr.plugin-installed .kiss-sbi-repo-checkbox:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* PQS Integration Notice */
.kiss-sbi-pqs-notice {
    display: inline-block;
    background: #e3f2fd;
    color: #0277bd;
    padding: 4px 8px;
    border-radius: 3px;
    font-size: 12px;
    margin-left: 10px;
}
```

### 4. Add PHP Backend Support

Modify `src/Admin/AdminInterface.php` to detect installed plugins:

```php
/**
 * Check if plugin is already installed
 */
private function isPluginInstalled($repo_name)
{
    if (!function_exists('get_plugins')) {
        require_once ABSPATH . 'wp-admin/includes/plugin.php';
    }
    
    $all_plugins = get_plugins();
    $repo_slug = sanitize_title($repo_name);
    
    // Check common plugin directory patterns
    $possible_paths = [
        $repo_slug . '/' . $repo_name . '.php',
        $repo_slug . '/index.php',
        $repo_slug . '/' . $repo_slug . '.php',
        $repo_slug . '/plugin.php'
    ];
    
    foreach ($possible_paths as $path) {
        if (isset($all_plugins[$path])) {
            return [
                'installed' => true,
                'active' => is_plugin_active($path),
                'path' => $path,
                'data' => $all_plugins[$path]
            ];
        }
    }
    
    return ['installed' => false];
}

/**
 * Enhanced repository table rendering with installation status
 */
private function renderRepositoriesTable($repositories)
{
    if (empty($repositories)) {
        echo '<p>' . __('No repositories found.', 'kiss-smart-batch-installer') . '</p>';
        return;
    }

    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <td class="manage-column column-cb check-column">
                    <input type="checkbox" id="kiss-sbi-select-all">
                </td>
                <th class="manage-column"><?php _e('Repository', 'kiss-smart-batch-installer'); ?></th>
                <th class="manage-column"><?php _e('Description', 'kiss-smart-batch-installer'); ?></th>
                <th class="manage-column"><?php _e('Language', 'kiss-smart-batch-installer'); ?></th>
                <th class="manage-column"><?php _e('Updated', 'kiss-smart-batch-installer'); ?></th>
                <th class="manage-column"><?php _e('Status', 'kiss-smart-batch-installer'); ?></th>
                <th class="manage-column"><?php _e('Actions', 'kiss-smart-batch-installer'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($repositories as $repo): ?>
                <?php
                $installation_status = $this->isPluginInstalled($repo['name']);
                $row_class = $installation_status['installed'] ? 'plugin-installed' : '';
                ?>
                <tr data-repo="<?php echo esc_attr($repo['name']); ?>" class="<?php echo esc_attr($row_class); ?>">
                    <th class="check-column">
                        <input type="checkbox" 
                               name="selected_repos[]" 
                               value="<?php echo esc_attr($repo['name']); ?>" 
                               class="kiss-sbi-repo-checkbox"
                               <?php echo $installation_status['installed'] ? 'disabled' : ''; ?>>
                    </th>
                    <td>
                        <strong>
                            <a href="<?php echo esc_url($repo['url']); ?>" target="_blank">
                                <?php echo esc_html($repo['name']); ?>
                            </a>
                        </strong>
                    </td>
                    <td><?php echo esc_html($repo['description']); ?></td>
                    <td><?php echo esc_html($repo['language']); ?></td>
                    <td>
                        <?php
                        if (!empty($repo['updated_at'])) {
                            echo esc_html(human_time_diff(strtotime($repo['updated_at']), current_time('timestamp')) . ' ago');
                        }
                        ?>
                    </td>
                    <td class="kiss-sbi-plugin-status <?php echo $installation_status['installed'] ? 'is-installed' : ''; ?>" 
                        data-repo="<?php echo esc_attr($repo['name']); ?>">
                        <?php if ($installation_status['installed']): ?>
                            <span class="kiss-sbi-plugin-yes">
                                ✓ Installed <?php echo $installation_status['active'] ? '(Active)' : '(Inactive)'; ?>
                            </span>
                        <?php else: ?>
                            <button type="button" class="button button-small kiss-sbi-check-plugin" 
                                    data-repo="<?php echo esc_attr($repo['name']); ?>">
                                <?php _e('Check', 'kiss-smart-batch-installer'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($installation_status['installed']): ?>
                            <button type="button" class="button button-small button-disabled" disabled>
                                <?php _e('Installed', 'kiss-smart-batch-installer'); ?>
                            </button>
                            <?php if (isset($installation_status['data']['SettingsUrl'])): ?>
                                <a href="<?php echo esc_url(admin_url($installation_status['data']['SettingsUrl'])); ?>" 
                                   class="button button-small">
                                    <?php _e('Settings', 'kiss-smart-batch-installer'); ?>
                                </a>
                            <?php endif; ?>
                        <?php else: ?>
                            <button type="button" class="button button-small kiss-sbi-install-single" 
                                    data-repo="<?php echo esc_attr($repo['name']); ?>" disabled>
                                <?php _e('Install', 'kiss-smart-batch-installer'); ?>
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <p class="kiss-sbi-batch-options">
        <label>
            <input type="checkbox" id="kiss-sbi-activate-after-install" value="1">
            <?php _e('Activate plugins after installation', 'kiss-smart-batch-installer'); ?>
        </label>
        <?php if (is_plugin_active('plugin-quick-search/plugin-quick-search.php')): ?>
            <span class="kiss-sbi-pqs-notice">
                <?php _e('Using Plugin Quick Search cache for faster loading', 'kiss-smart-batch-installer'); ?>
            </span>
        <?php endif; ?>
    </p>
    <?php
}
```

## Benefits of Integration

### 1. **Instant Status Detection**
- Immediately show which GitHub repos are already installed
- No need to manually check each repository

### 2. **Performance Boost**
- Leverage PQS's optimized plugin scanning
- Reduce redundant WordPress plugin directory scans

### 3. **Better UX**
- Visual indicators for installed/active plugins
- Disable actions for already-installed plugins
- Show settings links for installed plugins

### 4. **Smart Coordination**
- Cache invalidation coordination between plugins
- Consistent data across both interfaces

## Implementation Timeline

- **Phase 1** (2-3 hours): Basic PQS detection and status display
- **Phase 2** (2-3 hours): Full cache integration and UI enhancements  
- **Phase 3** (1-2 hours): Polish and testing

## Potential Challenges & Solutions

### Challenge: PQS Plugin Not Installed
**Solution**: Graceful degradation - function normally without PQS integration

### Challenge: Cache Sync Issues
**Solution**: Use PQS events for cache invalidation coordination

### Challenge: Plugin Name Matching
**Solution**: Multiple matching strategies (slug variations, fuzzy matching)

## Code Quality Considerations

- Maintain backward compatibility
- Add proper error handling
- Follow WordPress coding standards
- Add comprehensive documentation
- Include fallback for when PQS isn't available