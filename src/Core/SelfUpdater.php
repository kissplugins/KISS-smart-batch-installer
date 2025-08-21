<?php

namespace KissSmartBatchInstaller\Core;

use WP_Error;
use Plugin_Upgrader;
use WP_Ajax_Upgrader_Skin;

class SelfUpdater
{
    const REMOTE_PHP_RAW = 'https://raw.githubusercontent.com/kissplugins/KISS-Smart-Batch-Installer/main/KISS-smart-batch-installer.php';
    const REMOTE_ZIP = 'https://github.com/kissplugins/KISS-Smart-Batch-Installer/archive/refs/heads/main.zip';
    const TRANSIENT_KEY = 'kiss_sbi_remote_version';
    const TRANSIENT_TTL = 15 * 60; // 15 minutes

    public function __construct()
    {
        add_action('wp_ajax_kiss_sbi_check_self_update', [$this, 'ajaxCheckSelfUpdate']);
        add_action('wp_ajax_kiss_sbi_run_self_update', [$this, 'ajaxRunSelfUpdate']);
        add_filter('upgrader_source_selection', [$this, 'fixSourceDirectory'], 10, 4);
    }

    public function getInstalledVersion(): string
    {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $data = get_plugin_data(\KISS_SBI_PLUGIN_FILE, false, false);
        return (string) ($data['Version'] ?? (defined('KISS_SBI_VERSION') ? KISS_SBI_VERSION : '0.0.0'));
    }

    public function getRemoteVersion($force = false)
    {
        if (!$force) {
            $cached = get_transient(self::TRANSIENT_KEY);
            if ($cached) return $cached;
        }
        $resp = wp_remote_get(self::REMOTE_PHP_RAW, [ 'timeout' => 10 ]);
        if (is_wp_error($resp)) return $resp;
        $code = wp_remote_retrieve_response_code($resp);
        $body = wp_remote_retrieve_body($resp);
        if ($code !== 200 || empty($body)) return new WP_Error('remote_fetch_failed', 'Could not fetch remote version.');
        if (preg_match('/^\s*\*\s*Version:\s*([0-9a-zA-Z\.-]+)/m', $body, $m)) {
            $ver = trim($m[1]);
            set_transient(self::TRANSIENT_KEY, $ver, self::TRANSIENT_TTL);
            return $ver;
        }
        return new WP_Error('version_parse_failed', 'Could not parse remote version.');
    }

    public function isUpdateAvailable(): array
    {
        $installed = $this->getInstalledVersion();
        $remote = $this->getRemoteVersion();
        if (is_wp_error($remote)) {
            return ['available' => false, 'error' => $remote->get_error_message(), 'installed' => $installed, 'remote' => null];
        }
        $cmp = version_compare($remote, $installed, '>');
        return ['available' => $cmp, 'installed' => $installed, 'remote' => $remote];
    }

    public function ajaxCheckSelfUpdate()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');
        if (!current_user_can('update_plugins')) wp_die('Insufficient permissions');
        $force = isset($_POST['force']) && $_POST['force'] == '1';
        $remote = $this->getRemoteVersion($force);
        $installed = $this->getInstalledVersion();
        if (is_wp_error($remote)) {
            wp_send_json_success(['available' => false, 'installed' => $installed, 'remote' => null, 'error' => $remote->get_error_message(), 'status' => 'unknown']);
        }
        $status = 'equal';
        if (version_compare($installed, $remote, '>')) {
            $status = 'newer';
        } elseif (version_compare($installed, $remote, '<')) {
            $status = 'older';
        }
        wp_send_json_success([
            'available' => ($status === 'older'),
            'installed' => $installed,
            'remote' => $remote,
            'status' => $status,
        ]);
    }

    public function ajaxRunSelfUpdate()
    {
        check_ajax_referer('kiss_sbi_admin_nonce', 'nonce');
        if (!current_user_can('update_plugins') || !current_user_can('install_plugins')) wp_die('Insufficient permissions');

        include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';

        $skin = new WP_Ajax_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);

        // Allow override of package to ensure replace-in-place
        add_filter('upgrader_package_options', function ($options) {
            $options['abort_if_destination_exists'] = false;
            $options['clear_destination'] = true;
            return $options;
        });

        // Capture activation state prior to installing
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $was_active = is_plugin_active(\KISS_SBI_PLUGIN_BASENAME);
        $was_network = function_exists('is_plugin_active_for_network') && is_plugin_active_for_network(\KISS_SBI_PLUGIN_BASENAME);

        $result = $upgrader->install(self::REMOTE_ZIP);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }
        if (!$result) {
            wp_send_json_error('Update failed');
        }

        // Reactivate if it was active
        if ($was_active || $was_network) {
            activate_plugin(\KISS_SBI_PLUGIN_BASENAME, '', $was_network);
        }

        delete_transient(self::TRANSIENT_KEY);

        $installed = $this->getInstalledVersion();
        wp_send_json_success(['updated' => true, 'installed' => $installed]);
    }

    /**
     * Rename the extracted GitHub directory to the plugin slug so it overwrites correctly
     */
    public function fixSourceDirectory($source, $remote_source, $upgrader, $hook_extra)
    {
        // Only for our package
        if (empty($source) || strpos($source, 'KISS-Smart-Batch-Installer-main') === false) return $source;
        $desired = trailingslashit(WP_PLUGIN_DIR) . basename(dirname(\KISS_SBI_PLUGIN_FILE));
        $desired = trailingslashit($desired);
        $target = trailingslashit(dirname($source)) . basename($desired);

        // Prefer WordPress filesystem API during upgrader operations
        global $wp_filesystem;
        if (!$wp_filesystem) {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            WP_Filesystem();
        }
        if ($wp_filesystem) {
            if ($wp_filesystem->exists($target)) {
                $wp_filesystem->delete($target, true);
            }
            $moved = $wp_filesystem->move($source, $target, true);
            return $moved ? $target : $source;
        }

        // Fallback to direct rename
        @rename($source, $target);
        return $target;
    }
}

