# Developer Guide: PQS Keyboard Shortcut Integration

https://github.com/kissplugins/KISS-Plugin-Quick-Search

This guide explains how to integrate your WordPress plugin management tool with the **Plugin Quick Search (PQS)** keyboard shortcut system and cache infrastructure.

## Overview

The PQS system provides a unified **Cmd/Ctrl+Shift+P** keyboard shortcut that can be shared across multiple plugin management tools. This creates a consistent user experience where the same keyboard combination can intelligently route users to different plugin-related interfaces.

## Integration Benefits

- **Unified UX**: Same keyboard shortcut across all plugin management tools
- **Cache Sharing**: Leverage PQS's high-performance localStorage cache
- **Smart Routing**: Intelligent navigation based on context and user preferences
- **Performance**: Avoid duplicate plugin scanning and caching
- **Consistency**: Standardized keyboard shortcuts across the WordPress ecosystem

## Quick Start Integration

### Step 1: Create Keyboard Shortcut Script

Create a JavaScript file (e.g., `assets/pqs-keyboard-integration.js`):

```javascript
/**
 * PQS Keyboard Shortcut Integration
 * Integrates your plugin with the PQS keyboard shortcut system
 */

jQuery(document).ready(function($) {
    'use strict';

    function initPQSKeyboardIntegration() {
        document.addEventListener('keydown', function(e) {
            // Check for Cmd/Ctrl+Shift+P
            if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key === 'P') {
                e.preventDefault();
                
                // Check if we're already on your plugin's page
                const currentUrl = window.location.href;
                if (currentUrl.includes('page=your-plugin-page')) {
                    console.log('Your Plugin: Already on plugin page');
                    return;
                }
                
                // Navigate to your plugin page
                const pluginUrl = typeof yourPluginAjax !== 'undefined' && yourPluginAjax.pluginUrl 
                    ? yourPluginAjax.pluginUrl 
                    : '/wp-admin/admin.php?page=your-plugin-page';
                
                window.location.href = pluginUrl;
                
                console.log('Your Plugin: Keyboard shortcut triggered');
            }
        });
        
        console.log('Your Plugin: PQS keyboard integration initialized');
    }

    // Initialize integration
    initPQSKeyboardIntegration();
});
```

### Step 2: Enqueue Script in PHP

In your main plugin class or admin interface:

```php
public function enqueueAssets($hook) {
    // Always enqueue keyboard shortcuts on admin pages
    wp_enqueue_script(
        'your-plugin-pqs-integration',
        YOUR_PLUGIN_URL . 'assets/pqs-keyboard-integration.js',
        ['jquery'],
        YOUR_PLUGIN_VERSION,
        true
    );

    // Localize script with your plugin URL
    wp_localize_script('your-plugin-pqs-integration', 'yourPluginAjax', [
        'pluginUrl' => admin_url('admin.php?page=your-plugin-page'),
        'debug' => (bool) apply_filters('your_plugin_debug', true)
    ]);
    
    // Continue with page-specific assets...
}
```

### Step 3: Add Admin Hook

Ensure the script loads on all admin pages:

```php
public function __construct() {
    add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
    // ... other hooks
}
```

## Advanced Integration: Smart Routing

For more sophisticated routing between multiple plugin tools, implement smart routing logic:

```javascript
function initSmartPQSRouting() {
    document.addEventListener('keydown', function(e) {
        if ((e.metaKey || e.ctrlKey) && e.shiftKey && e.key === 'P') {
            e.preventDefault();
            
            // Determine best destination based on context
            const destination = determineOptimalDestination();
            
            if (destination && destination !== window.location.href) {
                window.location.href = destination;
                console.log('PQS Smart Routing: Navigating to', destination);
            }
        }
    });
}

function determineOptimalDestination() {
    const currentUrl = window.location.href;
    
    // Priority 1: If on plugins.php, go to PQS search
    if (currentUrl.includes('plugins.php') && !currentUrl.includes('page=')) {
        return '/wp-admin/plugins.php'; // PQS will handle the modal
    }
    
    // Priority 2: If PQS cache is available, prefer tools that use it
    if (typeof window.pqsCacheStatus === 'function' && window.pqsCacheStatus() === 'fresh') {
        // Route to cache-enabled tools first
        return '/wp-admin/plugins.php?page=kiss-smart-batch-installer';
    }
    
    // Priority 3: Default to your plugin
    return '/wp-admin/admin.php?page=your-plugin-page';
}
```

## PQS Cache Integration

### Reading PQS Cache Data

```javascript
function usePQSCache() {
    try {
        // Check if PQS cache is available
        if (typeof window.pqsCacheStatus === 'function') {
            const status = window.pqsCacheStatus();
            
            if (status === 'fresh') {
                // Read cached plugin data
                const raw = localStorage.getItem('pqs_plugin_cache') || '[]';
                const pluginData = JSON.parse(raw);
                
                console.log(`Using PQS cache with ${pluginData.length} plugins`);
                return pluginData;
            }
        }
        
        // Fallback: try direct localStorage access
        const raw = localStorage.getItem('pqs_plugin_cache') || '[]';
        const pluginData = JSON.parse(raw);
        
        if (Array.isArray(pluginData) && pluginData.length > 0) {
            console.log('Using PQS cache via localStorage fallback');
            return pluginData;
        }
    } catch (error) {
        console.warn('Failed to read PQS cache:', error);
    }
    
    return null;
}
```

