<?php
/**
 * Main Plugin Class for KISS Smart Batch Installer v2
 * 
 * Handles initialization, admin pages, asset enqueuing, and AJAX setup
 * for the v2 architecture.
 */

namespace KissSmartBatchInstaller\V2;

class Plugin 
{
    private $container;
    
    public function __construct()
    {
        $this->container = new Container();
    }
    
    /**
     * Initialize the v2 plugin
     */
    public function init(): void
    {
        add_action('admin_menu', [$this, 'addAdminPages']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        
        // Initialize AJAX handlers
        $this->container->get('AjaxHandler')->init();
    }
    
    /**
     * Add admin menu pages
     */
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
    
    /**
     * Render the main plugins page
     */
    public function renderPluginsPage(): void
    {
        $controller = $this->container->get('PluginsController');
        $controller->render();
    }
    
    /**
     * Enqueue CSS and JavaScript assets
     */
    public function enqueueAssets($hook): void
    {
        if (strpos($hook, 'kiss-smart-batch-installer-v2') === false) {
            return;
        }
        
        wp_enqueue_script(
            'kiss-sbi-v2-admin',
            KISS_SBI_V2_ASSETS_URL . 'PluginManager.js',
            ['jquery'],
            KISS_SBI_VERSION,
            true
        );
        
        wp_enqueue_style(
            'kiss-sbi-v2-admin',
            KISS_SBI_V2_ASSETS_URL . 'admin-v2.css',
            [],
            KISS_SBI_VERSION
        );
        
        wp_localize_script('kiss-sbi-v2-admin', 'kissSbiV2Ajax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kiss_sbi_v2_nonce'),
            'strings' => [
                'installing' => __('Installing...', 'kiss-smart-batch-installer'),
                'checking' => __('Checking...', 'kiss-smart-batch-installer'),
                'activating' => __('Activating...', 'kiss-smart-batch-installer'),
                'confirmInstall' => __('Install plugin "%s"?', 'kiss-smart-batch-installer'),
                'confirmBulkInstall' => __('Install %d selected plugins?', 'kiss-smart-batch-installer'),
                'selectPlugins' => __('Please select at least one repository to install.', 'kiss-smart-batch-installer'),
                'installSuccess' => __('Plugin "%s" installed successfully.', 'kiss-smart-batch-installer'),
                'installError' => __('Failed to install %s: %s', 'kiss-smart-batch-installer'),
                'checkError' => __('Error checking %s: %s', 'kiss-smart-batch-installer'),
            ]
        ]);
    }
    
    /**
     * Get the dependency injection container
     */
    public function getContainer()
    {
        return $this->container;
    }
}
