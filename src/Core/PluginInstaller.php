<?php

namespace KissSmartBatchInstaller\Core;

/**
 * Plugin Installer
 *
 * Handles downloading and installing WordPress plugins from GitHub repositories.
 */
class PluginInstaller
{
    private $org_name;

    public function __construct()
    {
        $this->org_name = get_option('kiss_sbi_github_org', '');

        // AJAX handlers
        add_action('wp_ajax_kiss_sbi_install_plugin', [$this, 'ajaxInstallPlugin']);
        add_action('wp_ajax_kiss_sbi_batch_install', [$this, 'ajaxBatchInstall']);
        add_action('wp_ajax_kiss_sbi_activate_plugin', [$this, 'ajaxActivatePlugin']);
        add_action('wp_ajax_kiss_sbi_check_installed', [$this, 'ajaxCheckInstalled']);
        add_action('wp_ajax_kiss_sbi_refresh_repos', [$this, 'ajaxRefreshRepos']);
    }

    /**
     * Install a single plugin from GitHub using WordPress core Plugin_Upgrader
     */
    public function installPlugin($repo_name, $activate = false)
    {
        if (empty($this->org_name) || empty($repo_name)) {
            return new \WP_Error('invalid_params', __('Invalid parameters provided.', 'kiss-smart-batch-installer'));
        }

        $plugin_slug = sanitize_title($repo_name);
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        // Pre-check: prevent overwriting an existing directory
        if (is_dir($plugin_dir)) {
            return new \WP_Error('plugin_exists', sprintf(__('Plugin directory %s already exists.', 'kiss-smart-batch-installer'), $plugin_slug));
        }

        // Build main branch ZIP URL
        $zipUrl = sprintf('https://github.com/%s/%s/archive/refs/heads/main.zip', urlencode($this->org_name), urlencode($repo_name));

        // Include upgrader classes
        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        // Capture detailed upgrader messages
        $messages = [];
        add_action('upgrader_process_complete', function($upg, $hook_extra) use (&$messages) {
            $messages[] = 'Upgrader complete: ' . (is_array($hook_extra) ? json_encode($hook_extra) : (string) $hook_extra);
        }, 10, 2);
        add_action('upgrader_source_selection', function($source) use (&$messages) {
            $messages[] = 'Source selected: ' . (string) $source; return $source;
        }, 9, 1);
        add_action('upgrader_post_install', function($true, $hook_extra, $result) use (&$messages) {
            $messages[] = 'Post-install: ' . json_encode(['result' => $result, 'extra' => $hook_extra]); return $true;
        }, 10, 3);

        $skin = new \WP_Ajax_Upgrader_Skin();
        $upgrader = new \Plugin_Upgrader($skin);

        // Ensure destination can be overwritten if needed and cleaned appropriately (we pre-checked above)
        add_filter('upgrader_package_options', function($options){
            $options['abort_if_destination_exists'] = false;
            $options['clear_destination'] = false;
            return $options;
        });

        // Rename the extracted GitHub directory (repo-main/) to the sanitized plugin slug
        $renameFilter = function ($source, $remote_source, $upgrader_obj, $hook_extra) use ($plugin_slug) {
            if (empty($source) || !is_dir($source)) return $source;
            // Desired path inside plugins dir
            $desired = trailingslashit(WP_PLUGIN_DIR) . $plugin_slug;
            $desired = trailingslashit($desired);
            $target = trailingslashit(dirname($source)) . basename($desired);
            if ($source !== $target) {
                // Remove target if exists (very unlikely as we pre-checked)
                if (is_dir($target)) {
                    // best-effort cleanup
                    @rmdir($target);
                }
                @rename($source, $target);
                return $target;
            }
            return $source;
        };
        add_filter('upgrader_source_selection', $renameFilter, 10, 4);

        // Perform install
        $result = $upgrader->install($zipUrl);

        // Remove our temporary filter
        remove_filter('upgrader_source_selection', $renameFilter, 10);

        if (is_wp_error($result)) {
            return new \WP_Error($result->get_error_code() ?: 'install_failed', $result->get_error_message() . (!empty($messages) ? ' | Logs: ' . implode(' • ', array_map('sanitize_text_field', $messages)) : ''));
        }
        if (!$result) {
            return new \WP_Error('install_failed', __('Installation failed.', 'kiss-smart-batch-installer') . (!empty($messages) ? ' | Logs: ' . implode(' • ', array_map('sanitize_text_field', $messages)) : ''));
        }

        // Post-install: locate main plugin file
        $plugin_file_rel = null;
        $main_file = $this->findMainPluginFile($plugin_dir, $repo_name);
        if ($main_file) {
            $plugin_file_rel = $plugin_slug . '/' . $main_file;
        } else {
            // Fallback: try to find using get_plugins() by directory
            if (!function_exists('get_plugins')) {
                require_once ABSPATH . 'wp-admin/includes/plugin.php';
            }
            $all = function_exists('get_plugins') ? get_plugins() : [];
            foreach ($all as $file => $data) {
                $parts = explode('/', $file, 2);
                if (strtolower($parts[0] ?? '') === strtolower($plugin_slug)) { $plugin_file_rel = $file; break; }
            }
            if (!$plugin_file_rel) {
                // Could not find a valid main file
                return new \WP_Error('no_plugin_file', __('Could not find main plugin file after installation.', 'kiss-smart-batch-installer'));
            }
        }

        // Activate if requested
        if ($activate) {
            $activate_result = $this->activatePlugin($plugin_file_rel);
            if (is_wp_error($activate_result)) {
                return $activate_result;
            }
        }

        return [
            'success' => true,
            'plugin_dir' => $plugin_dir,
            'plugin_file' => $plugin_file_rel,
            'activated' => (bool) $activate,
            'logs' => $messages,
        ];
    }