### Listening for Cache Updates

```javascript
function setupPQSCacheListeners() {
    // Listen for cache rebuilds
    document.addEventListener('pqs-cache-rebuilt', function(event) {
        console.log('PQS cache rebuilt, refreshing plugin data');
        const pluginCount = event.detail.pluginCount;
        refreshYourPluginData();
    });
    
    // Listen for cache status changes
    document.addEventListener('pqs-cache-status-changed', function(event) {
        const status = event.detail.status;
        console.log('PQS cache status changed to:', status);
        
        if (status === 'fresh') {
            // Cache is now available
            usePQSCache();
        }
    });
}
```

## Plugin Data Structure

The PQS cache provides the following data structure for each plugin:

```javascript
{
    name: "Plugin Name",                    // Display name
    nameLower: "plugin name",               // Lowercase for searching
    description: "Plugin description text", // Full description
    descriptionLower: "plugin description", // Lowercase for searching
    version: "1.2.3",                      // Version string
    isActive: true,                         // Activation status
    settingsUrl: "admin.php?page=settings", // Settings page URL (null if none)
    rowIndex: 5,                            // Original DOM position
    wordCount: 2,                           // Words in name (for scoring)
    hasForIn: false                         // Contains "for" or "-" (for scoring)
}
```

## Best Practices

### 1. Graceful Degradation

Always provide fallbacks when PQS is not available:

```javascript
function initWithFallback() {
    // Try PQS integration first
    if (typeof window.pqsCacheStatus === 'function') {
        initPQSIntegration();
    } else {
        // Fallback to standalone functionality
        initStandaloneMode();
    }
}
```

### 2. Avoid Conflicts

Check for existing keyboard handlers:

```javascript
function safeKeyboardInit() {
    // Check if PQS is already handling this shortcut
    if (window.pqsKeyboardHandlerActive) {
        console.log('PQS keyboard handler already active, skipping duplicate');
        return;
    }
    
    // Mark as active to prevent conflicts
    window.yourPluginKeyboardActive = true;
    initKeyboardShortcuts();
}
```

### 3. Performance Considerations

```javascript
// Debounce cache reads
let cacheReadTimeout;
function debouncedCacheRead() {
    clearTimeout(cacheReadTimeout);
    cacheReadTimeout = setTimeout(() => {
        const data = usePQSCache();
        if (data) processPluginData(data);
    }, 100);
}
```

## Testing Your Integration

### 1. Basic Functionality Test

```javascript
// Test keyboard shortcut
console.log('Testing keyboard shortcut integration...');
document.dispatchEvent(new KeyboardEvent('keydown', {
    key: 'P',
    ctrlKey: true,
    shiftKey: true,
    bubbles: true
}));
```

### 2. Cache Integration Test

```javascript
// Test cache access
function testPQSCacheIntegration() {
    const cacheData = usePQSCache();
    
    if (cacheData && cacheData.length > 0) {
        console.log('✅ PQS cache integration working');
        console.log(`Found ${cacheData.length} cached plugins`);
    } else {
        console.log('❌ PQS cache not available or empty');
    }
}
```

## Example Implementation

See the **KISS Smart Batch Installer** plugin for a complete implementation example:

- **File**: `KISS-smart-batch-installer/assets/keyboard-shortcuts.js`
- **Integration**: `KISS-smart-batch-installer/assets/pqs-integration.js`
- **PHP Setup**: `KISS-smart-batch-installer/src/Admin/AdminInterface.php`

## Support and Documentation

- **PQS Cache API**: See `CACHE-API.md` for complete cache integration documentation
- **Events**: PQS fires `pqs-cache-rebuilt` and `pqs-cache-status-changed` events
- **Global Functions**: `window.pqsCacheStatus()`, `window.pqsRebuildCache()`, `window.pqsClearCache()`

## Contributing

When implementing PQS integration:

1. Follow the naming conventions shown in this guide
2. Include proper error handling and fallbacks
3. Add console logging for debugging (respect debug flags)
4. Test with and without PQS plugin active
5. Document your integration in your plugin's README

This integration system creates a unified ecosystem of WordPress plugin management tools that work seamlessly together while maintaining individual plugin functionality.

## Advanced Features

### Multi-Tool Routing Table

For complex setups with multiple plugin management tools, implement a routing table:

