<?php
/**
 * Plugin Name: KISS Smart Batch Installer
 * Plugin URI: https://github.com/your-org/github-org-repo-manager
 * Description: Manage and batch install WordPress plugins from your GitHub organization's most recently updated repositories.
 * Version: 1.0.2
 * Author: KISS Plugins
 * Author URI: https://github.com/kissplugins?tab=repositories
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: kiss-smart-batch-installer
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.3
 * Requires PHP: 7.4
 * Network: true
 */

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}

// Define plugin constants
define('KISS_SBI_VERSION', '1.0.1');
define('KISS_SBI_PLUGIN_FILE', __FILE__);
define('KISS_SBI_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('KISS_SBI_PLUGIN_URL', plugin_dir_url(__FILE__));
define('KISS_SBI_PLUGIN_BASENAME', plugin_basename(__FILE__));

/**
 * PSR-4 Autoloader
 */
spl_autoload_register(function ($class) {
    $prefix = 'KissSmartBatchInstaller\\';
    $base_dir = KISS_SBI_PLUGIN_DIR . 'src/';

    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relative_class = substr($class, $len);
    $file = $base_dir . str_replace('\\', '/', $relative_class) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

/**
 * Main Plugin Class
 */
class KissSmartBatchInstaller
{
    private static $instance = null;

    public static function getInstance()
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct()
    {
        $this->init();
    }

    private function init()
    {
        // Initialize plugin
        add_action('plugins_loaded', [$this, 'loadTextDomain']);
        add_action('init', [$this, 'initializePlugin']);

        // Add action links (Settings | Installer) on the Plugins list row
        add_filter('plugin_action_links_' . KISS_SBI_PLUGIN_BASENAME, [$this, 'pluginActionLinks']);

        // Activation/Deactivation hooks
        register_activation_hook(KISS_SBI_PLUGIN_FILE, [$this, 'activate']);
        register_deactivation_hook(KISS_SBI_PLUGIN_FILE, [$this, 'deactivate']);
    }

    public function loadTextDomain()
    {
        load_plugin_textdomain('kiss-smart-batch-installer', false, dirname(KISS_SBI_PLUGIN_BASENAME) . '/languages');
    }

    public function initializePlugin()
    {
        if (is_admin()) {
            new \KissSmartBatchInstaller\Admin\AdminInterface();
        }

        new \KissSmartBatchInstaller\Core\GitHubScraper();
        new \KissSmartBatchInstaller\Core\PluginInstaller();
        new \KissSmartBatchInstaller\Core\SelfUpdater();
    }

    public function activate()
    {
        // Set default options
        add_option('kiss_sbi_github_org', '');
        add_option('kiss_sbi_cache_duration', 3600); // 1 hour
        add_option('kiss_sbi_repo_limit', 15);

        // Create plugin tables if needed (none for v1.0)
        flush_rewrite_rules();
    }

    public function deactivate()
    {
        // Clean up transients
        delete_transient('kiss_sbi_repositories_cache');
        flush_rewrite_rules();
    }

    /**
     * Add Settings and Installer links on the Plugins screen
     */
    public function pluginActionLinks($links)
    {
        $settings_url = admin_url('admin.php?page=kiss-smart-batch-installer-settings');
        $installer_url = admin_url('plugins.php?page=kiss-smart-batch-installer');

        $custom_links = [
            '<a href="' . esc_url($settings_url) . '">' . esc_html__('Settings', 'kiss-smart-batch-installer') . '</a>',
            '<a href="' . esc_url($installer_url) . '">' . esc_html__('Installer', 'kiss-smart-batch-installer') . '</a>'
        ];

        // Prepend our links so they appear first
        return array_merge($custom_links, (array) $links);
    }
}

// Initialize the plugin
KissSmartBatchInstaller::getInstance();