    // Note: Legacy helper methods for manual download/extract have been removed in favor of WordPress core Upgrader usage.

    /**
     * Find the main plugin file in the extracted directory
     */
    private function findMainPluginFile($plugin_dir, $repo_name)
    {
        $possible_files = [
            $repo_name . '.php',
            'index.php',
            $repo_name . '-plugin.php',
            'plugin.php',
            'main.php'
        ];

        foreach ($possible_files as $filename) {
            $file_path = $plugin_dir . '/' . $filename;

            if (file_exists($file_path)) {
                $file_content = file_get_contents($file_path);

                // Ensure this is actually a PHP file with proper opening tag
                if (!preg_match('/^\s*<\?php/i', $file_content)) {
                    continue;
                }

                // Check for WordPress plugin header in a PHP comment block
                $header_content = substr($file_content, 0, 8192);
                if (preg_match('/\/\*.*?Plugin Name:\s*(.+?).*?\*\//is', $header_content)) {
                    return $filename;
                }
            }
        }

        // Fallback: look for any PHP file with plugin header
        $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($plugin_dir));

        foreach ($iterator as $file) {
            if ($file->getExtension() === 'php') {
                $file_content = file_get_contents($file->getPathname());

                // Ensure this is actually a PHP file with proper opening tag
                if (!preg_match('/^\s*<\?php/i', $file_content)) {
                    continue;
                }

                // Check for WordPress plugin header in a PHP comment block
                $header_content = substr($file_content, 0, 8192);
                if (preg_match('/\/\*.*?Plugin Name:\s*(.+?).*?\*\//is', $header_content)) {
                    return str_replace($plugin_dir . '/', '', $file->getPathname());
                }
            }
        }

