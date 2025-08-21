<?php
/**
 * AJAX Handler for KISS Smart Batch Installer v2
 * 
 * Handles AJAX requests for plugin operations.
 * Will be fully implemented in Phase 2.
 */

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
    
    /**
     * Initialize AJAX handlers
     */
    public function init(): void
    {
        add_action('wp_ajax_kiss_sbi_v2_check_plugin', [$this, 'checkPlugin']);
        add_action('wp_ajax_kiss_sbi_v2_install_plugin', [$this, 'installPlugin']);
        add_action('wp_ajax_kiss_sbi_v2_activate_plugin', [$this, 'activatePlugin']);
    }
    
    /**
     * Handle check plugin AJAX request
     */
    public function checkPlugin(): void
    {
        check_ajax_referer('kiss_sbi_v2_nonce', 'nonce');
        
        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // For Phase 1, return not implemented
        wp_send_json_error('Check plugin functionality will be available in Phase 2');
    }
    
    /**
     * Handle install plugin AJAX request
     */
    public function installPlugin(): void
    {
        check_ajax_referer('kiss_sbi_v2_nonce', 'nonce');
        
        if (!current_user_can('install_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // For Phase 1, return not implemented
        wp_send_json_error('Install plugin functionality will be available in Phase 2');
    }
    
    /**
     * Handle activate plugin AJAX request
     */
    public function activatePlugin(): void
    {
        check_ajax_referer('kiss_sbi_v2_nonce', 'nonce');
        
        if (!current_user_can('activate_plugins')) {
            wp_send_json_error('Insufficient permissions');
        }
        
        // For Phase 1, return not implemented
        wp_send_json_error('Activate plugin functionality will be available in Phase 2');
    }
}