```javascript
const PQS_ROUTING_TABLE = {
    // Route based on current page context
    'plugins.php': {
        default: '/wp-admin/plugins.php', // Triggers PQS modal
        withParams: '/wp-admin/plugins.php?page=kiss-smart-batch-installer'
    },
    'plugin-install.php': '/wp-admin/plugins.php?page=kiss-smart-batch-installer',
    'admin.php': {
        'page=kiss-smart-batch-installer': '/wp-admin/plugins.php', // Back to PQS
        'default': '/wp-admin/plugins.php?page=kiss-smart-batch-installer'
    }
};

function getOptimalRoute() {
    const url = new URL(window.location.href);
    const pathname = url.pathname.split('/').pop();
    const searchParams = url.searchParams.toString();

    const routes = PQS_ROUTING_TABLE[pathname];
    if (!routes) return PQS_ROUTING_TABLE.default;

    if (typeof routes === 'string') return routes;

    // Check for specific page parameters
    for (const [key, route] of Object.entries(routes)) {
        if (key !== 'default' && searchParams.includes(key)) {
            return route;
        }
    }

    return routes.default || routes;
}
```

### User Preference System

Allow users to customize keyboard shortcut behavior:

```javascript
const PQS_USER_PREFS = {
    load() {
        try {
            return JSON.parse(localStorage.getItem('pqs_user_preferences') || '{}');
        } catch (e) {
            return {};
        }
    },

    save(prefs) {
        try {
            localStorage.setItem('pqs_user_preferences', JSON.stringify(prefs));
        } catch (e) {
            console.warn('Failed to save PQS preferences');
        }
    },

    getPreferredDestination() {
        const prefs = this.load();
        return prefs.keyboardShortcutDestination || 'auto';
    }
};

function applyUserPreferences() {
    const preferred = PQS_USER_PREFS.getPreferredDestination();

    switch (preferred) {
        case 'pqs-modal':
            return '/wp-admin/plugins.php';
        case 'batch-installer':
            return '/wp-admin/plugins.php?page=kiss-smart-batch-installer';
        case 'your-plugin':
            return '/wp-admin/admin.php?page=your-plugin-page';
        default:
            return getOptimalRoute(); // Auto-routing
    }
}
```

### Plugin Registration System

Register your plugin with the PQS ecosystem:

```javascript
// Register your plugin with PQS ecosystem
function registerWithPQSEcosystem() {
    if (!window.PQS_ECOSYSTEM) {
        window.PQS_ECOSYSTEM = {
            plugins: new Map(),
            register: function(id, config) {
                this.plugins.set(id, config);
                console.log(`PQS Ecosystem: Registered ${id}`);
            },
            getRegistered: function() {
                return Array.from(this.plugins.entries());
            }
        };
    }

    window.PQS_ECOSYSTEM.register('your-plugin-id', {
        name: 'Your Plugin Name',
        url: '/wp-admin/admin.php?page=your-plugin-page',
        priority: 2, // 1 = highest priority for routing
        capabilities: ['plugin-management', 'batch-operations'],
        cacheCompatible: true,
        keyboardShortcut: 'Cmd/Ctrl+Shift+P'
    });
}
```

## Troubleshooting

### Common Issues

1. **Keyboard shortcut not working**
   ```javascript
   // Debug keyboard events
   document.addEventListener('keydown', function(e) {
       if ((e.metaKey || e.ctrlKey) && e.shiftKey) {
           console.log('Key combo detected:', e.key, 'Meta:', e.metaKey, 'Ctrl:', e.ctrlKey);
       }
   });
   ```

2. **Cache not loading**
   ```javascript
   // Debug cache status
   function debugPQSCache() {
       console.log('PQS Cache Status:', typeof window.pqsCacheStatus === 'function' ? window.pqsCacheStatus() : 'Function not available');
       console.log('LocalStorage cache:', localStorage.getItem('pqs_plugin_cache') ? 'Present' : 'Missing');
       console.log('Cache metadata:', localStorage.getItem('pqs_cache_meta'));
   }
   ```

3. **Script conflicts**
   ```javascript
   // Check for conflicts
   function checkForConflicts() {
       const handlers = [];
       if (window.pqsKeyboardHandlerActive) handlers.push('PQS');
       if (window.kissSbiKeyboardActive) handlers.push('Smart Batch Installer');
       if (window.yourPluginKeyboardActive) handlers.push('Your Plugin');

       if (handlers.length > 1) {
           console.warn('Multiple keyboard handlers detected:', handlers);
       }
   }
   ```

## Integration Checklist

- [ ] Keyboard shortcut script created and enqueued
- [ ] Script loads on all admin pages (not just plugin-specific pages)
- [ ] Proper URL localization in PHP
- [ ] PQS cache integration implemented
- [ ] Event listeners for cache updates added
- [ ] Graceful fallback when PQS not available
- [ ] Console logging for debugging
- [ ] Conflict prevention measures
- [ ] User preference support (optional)
- [ ] Documentation updated
- [ ] Testing completed

## Version Compatibility

- **PQS Version**: 1.1.0+
- **WordPress**: 5.0+
- **PHP**: 7.4+
- **Browser**: Modern browsers with localStorage support

## License and Attribution

When using this integration system, please:

- Maintain attribution to the PQS project
- Follow the same GPL v2+ licensing
- Contribute improvements back to the ecosystem
- Document your integration for other developers
