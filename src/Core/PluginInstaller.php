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
     * Install a single plugin from GitHub
     */
    public function installPlugin($repo_name, $activate = false)
    {
        if (empty($this->org_name) || empty($repo_name)) {
            return new \WP_Error('invalid_params', __('Invalid parameters provided.', 'kiss-smart-batch-installer'));
        }

        // Check if plugin already exists
        $plugin_slug = sanitize_title($repo_name);
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        if (is_dir($plugin_dir)) {
            return new \WP_Error('plugin_exists', sprintf(__('Plugin directory %s already exists.', 'kiss-smart-batch-installer'), $plugin_slug));
        }

        // Download plugin
        $download_result = $this->downloadPlugin($repo_name);
        if (is_wp_error($download_result)) {
            return $download_result;
        }

        // Extract plugin
        $extract_result = $this->extractPlugin($download_result['file'], $repo_name);
        if (is_wp_error($extract_result)) {
            // Clean up downloaded file
            wp_delete_file($download_result['file']);
            return $extract_result;
        }

        // Clean up downloaded file
        wp_delete_file($download_result['file']);

        // Activate if requested
        if ($activate) {
            $activate_result = $this->activatePlugin($extract_result['plugin_file']);
            if (is_wp_error($activate_result)) {
                return $activate_result;
            }
        }

        return [
            'success' => true,
            'plugin_dir' => $extract_result['plugin_dir'],
            'plugin_file' => $extract_result['plugin_file'],
            'activated' => $activate
        ];
    }

    /**
     * Download plugin ZIP from GitHub
     */
    private function downloadPlugin($repo_name)
    {
        $download_url = sprintf(
            'https://github.com/%s/%s/archive/refs/heads/main.zip',
            urlencode($this->org_name),
            urlencode($repo_name)
        );

        // Create temporary file
        $temp_file = download_url($download_url, 300);

        if (is_wp_error($temp_file)) {
            return new \WP_Error('download_failed', sprintf(__('Failed to download plugin: %s', 'kiss-smart-batch-installer'), $temp_file->get_error_message()));
        }

        // Verify it's a ZIP file
        $file_type = wp_check_filetype($temp_file);
        if ($file_type['ext'] !== 'zip') {
            wp_delete_file($temp_file);
            return new \WP_Error('invalid_file', __('Downloaded file is not a ZIP archive.', 'kiss-smart-batch-installer'));
        }

        return [
            'file' => $temp_file,
            'url' => $download_url
        ];
    }

    /**
     * Extract plugin ZIP to plugins directory
     */
    private function extractPlugin($zip_file, $repo_name)
    {
        if (!class_exists('ZipArchive')) {
            return new \WP_Error('no_zip_support', __('PHP ZipArchive class is not available.', 'kiss-smart-batch-installer'));
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zip_file);

        if ($result !== true) {
            return new \WP_Error('zip_open_failed', sprintf(__('Failed to open ZIP file. Error code: %s', 'kiss-smart-batch-installer'), $result));
        }

        // GitHub archives have a folder structure like: repo-name-main/
        $root_folder = null;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            if (strpos($filename, '/') !== false) {
                $root_folder = substr($filename, 0, strpos($filename, '/'));
                break;
            }
        }

        if (!$root_folder) {
            $zip->close();
            return new \WP_Error('invalid_archive', __('Invalid ZIP archive structure.', 'kiss-smart-batch-installer'));
        }

        // Create plugin directory
        $plugin_slug = sanitize_title($repo_name);
        $plugin_dir = WP_PLUGIN_DIR . '/' . $plugin_slug;

        if (!wp_mkdir_p($plugin_dir)) {
            $zip->close();
            return new \WP_Error('mkdir_failed', sprintf(__('Failed to create plugin directory: %s', 'kiss-smart-batch-installer'), $plugin_dir));
        }

        // Extract files
        $extracted = false;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);

            // Skip root folder and extract contents directly
            if (strpos($filename, $root_folder . '/') === 0) {
                $relative_path = substr($filename, strlen($root_folder) + 1);

                if (empty($relative_path)) {
                    continue; // Skip the root folder itself
                }

                $target_path = $plugin_dir . '/' . $relative_path;

                // Create directory if needed
                if (substr($filename, -1) === '/') {
                    wp_mkdir_p($target_path);
                    continue;
                }

                // Extract file
                $file_content = $zip->getFromIndex($i);
                if ($file_content !== false) {
                    $target_dir = dirname($target_path);
                    if (!is_dir($target_dir)) {
                        wp_mkdir_p($target_dir);
                    }

                    if (file_put_contents($target_path, $file_content) !== false) {
                        $extracted = true;
                    }
                }
            }
        }

        $zip->close();

        if (!$extracted) {
            // Clean up on failure
            $this->removeDirectory($plugin_dir);
            return new \WP_Error('extraction_failed', __('Failed to extract plugin files.', 'kiss-smart-batch-installer'));
        }

        // Find the main plugin file
        $plugin_file = $this->findMainPluginFile($plugin_dir, $repo_name);

        if (!$plugin_file) {
            // Clean up on failure
            $this->removeDirectory($plugin_dir);
            return new \WP_Error('no_plugin_file', __('Could not find main plugin file.', 'kiss-smart-batch-installer'));
        }

        return [
            'plugin_dir' => $plugin_dir,
            'plugin_file' => $plugin_slug . '/' . $plugin_file
        ];
    }

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

    /**
     * Recursively remove a directory
     */
    private function removeDirectory($dir)
    {
        if (!is_dir($dir)) {
            return false;
        }

        $files = array_diff(scandir($dir), ['.', '..']);

        foreach ($files as $file) {
            $path = $dir . '/' . $file;
            is_dir($path) ? $this->removeDirectory($path) : unlink($path);
        }

        return rmdir($dir);
    }

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