        return null;
    }

    /**
     * Activate a plugin
     */
    private function activatePlugin($plugin_file)
    {
        if (!function_exists('activate_plugin')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $result = activate_plugin($plugin_file);

        if (is_wp_error($result)) {
            return new \WP_Error('activation_failed', sprintf(__('Failed to activate plugin: %s', 'kiss-smart-batch-installer'), $result->get_error_message()));
        }

        return true;
    }

    // Note: Legacy removeDirectory helper removed; Upgrader handles extraction and cleanup.

    /**
     * AJAX handler for single plugin installation
     */
    public function ajaxInstallPlugin()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');
        $activate = (bool) ($_POST['activate'] ?? false);

        if (empty($repo_name)) {
            wp_send_json_error(__('Repository name is required.', 'kiss-smart-batch-installer'));
        }

        $result = $this->installPlugin($repo_name, $activate);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success($result);
    }

    /**
     * AJAX handler for batch plugin installation
     */
    public function ajaxBatchInstall()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $repo_names = $_POST['repo_names'] ?? [];
        $activate = (bool) ($_POST['activate'] ?? false);

        if (!is_array($repo_names) || empty($repo_names)) {
            wp_send_json_error(__('No repositories selected.', 'kiss-smart-batch-installer'));
        }

        $results = [];
        $success_count = 0;
        $error_count = 0;

        foreach ($repo_names as $repo_name) {
            $repo_name = sanitize_text_field($repo_name);
            $result = $this->installPlugin($repo_name, $activate);

            if (is_wp_error($result)) {
                $results[] = [
                    'repo_name' => $repo_name,
                    'success' => false,
                    'error' => $result->get_error_message()
                ];
                $error_count++;
            } else {
                $results[] = [
                    'repo_name' => $repo_name,
                    'success' => true,
                    'data' => $result
                ];
                $success_count++;
            }
        }

        wp_send_json_success([
            'results' => $results,
            'summary' => [
                'total' => count($repo_names),
                'success' => $success_count,
                'errors' => $error_count
            ]
        ]);

        }


	    /**
	     * AJAX: Refresh repositories (clear cache and return success)
	     */
	    public function ajaxRefreshRepos()
	    {
	        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');
	        if (!current_user_can('install_plugins')) {
	            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
	        }
	        delete_transient('kiss_sbi_repositories_cache');
	        wp_send_json_success(true);


    }

    /**
     * Check if a plugin is already installed
     */
    public function isPluginInstalled($repo_name)
    {
        // Ensure WordPress plugin functions are available during AJAX requests
        if (!function_exists('is_plugin_active') || !function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        $target_slug = sanitize_title($repo_name);

        // First, use WordPress' plugin registry for a robust match
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugins = function_exists('get_plugins') ? get_plugins() : [];
        foreach ($plugins as $plugin_file_rel => $data) {
            $parts = explode('/', $plugin_file_rel, 2);
            $dir = $parts[0];
            if (strtolower($dir) === strtolower($target_slug)) {
                $plugin_path = $plugin_file_rel; // e.g. my-plugin/my-plugin.php
                $active = is_plugin_active($plugin_path) || (function_exists('is_plugin_active_for_network') && is_plugin_active_for_network($plugin_path));
                return [
                    'installed' => true,
                    'plugin_file' => $plugin_path,
                    'active' => $active,
                    'plugin_data' => $data
                ];
            }
        }

        // Fallback: find directory by case-insensitive scan and detect main file
        $actual_dir = null;
        $entries = @scandir(WP_PLUGIN_DIR) ?: [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') { continue; }
            $full = WP_PLUGIN_DIR . '/' . $entry;
            if (is_dir($full) && strtolower($entry) === strtolower($target_slug)) {
                $actual_dir = $entry; // preserve real casing
                break;
            }
        }

        if (!$actual_dir) {
            return false;
        }

        $plugin_dir = WP_PLUGIN_DIR . '/' . $actual_dir;

        // Find the main plugin file
        $plugin_file = $this->findMainPluginFile($plugin_dir, $repo_name);
        if (!$plugin_file) {
            return false;
        }

        $plugin_path = $actual_dir . '/' . $plugin_file;

        $active = is_plugin_active($plugin_path);
        if (!$active && function_exists('is_plugin_active_for_network')) {
            $active = is_plugin_active_for_network($plugin_path);
        }

        return [
            'installed' => true,
            'plugin_file' => $plugin_path,
            'active' => $active,
            'plugin_data' => get_plugin_data($plugin_dir . '/' . $plugin_file)
        ];
    }

    /**
     * AJAX handler for checking if plugin is installed
     */
    public function ajaxCheckInstalled()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('install_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $repo_name = sanitize_text_field($_POST['repo_name'] ?? '');

        if (empty($repo_name)) {
            wp_send_json_error(__('Repository name is required.', 'kiss-smart-batch-installer'));
        }

        $result = $this->isPluginInstalled($repo_name);

        wp_send_json_success([
            'installed' => $result !== false,
            'data' => $result
        ]);
    }

    /**
     * AJAX handler for activating a plugin
     */
    public function ajaxActivatePlugin()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');

        if (!current_user_can('activate_plugins')) {
            wp_die(__('Insufficient permissions.', 'kiss-smart-batch-installer'));
        }

        $plugin_file = sanitize_text_field($_POST['plugin_file'] ?? '');

        if (empty($plugin_file)) {
            wp_send_json_error(__('Plugin file is required.', 'kiss-smart-batch-installer'));
        }

        $result = $this->activatePlugin($plugin_file);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        wp_send_json_success([
            'activated' => true,
            'plugin_file' => $plugin_file
        ]);
    }
}