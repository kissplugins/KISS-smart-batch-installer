<?php
/**
 * Plugins Controller for KISS Smart Batch Installer v2
 * 
 * Handles the main plugins page rendering and logic.
 */

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
    
    /**
     * Render the main plugins page
     */
    public function render(): void
    {
        $github_org = get_option('kiss_sbi_github_org', '');
        
        if (empty($github_org)) {
            $this->renderEmptyState();
            return;
        }
        
        // For Phase 1, show basic interface
        $this->renderBasicInterface($github_org);
    }
    
    /**
     * Render empty state when no GitHub org is configured
     */
    private function renderEmptyState(): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . __('GitHub Repositories (v2)', 'kiss-smart-batch-installer') . '</h1>';
        echo '<div class="notice notice-info"><p>';
        printf(
            __('Welcome to the new v2 interface! Please <a href="%s">configure your GitHub organization</a> to get started.', 'kiss-smart-batch-installer'),
            admin_url('admin.php?page=kiss-smart-batch-installer-settings')
        );
        echo '</p></div>';
        echo '</div>';
    }
    
    /**
     * Render basic interface for Phase 1
     */
    private function renderBasicInterface(string $github_org): void
    {
        echo '<div class="wrap">';
        echo '<h1>' . __('GitHub Repositories (v2)', 'kiss-smart-batch-installer') . '</h1>';
        
        echo '<div class="notice notice-success"><p>';
        echo '<strong>' . __('Phase 1 Complete!', 'kiss-smart-batch-installer') . '</strong> ';
        echo __('The new v2 architecture is now active. Full functionality will be available in Phase 2.', 'kiss-smart-batch-installer');
        echo '</p></div>';
        
        echo '<p class="description">';
        printf(__('Configured for GitHub organization: <strong>%s</strong>', 'kiss-smart-batch-installer'), esc_html($github_org));
        echo '</p>';
        
        echo '<p>';
        echo '<a href="' . esc_url(admin_url('admin.php?page=kiss-smart-batch-installer-settings')) . '" class="button">';
        echo __('Settings', 'kiss-smart-batch-installer');
        echo '</a> ';
        echo '<a href="' . esc_url(admin_url('plugins.php?page=kiss-smart-batch-installer')) . '" class="button">';
        echo __('Switch to v1 Interface', 'kiss-smart-batch-installer');
        echo '</a>';
        echo '</p>';
        
        echo '</div>';
    }
}
