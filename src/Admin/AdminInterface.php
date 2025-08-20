<?php

namespace KissSmartBatchInstaller\Admin;

use KissSmartBatchInstaller\Core\GitHubScraper;

/**
 * Admin Interface
 *
 * Handles WordPress admin interface for the plugin.
 */
class AdminInterface
{
    private $github_scraper;

    public function __construct()
    {
        $this->github_scraper = new GitHubScraper();

        add_action('admin_menu', [$this, 'addAdminMenu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueueAssets']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_notices', [$this, 'displayAdminNotices']);
    }

    /**
     * Add admin menu pages
     */
    public function addAdminMenu()
    {
        add_plugins_page(
            __('KISS Smart Batch Installer', 'kiss-smart-batch-installer'),
            __('KISS Smart Batch Installer', 'kiss-smart-batch-installer'),
            'install_plugins',
            'kiss-smart-batch-installer',
            [$this, 'renderMainPage']
        );

        add_submenu_page(
            'kiss-smart-batch-installer',
            __('Settings', 'kiss-smart-batch-installer'),
            __('Settings', 'kiss-smart-batch-installer'),
            'manage_options',
            'kiss-smart-batch-installer-settings',
            [$this, 'renderSettingsPage']
        );

        add_submenu_page(
            'kiss-smart-batch-installer',
            __('Self Tests', 'kiss-smart-batch-installer'),
            __('Self Tests', 'kiss-smart-batch-installer'),
            'manage_options',
            'kiss-smart-batch-installer-tests',
            [$this, 'renderSelfTestsPage']
        );
    }

    /**
     * Register plugin settings
     */
    public function registerSettings()
    {
        register_setting('kiss_sbi_settings', 'kiss_sbi_github_org', [
            'sanitize_callback' => 'sanitize_text_field'
        ]);

        register_setting('kiss_sbi_settings', 'kiss_sbi_cache_duration', [
            'sanitize_callback' => 'absint'
        ]);

        register_setting('kiss_sbi_settings', 'kiss_sbi_repo_limit', [
            'sanitize_callback' => 'absint'
        ]);

        add_settings_section(
            'kiss_sbi_main_settings',
            __('GitHub Organization Settings', 'kiss-smart-batch-installer'),
            [$this, 'settingsSectionCallback'],
            'kiss_sbi_settings'
        );

        add_settings_field(
            'kiss_sbi_github_org',
            __('GitHub Organization', 'kiss-smart-batch-installer'),
            [$this, 'githubOrgFieldCallback'],
            'kiss_sbi_settings',
            'kiss_sbi_main_settings'
        );

        add_settings_field(
            'kiss_sbi_repo_limit',
            __('Repository Limit', 'kiss-smart-batch-installer'),
            [$this, 'repoLimitFieldCallback'],
            'kiss_sbi_settings',
            'kiss_sbi_main_settings'
        );

        add_settings_field(
            'kiss_sbi_cache_duration',
            __('Cache Duration (seconds)', 'kiss-smart-batch-installer'),
            [$this, 'cacheDurationFieldCallback'],
            'kiss_sbi_settings',
            'kiss_sbi_main_settings'
        );
    }

    /**
     * Enqueue admin assets
     */
    public function enqueueAssets($hook)
    {
        if (strpos($hook, 'kiss-smart-batch-installer') === false) {
            return;
        }

        // Ensure plugin functions are available
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        // Keep server-side flag best-effort only; runtime JS will also detect PQS
        $has_pqs = function_exists('is_plugin_active') && is_plugin_active('plugin-quick-search/plugin-quick-search.php');

        wp_enqueue_script(
            'kiss-sbi-admin',
            KISS_SBI_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
            KISS_SBI_VERSION,
            true
        );

        // Enqueue PQS integration script after main admin script
        wp_enqueue_script(
            'kiss-sbi-pqs-integration',
            KISS_SBI_PLUGIN_URL . 'assets/pqs-integration.js',
            ['kiss-sbi-admin'],
            KISS_SBI_VERSION,
            true
        );

        wp_enqueue_style(
            'kiss-sbi-admin',
            KISS_SBI_PLUGIN_URL . 'assets/admin.css',
            [],
            KISS_SBI_VERSION
        );

        wp_localize_script('kiss-sbi-admin', 'kissSbiAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kiss_sbi_admin_nonce'),
            'debug' => (bool) apply_filters('kiss_sbi_debug', true),
            'hasPQS' => (bool) $has_pqs,
            'strings' => [
                'installing' => __('Installing...', 'kiss-smart-batch-installer'),
                'installed' => __('Installed', 'kiss-smart-batch-installer'),
                'error' => __('Error', 'kiss-smart-batch-installer'),
                'scanning' => __('Scanning...', 'kiss-smart-batch-installer'),
                'pqsCacheFound' => __('Using Plugin Quick Search cache...', 'kiss-smart-batch-installer'),
                'confirmBatch' => __('Install selected plugins?', 'kiss-smart-batch-installer'),
                'noSelection' => __('Please select at least one plugin.', 'kiss-smart-batch-installer')
            ]
        ]);
    }

    /**
     * Display admin notices
     */
    public function displayAdminNotices()
    {
        $screen = get_current_screen();
        if (!$screen || strpos($screen->id, 'kiss-smart-batch-installer') === false) {
            return;
        }

        $github_org = get_option('kiss_sbi_github_org', '');
        if (empty($github_org)) {
            echo '<div class="notice notice-warning"><p>';
            printf(
                __('Please configure your GitHub organization in the <a href="%s">settings</a>.', 'kiss-smart-batch-installer'),
                admin_url('admin.php?page=kiss-smart-batch-installer-settings')
            );
            echo '</p></div>';
        }
    }

    /**
     * Render main plugin page
     */
    public function renderMainPage()
    {
        $github_org = get_option('kiss_sbi_github_org', '');

        if (empty($github_org)) {
            $this->renderEmptyState();
            return;
        }

        // Handle pagination
        $current_page = max(1, (int) ($_GET['paged'] ?? 1));
        $per_page = 15;

        $result = $this->github_scraper->getRepositories(false, $current_page, $per_page);

        if (is_wp_error($result)) {
            $repositories = $result;
            $pagination = null;
        } else {
            $repositories = $result['repositories'];
            $pagination = $result['pagination'];
        }

        ?>
        <div class="wrap">
            <h1><?php _e('KISS Smart Batch Installer', 'kiss-smart-batch-installer'); ?></h1>

            <div class="kiss-sbi-header">
                <div>
                    <p><?php printf(__('Showing repositories from: <strong>%s</strong>', 'kiss-smart-batch-installer'), esc_html($github_org)); ?></p>
                    <p style="margin-top:6px;color:#646970;">
                        <?php
                        $repo_limit = (int) get_option('kiss_sbi_repo_limit', 15);
                        echo wp_kses_post(sprintf(
                            __('Heads up: the current selection on screen might be missing repos. Increase the limit in <a href="%s">Settings</a> if needed.', 'kiss-smart-batch-installer'),
                            esc_url(admin_url('admin.php?page=kiss-smart-batch-installer-settings'))
                        ));
                        ?>
                    </p>
                </div>

                <div class="kiss-sbi-actions">
                    <button type="button" class="button" id="kiss-sbi-refresh-repos">
                        <?php _e('Refresh Repositories', 'kiss-smart-batch-installer'); ?>
                    </button>

                    <button type="button" class="button" id="kiss-sbi-clear-cache">
                        <?php _e('Clear Cache', 'kiss-smart-batch-installer'); ?>
                    </button>

                    <button type="button" class="button" id="kiss-sbi-rebuild-pqs" title="<?php esc_attr_e('Force rebuild of Plugin Quick Search cache', 'kiss-smart-batch-installer'); ?>">
                        <?php _e('Rebuild PQS', 'kiss-smart-batch-installer'); ?>
                    </button>

                    <span id="kiss-sbi-pqs-indicator" class="kiss-sbi-cache-indicator" title="<?php esc_attr_e('Shows whether Plugin Quick Search cache is being used', 'kiss-smart-batch-installer'); ?>">
                        <?php _e('PQS: Checking…', 'kiss-smart-batch-installer'); ?>
                    </span>

                    <a href="<?php echo esc_url(admin_url('admin.php?page=kiss-smart-batch-installer-settings')); ?>" class="button">
                        <?php _e('Settings', 'kiss-smart-batch-installer'); ?>
                    </a>

                    <button type="button" class="button button-primary" id="kiss-sbi-batch-install" disabled>
                        <?php _e('Install Selected', 'kiss-smart-batch-installer'); ?>
                    </button>
                </div>
            </div>

            <?php if (is_wp_error($repositories)): ?>
                <div class="notice notice-error">
                    <p><?php echo esc_html($repositories->get_error_message()); ?></p>
                </div>
            <?php else: ?>
                <form id="kiss-sbi-plugins-form">
                    <?php $this->renderRepositoriesTable($repositories); ?>
                    <?php if ($pagination && $pagination['total_pages'] > 1): ?>
                        <?php $this->renderPagination($pagination); ?>
                    <?php endif; ?>
                </form>
            <?php endif; ?>

            <div id="kiss-sbi-install-progress" style="display: none;">
                <h3><?php _e('Installation Progress', 'kiss-smart-batch-installer'); ?></h3>
                <div id="kiss-sbi-progress-list"></div>
            </div>
        </div>
        <?php
    }

    /**
     * Render repositories table
     */
    private function renderRepositoriesTable($repositories)
    {
        if (empty($repositories)) {
            echo '<p>' . __('No repositories found.', 'kiss-smart-batch-installer') . '</p>';
            return;
        }

        // Debug: Log repository count and names
        error_log('KISS SBI Debug: Rendering ' . count($repositories) . ' repositories');
        $repo_names = array_column($repositories, 'name');
        error_log('KISS SBI Debug: Repository names: ' . implode(', ', $repo_names));

        // Additional check for duplicates in the final array
        $unique_names = array_unique($repo_names);
        if (count($repo_names) !== count($unique_names)) {
            error_log('KISS SBI Debug: WARNING - Duplicate repositories detected in final array!');
            $duplicates = array_diff_assoc($repo_names, $unique_names);
            error_log('KISS SBI Debug: Duplicates: ' . implode(', ', $duplicates));
        }

        ?>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <td class="manage-column column-cb check-column">
                        <input type="checkbox" id="kiss-sbi-select-all">
                    </td>
                    <th class="manage-column"><?php _e('Repository', 'kiss-smart-batch-installer'); ?></th>
                    <th class="manage-column"><?php _e('Description', 'kiss-smart-batch-installer'); ?></th>
                    <th class="manage-column"><?php _e('Language', 'kiss-smart-batch-installer'); ?></th>
                    <th class="manage-column"><?php _e('Updated', 'kiss-smart-batch-installer'); ?></th>
                    <th class="manage-column"><?php _e('WordPress Plugin', 'kiss-smart-batch-installer'); ?></th>
                    <th class="manage-column"><?php _e('Actions', 'kiss-smart-batch-installer'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($repositories as $repo): ?>
                    <tr data-repo="<?php echo esc_attr($repo['name']); ?>">
                        <th class="check-column">
                            <input type="checkbox" name="selected_repos[]" value="<?php echo esc_attr($repo['name']); ?>" class="kiss-sbi-repo-checkbox">
                        </th>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url($repo['url']); ?>" target="_blank">
                                    <?php echo esc_html($repo['name']); ?>
                                </a>
                            </strong>
                        </td>
                        <td><?php echo esc_html($repo['description']); ?></td>
                        <td><?php echo esc_html($repo['language']); ?></td>
                        <td>
                            <?php
                            if (!empty($repo['updated_at'])) {
                                echo esc_html(human_time_diff(strtotime($repo['updated_at']), current_time('timestamp')) . ' ago');
                            }
                            ?>
                        </td>
                        <td class="kiss-sbi-plugin-status" data-repo="<?php echo esc_attr($repo['name']); ?>">
                            <button type="button" class="button button-small kiss-sbi-check-plugin" data-repo="<?php echo esc_attr($repo['name']); ?>">
                                <?php _e('Check', 'kiss-smart-batch-installer'); ?>
                            </button>
                        </td>
                        <td>
                            <button type="button" class="button button-small kiss-sbi-check-installed" data-repo="<?php echo esc_attr($repo['name']); ?>">
                                <?php _e('Check Status', 'kiss-smart-batch-installer'); ?>
                            </button>
                            <button type="button" class="button button-small kiss-sbi-install-single" data-repo="<?php echo esc_attr($repo['name']); ?>" disabled style="display: none;">
                                <?php _e('Install', 'kiss-smart-batch-installer'); ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <p class="kiss-sbi-batch-options">
            <label>
                <input type="checkbox" id="kiss-sbi-activate-after-install" value="1">
                <?php _e('Activate plugins after installation', 'kiss-smart-batch-installer'); ?>
            </label>
            <?php if (function_exists('is_plugin_active') && is_plugin_active('plugin-quick-search/plugin-quick-search.php')): ?>
                <span class="kiss-sbi-pqs-notice"><?php _e('Using Plugin Quick Search cache for faster loading', 'kiss-smart-batch-installer'); ?></span>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Render pagination
     */
    private function renderPagination($pagination)
    {
        $current_page = $pagination['current_page'];
        $total_pages = $pagination['total_pages'];
        $total_items = $pagination['total_items'];

        $base_url = admin_url('plugins.php?page=kiss-smart-batch-installer');

        ?>
        <div class="tablenav bottom">
            <div class="alignleft actions">
                <span class="displaying-num">
                    <?php printf(_n('%s item', '%s items', $total_items, 'kiss-smart-batch-installer'), number_format_i18n($total_items)); ?>
                </span>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="tablenav-pages">
                    <span class="pagination-links">
                        <?php if ($current_page > 1): ?>
                            <a class="first-page button" href="<?php echo esc_url($base_url . '&paged=1'); ?>">
                                <span class="screen-reader-text"><?php _e('First page', 'kiss-smart-batch-installer'); ?></span>
                                <span aria-hidden="true">«</span>
                            </a>
                            <a class="prev-page button" href="<?php echo esc_url($base_url . '&paged=' . ($current_page - 1)); ?>">
                                <span class="screen-reader-text"><?php _e('Previous page', 'kiss-smart-batch-installer'); ?></span>
                                <span aria-hidden="true">‹</span>
                            </a>
                        <?php else: ?>
                            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">«</span>
                            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">‹</span>
                        <?php endif; ?>

                        <span class="paging-input">
                            <label for="current-page-selector" class="screen-reader-text"><?php _e('Current Page', 'kiss-smart-batch-installer'); ?></label>
                            <input class="current-page" id="current-page-selector" type="text" name="paged" value="<?php echo esc_attr($current_page); ?>" size="<?php echo strlen($total_pages); ?>" aria-describedby="table-paging" />
                            <span class="tablenav-paging-text"> <?php _e('of', 'kiss-smart-batch-installer'); ?> <span class="total-pages"><?php echo esc_html($total_pages); ?></span></span>
                        </span>

                        <?php if ($current_page < $total_pages): ?>
                            <a class="next-page button" href="<?php echo esc_url($base_url . '&paged=' . ($current_page + 1)); ?>">
                                <span class="screen-reader-text"><?php _e('Next page', 'kiss-smart-batch-installer'); ?></span>
                                <span aria-hidden="true">›</span>
                            </a>
                            <a class="last-page button" href="<?php echo esc_url($base_url . '&paged=' . $total_pages); ?>">
                                <span class="screen-reader-text"><?php _e('Last page', 'kiss-smart-batch-installer'); ?></span>
                                <span aria-hidden="true">»</span>
                            </a>
                        <?php else: ?>
                            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">›</span>
                            <span class="tablenav-pages-navspan button disabled" aria-hidden="true">»</span>
                        <?php endif; ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <script>
        jQuery(document).ready(function($) {
            $('#current-page-selector').on('keypress', function(e) {
                if (e.which === 13) { // Enter key
                    var page = parseInt($(this).val());
                    if (page > 0 && page <= <?php echo $total_pages; ?>) {
                        window.location.href = '<?php echo esc_js($base_url); ?>&paged=' + page;
                    }
                }
            });
        });
        </script>
        <?php
    }

    /**
     * Render empty state
     */
    private function renderEmptyState()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('KISS Smart Batch Installer', 'kiss-smart-batch-installer'); ?></h1>

            <div class="notice notice-info">
                <p>
                    <?php
                    printf(
                        __('Welcome! Please <a href="%s">configure your GitHub organization</a> to get started.', 'kiss-smart-batch-installer'),
                        admin_url('admin.php?page=kiss-smart-batch-installer-settings')
                    );
                    ?>
                </p>
            </div>
        </div>
        <?php
    }

    /**
     * Render settings page
     */
    public function renderSettingsPage()
    {
        ?>
        <div class="wrap">
            <h1><?php _e('KISS Smart Batch Installer Settings', 'kiss-smart-batch-installer'); ?></h1>

            <p>
                <a href="<?php echo esc_url(admin_url('admin.php?page=kiss-smart-batch-installer-tests')); ?>" class="button">
                    <?php _e('Run Self Tests', 'kiss-smart-batch-installer'); ?>
                </a>
            </p>

            <form method="post" action="options.php">
                <?php
                settings_fields('kiss_sbi_settings');
                do_settings_sections('kiss_sbi_settings');
                submit_button();
                ?>
            </form>

            <div class="kiss-sbi-settings-info">
                <h3><?php _e('How it works', 'kiss-smart-batch-installer'); ?></h3>
                <ol>
                    <li><?php _e('Enter your GitHub organization name (e.g., "kissplugins")', 'kiss-smart-batch-installer'); ?></li>
                    <li><?php _e('The plugin will fetch the most recently updated repositories', 'kiss-smart-batch-installer'); ?></li>
                    <li><?php _e('Check which repositories contain WordPress plugins', 'kiss-smart-batch-installer'); ?></li>
                    <li><?php _e('Install plugins directly from the main branch', 'kiss-smart-batch-installer'); ?></li>
                </ol>

                <h3><?php _e('Requirements', 'kiss-smart-batch-installer'); ?></h3>
                <ul>
                    <li><?php _e('GitHub organization must be public', 'kiss-smart-batch-installer'); ?></li>
                    <li><?php _e('Repositories must be public', 'kiss-smart-batch-installer'); ?></li>
                    <li><?php _e('WordPress plugins must have proper plugin headers', 'kiss-smart-batch-installer'); ?></li>
                </ul>
            </div>
        </div>
        <?php
    }

    /**
     * Render Self Tests page
     */
    public function renderSelfTestsPage()
    {
        $results = [];

        // 1) GitHub configuration/connectivity
        $org = get_option('kiss_sbi_github_org', '');
        $results['config'] = [
            'label' => __('GitHub configuration', 'kiss-smart-batch-installer'),
            'pass' => !empty($org),
            'details' => empty($org) ? __('GitHub organization is not set.', 'kiss-smart-batch-installer') : sprintf(__('Org set to: %s', 'kiss-smart-batch-installer'), esc_html($org)),
        ];

        // 2) Repository fetch + pagination (dry-run, page 1 & 2 with per_page=5)
        $paginationCheck = ['pass' => false, 'details' => ''];
        if (!empty($org)) {
            try {
                $r1 = $this->github_scraper->getRepositories(true, 1, 5);
                $r2 = $this->github_scraper->getRepositories(false, 2, 5);
                if (!is_wp_error($r1) && !is_wp_error($r2)) {
                    $page1 = wp_list_pluck($r1['repositories'], 'name');
                    $page2 = wp_list_pluck($r2['repositories'], 'name');
                    $total = (int) $r1['pagination']['total_items'];
                    $pages = (int) $r1['pagination']['total_pages'];
                    $paginationCheck['pass'] = $total >= 0 && $pages >= 1;
                    $paginationCheck['details'] = sprintf(__('Total: %d, Pages: %d, Page1 count: %d, Page2 count: %d', 'kiss-smart-batch-installer'), $total, $pages, count($page1), count($page2));
                } else {
                    $err = is_wp_error($r1) ? $r1 : $r2;
                    $paginationCheck['details'] = $err->get_error_message();
                }
            } catch (\Throwable $e) {
                $paginationCheck['details'] = $e->getMessage();
            }
        } else {
            $paginationCheck['details'] = __('Org not set; skipping.', 'kiss-smart-batch-installer');
        }
        $results['pagination'] = array_merge(['label' => __('Repository fetch + pagination', 'kiss-smart-batch-installer')], $paginationCheck);

        // 3) Plugin detection spot-check (up to 5 repos)
        $detectCheck = ['pass' => false, 'details' => ''];
        if (!empty($org) && !is_wp_error($r1 ?? null)) {
            $names = array_slice(wp_list_pluck($r1['repositories'], 'name'), 0, 5);
            $counts = ['plugins' => 0, 'not_plugins' => 0];
            foreach ($names as $n) {
                $res = $this->github_scraper->isWordPressPlugin($n);
                if (is_wp_error($res)) {
                    $detectCheck['details'] = $res->get_error_message();
                    break;
                }
                if (!empty($res['is_plugin'])) {
                    $counts['plugins']++;
                } else {
                    $counts['not_plugins']++;
                }
            }
            if (empty($detectCheck['details'])) {
                $detectCheck['pass'] = true; // The check executed
                $detectCheck['details'] = sprintf(__('Checked %d repos: %d plugins, %d not plugins', 'kiss-smart-batch-installer'), count($names), $counts['plugins'], $counts['not_plugins']);
            }
        } else if (empty($org)) {
            $detectCheck['details'] = __('Org not set; skipping.', 'kiss-smart-batch-installer');
        }
        $results['detection'] = array_merge(['label' => __('Plugin detection', 'kiss-smart-batch-installer')], $detectCheck);

        // 4) Install/Activate endpoints health (dry-run checks only)
        $capPass = current_user_can('install_plugins');
        $results['endpoints'] = [
            'label' => __('Install/Activate endpoints (permissions)', 'kiss-smart-batch-installer'),
            'pass' => (bool) $capPass,
            'details' => $capPass ? __('Current user can install plugins.', 'kiss-smart-batch-installer') : __('Current user cannot install plugins.', 'kiss-smart-batch-installer'),
        ];

        // 5) PQS cache presence (server-side)
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('get_plugins')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $hasPqs = false;
        if (function_exists('is_plugin_active')) {
            // Check common slug first
            if (is_plugin_active('plugin-quick-search/plugin-quick-search.php')) {
                $hasPqs = true;
            } else if (function_exists('get_plugins')) {
                // Fallback: scan all plugins for a likely PQS entry that is active
                $all_plugins = get_plugins();
                foreach ($all_plugins as $file => $data) {
                    $name = strtolower($data['Name'] ?? '');
                    if (strpos($name, 'plugin quick search') !== false || strpos($file, 'plugin-quick-search') !== false) {
                        if (is_plugin_active($file)) {
                            $hasPqs = true;
                            break;
                        }
                    }
                }
            }
        }
        $results['pqs_found'] = [
            'label' => __('PQS Cache plugin found', 'kiss-smart-batch-installer'),
            'pass' => (bool) $hasPqs,
            'details' => $hasPqs ? __('Plugin Quick Search is active.', 'kiss-smart-batch-installer') : __('Plugin Quick Search is not active.', 'kiss-smart-batch-installer'),
        ];

        // 6) PQS cache usage (client-side verification)
        $results['pqs_used'] = [
            'label' => __('PQS Cache used', 'kiss-smart-batch-installer'),
            'pass' => false,
            'details' => __('Will be verified in-browser using pqsCacheStatus().', 'kiss-smart-batch-installer'),
        ];

        // Render page
        echo '<div class="wrap">';
        echo '<h1>' . esc_html__('KISS Smart Batch Installer — Self Tests', 'kiss-smart-batch-installer') . '</h1>';
        echo '<p>' . esc_html__('These tests help verify connectivity, pagination, detection and permissions. They are safe and do not install anything.', 'kiss-smart-batch-installer') . '</p>';

        // Allow other plugins (e.g., PQS) to add self-test rows
        $results = apply_filters('kiss_sbi_self_test_results', $results);
        echo '<table class="widefat fixed striped"><thead><tr><th>' . esc_html__('Test', 'kiss-smart-batch-installer') . '</th><th>' . esc_html__('Result', 'kiss-smart-batch-installer') . '</th><th>' . esc_html__('Details', 'kiss-smart-batch-installer') . '</th></tr></thead><tbody>';
        foreach ($results as $key => $r) {
            $status = !empty($r['pass']) ? '<span style="color:#46b450;font-weight:600;">' . esc_html__('PASS', 'kiss-smart-batch-installer') . '</span>' : '<span style="color:#dc3232;font-weight:600;">' . esc_html__('FAIL', 'kiss-smart-batch-installer') . '</span>';
            echo '<tr id="test-' . esc_attr($key) . '"><td>' . esc_html($r['label']) . '</td><td id="test-' . esc_attr($key) . '-result">' . $status . '</td><td id="test-' . esc_attr($key) . '-details">' . wp_kses_post($r['details']) . '</td></tr>';
        }
        echo '</tbody></table>';
        echo '<p><a href="' . esc_url(admin_url('plugins.php?page=kiss-smart-batch-installer')) . '" class="button">' . esc_html__('Back to Installer', 'kiss-smart-batch-installer') . '</a></p>';

        // JS API for counter tests from other plugins
        echo '<script>(function(){\n' .
            'function addOrUpdateRow(key,label,pass,details){\n' .
            '  var row=document.getElementById(\'test-\'+key);\n' .
            '  var statusHtml=pass?\'<span style="color:#46b450;font-weight:600;">PASS</span>\':\'<span style="color:#dc3232;font-weight:600;">FAIL</span>\';\n' .
            '  if(!row){\n' .
            '    var tbody=document.querySelector(\'.widefat tbody\'); if(!tbody) return;\n' .
            '    row=document.createElement(\'tr\'); row.id=\'test-\'+key;\n' .
            '    row.innerHTML=\'<td>\'+label+\'</td><td id="test-\'+key+\'-result">\'+statusHtml+\'</td><td id="test-\'+key+\'-details"></td>\';\n' .
            '    tbody.appendChild(row);\n' .
            '  } else {\n' .
            '    var res=document.getElementById(\'test-\'+key+\'-result\'); if(res) res.innerHTML=statusHtml;\n' .
            '  }\n' .
            '  var det=document.getElementById(\'test-\'+key+\'-details\'); if(det) det.textContent=details||\'\';\n' .
            '}\n' .
            'window.kissSbiSelfTests={addOrUpdateRow:addOrUpdateRow};\n' .
            'document.dispatchEvent(new CustomEvent(\'kiss-sbi-self-tests-ready\',{detail:window.kissSbiSelfTests}));\n' .
            '})();</script>';

        // Inline script to evaluate PQS usage in the browser
        ?>
        <script>
        (function(){
            function setRow(pass, details){
                var r = document.getElementById('test-pqs_used-result');
                var d = document.getElementById('test-pqs_used-details');
                if (!r || !d) return;
                r.innerHTML = pass ? '<span style="color:#46b450;font-weight:600;">PASS</span>' : '<span style="color:#dc3232;font-weight:600;">FAIL</span>';
                d.textContent = details;
            }
            function run(){
                try{
                    var raw = localStorage.getItem('pqs_plugin_cache');
                    var len = 0; try { var arr = JSON.parse(raw||'[]'); len = Array.isArray(arr)?arr.length:0; } catch(e) {}
                    var status = (typeof window.pqsCacheStatus === 'function') ? window.pqsCacheStatus() : (len > 0 ? 'unknown' : 'missing');
                    var used = (status === 'fresh') || (status === 'unknown' && len > 0);
                    var detail = 'status=' + status + ', entries=' + len + (status==='unknown' ? ' (via localStorage)' : '');
                    setRow(!!used, detail);
                }catch(e){
                    setRow(false, 'Error: ' + (e && e.message ? e.message : e));
                }
            }
            if (document.readyState === 'complete' || document.readyState === 'interactive') run();
            else document.addEventListener('DOMContentLoaded', run);
        })();
        </script>
        <?php
        echo '</div>';
    }

    /**
     * Settings section callback
     */
    public function settingsSectionCallback()
    {
        echo '<p>' . __('Configure your GitHub organization settings below.', 'kiss-smart-batch-installer') . '</p>';
    }

    /**
     * GitHub org field callback
     */
    public function githubOrgFieldCallback()
    {
        $value = get_option('kiss_sbi_github_org', '');
        echo '<input type="text" name="kiss_sbi_github_org" value="' . esc_attr($value) . '" class="regular-text" placeholder="e.g., kissplugins">';
        echo '<p class="description">' . __('Enter the GitHub organization name (without @ symbol).', 'kiss-smart-batch-installer') . '</p>';
    }

    /**
     * Repo limit field callback
     */
    public function repoLimitFieldCallback()
    {
        $value = get_option('kiss_sbi_repo_limit', 15);
        echo '<input type="number" name="kiss_sbi_repo_limit" value="' . esc_attr($value) . '" min="1" max="100" class="small-text">';
        echo '<p class="description">' . __('Number of repositories to check (1-100).', 'kiss-smart-batch-installer') . '</p>';
    }

    /**
     * Cache duration field callback
     */
    public function cacheDurationFieldCallback()
    {
        $value = get_option('kiss_sbi_cache_duration', 3600);
        echo '<input type="number" name="kiss_sbi_cache_duration" value="' . esc_attr($value) . '" min="300" class="regular-text">';
        echo '<p class="description">' . __('How long to cache repository data (in seconds). Default: 3600 (1 hour).', 'kiss-smart-batch-installer') . '</p>';
    }
}