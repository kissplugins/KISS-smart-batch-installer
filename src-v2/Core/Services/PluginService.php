<?php
/**
 * Plugin Service for KISS Smart Batch Installer v2
 * 
 * Central service for managing plugin states and operations.
 * Will be fully implemented in Phase 2.
 */

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

    /**
     * Get plugin instance for repository
     */
    public function getPlugin(string $repositoryName): Plugin
    {
        if (isset($this->plugins[$repositoryName])) {
            return $this->plugins[$repositoryName];
        }
        
        // For Phase 1, create basic plugin instance
        $plugin = new Plugin($repositoryName);
        $plugin->setState(Plugin::STATE_UNKNOWN);
        
        $this->plugins[$repositoryName] = $plugin;
        return $plugin;
    }
    
    /**
     * Check plugin status
     */
    public function checkPluginStatus(string $repositoryName): Plugin
    {
        $plugin = $this->getPlugin($repositoryName);
        
        // For Phase 1, just return the plugin as-is
        // Full implementation in Phase 2
        return $plugin;
    }
}
