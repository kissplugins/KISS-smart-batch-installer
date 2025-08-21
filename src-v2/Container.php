<?php
/**
 * Dependency Injection Container for KISS Smart Batch Installer v2
 * 
 * Simple container that manages service instantiation and dependencies.
 */

namespace KissSmartBatchInstaller\V2;

class Container
{
    private array $services = [];
    private array $singletons = [];
    
    /**
     * Get a service instance (singleton pattern)
     */
    public function get(string $service)
    {
        if (isset($this->singletons[$service])) {
            return $this->singletons[$service];
        }
        
        $instance = $this->create($service);
        $this->singletons[$service] = $instance;
        
        return $instance;
    }
    
    /**
     * Create service instances with their dependencies
     */
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
    
    /**
     * Create GitHub service using existing v1 logic
     */
    private function createGitHubService()
    {
        // For now, create a wrapper around the existing GitHubScraper
        // This will be fully implemented in Phase 2
        return new Core\Services\GitHubService();
    }
}
