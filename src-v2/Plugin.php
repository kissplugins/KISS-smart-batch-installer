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
