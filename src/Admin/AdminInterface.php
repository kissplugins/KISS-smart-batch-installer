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
        // Always enqueue keyboard shortcuts on admin pages
        wp_enqueue_script(
            'kiss-sbi-keyboard-shortcuts',
            KISS_SBI_PLUGIN_URL . 'assets/keyboard-shortcuts.js',
            ['jquery'],
            KISS_SBI_VERSION,
            true
        );

        // Localize script with installer URL for keyboard shortcut
        wp_localize_script('kiss-sbi-keyboard-shortcuts', 'kissSbiShortcuts', [
            'installerUrl' => admin_url('plugins.php?page=kiss-smart-batch-installer'),
            'debug' => (bool) apply_filters('kiss_sbi_debug', true)
        ]);
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

        // SBI Quick Search overlay (for in-page search on SBI screen)
        wp_enqueue_script(
            'kiss-sbi-quick-search',
            KISS_SBI_PLUGIN_URL . 'assets/sbi-quick-search.js',
            ['kiss-sbi-admin'],
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

        // Default unified cell flag: enable unified cell by default (can be overridden via filter)
        $unified_default = true;

        $unified_cell_enabled = (bool) apply_filters('kiss_sbi_unified_cell', $unified_default);

        wp_localize_script('kiss-sbi-admin', 'kissSbiAjax', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('kiss_sbi_admin_nonce'),
            'debug' => (bool) apply_filters('kiss_sbi_debug', true),
            'hasPQS' => (bool) $has_pqs,
            'org' => (string) get_option('kiss_sbi_github_org', ''),
            'unifiedCell' => $unified_cell_enabled,
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

        // Unified cell flag (server-side) for CSS column hiding
        $unified_default = true;
        $unified_cell_enabled = (bool) apply_filters('kiss_sbi_unified_cell', $unified_default);

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

        // If org is kissplugins, pin SBI first and PQS second (companions)
        if (!is_wp_error($repositories) && strtolower($github_org) === 'kissplugins') {
            $sbi = null; $pqs = null; $rest = [];
            foreach ($repositories as $r) {
                $name = isset($r['name']) ? $r['name'] : '';
                if (strcasecmp($name, 'KISS-Smart-Batch-Installer') === 0) { $sbi = $r; continue; }
                if (strcasecmp($name, 'KISS-Plugin-Quick-Search') === 0) { $pqs = $r; continue; }
                $rest[] = $r;
            }
            if ($pqs === null) {
                $pqs = [
                    'name' => 'KISS-Plugin-Quick-Search',
                    'description' => __('Plugin Quick Search (helper for faster SBI detection)', 'kiss-smart-batch-installer'),
                    'language' => 'PHP',
                    'updated_at' => '',
                    'url' => 'https://github.com/kissplugins/KISS-Plugin-Quick-Search',
                    'is_wordpress_plugin' => null
                ];
            }
            $new = [];
            if ($sbi) { $new[] = $sbi; }
            if ($pqs) { $new[] = $pqs; }
            $repositories = array_merge($new, $rest);
        }

        ?>


        <div class="wrap">
            <h1><?php _e('KISS Smart Batch Installer', 'kiss-smart-batch-installer'); ?></h1>




            <div class="kiss-sbi-header">
                <div>
                    <p><?php printf(__('Showing repositories from: <strong>%s</strong>', 'kiss-smart-batch-installer'), esc_html($github_org)); ?></p>
                    <p style="margin-top:6px;color:#646970;">
                        <?php
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

            <div class="notice notice-warning" style="margin:12px 0 16px 0;">
                <p><strong><?php _e('Important Notes:', 'kiss-smart-batch-installer'); ?></strong> <?php _e('Installation of any plugins below are at your own risk. We do not run any security scans of any plugins. We rely on native/built-in WP functions to install or upgrade plugins from your specified repositories.', 'kiss-smart-batch-installer'); ?></p>
                <p><?php _e('KISS Plugins does not offer any warranty or support for this software. Please review any plugins yourself before installation.', 'kiss-smart-batch-installer'); ?></p>
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
                    <?php $is_sbi_repo = (strcasecmp($repo['name'], 'KISS-Smart-Batch-Installer') === 0); ?>
                    <tr data-repo="<?php echo esc_attr($repo['name']); ?>">
                        <th class="check-column">
                            <input type="checkbox" name="selected_repos[]" value="<?php echo esc_attr($repo['name']); ?>" class="kiss-sbi-repo-checkbox">
                        </th>
                        <td>
                            <strong>
                                <a href="<?php echo esc_url($repo['url']); ?>" target="_blank">
                                    <?php echo esc_html($repo['name']); ?>
                                </a>
                                <?php if ($is_sbi_repo): ?>
                                    <span class="dashicons dashicons-yes" title="This plugin"></span>
                                <?php endif; ?>
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
                            <?php if ($is_sbi_repo): ?>
                                <span class="kiss-sbi-plugin-yes" title="WordPress Plugin">✓ WordPress Plugin</span>
                            <?php else: ?>
                                <button type="button" class="button button-small kiss-sbi-check-plugin" data-repo="<?php echo esc_attr($repo['name']); ?>">
                                    <?php _e('Check', 'kiss-smart-batch-installer'); ?>
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($is_sbi_repo): ?>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=kiss-smart-batch-installer-settings')); ?>" class="button button-small"><span class="dashicons dashicons-admin-generic" aria-hidden="true"></span> <?php _e('Settings', 'kiss-smart-batch-installer'); ?></a>
                                <button type="button" class="button button-primary kiss-sbi-self-update" data-repo="<?php echo esc_attr($repo['name']); ?>" style="margin-left:8px; display:none;">
                                    <?php _e('Update', 'kiss-smart-batch-installer'); ?>
                                </button>
                                <span class="kiss-sbi-self-update-meta" style="color:#646970;"></span>
                            <?php else: ?>
                                <button type="button" class="button button-small kiss-sbi-check-installed" data-repo="<?php echo esc_attr($repo['name']); ?>">
                                    <?php _e('Check Status', 'kiss-smart-batch-installer'); ?>
                                </button>
                                <button type="button" class="button button-small kiss-sbi-install-single" data-repo="<?php echo esc_attr($repo['name']); ?>" disabled style="display: none;">
                                    <?php _e('Install', 'kiss-smart-batch-installer'); ?>
                                </button>
                            <?php endif; ?>
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

        // 3) Plugin detection: spot-check recent repos + assert non-plugins stay non-plugins
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
                if ($res && !is_wp_error($res)) {
                    $counts['plugins']++;
                } else {
                    $counts['not_plugins']++;
                }
            }
            // Assert known non-plugins are detected as non-plugins
            $known_non_plugins = [];
            if (strtolower($org) === 'kissplugins') {
                $known_non_plugins[] = 'NHK-plugin-framework';
            }
            $failed_non_plugin = '';
            foreach ($known_non_plugins as $np) {
                $res = $this->github_scraper->isWordPressPlugin($np);
                if (!is_wp_error($res) && !empty($res['is_plugin'])) {
                    $failed_non_plugin = $np; break;
                }
            }
            if (empty($detectCheck['details'])) {
                $detectCheck['pass'] = empty($failed_non_plugin);
                $detectCheck['details'] = empty($failed_non_plugin)
                    ? sprintf(__('Checked %d repos: %d plugins, %d not plugins. Non-plugin assertions passed.', 'kiss-smart-batch-installer'), count($names), $counts['plugins'], $counts['not_plugins'])
                    : sprintf(__('Regression: %s should not be detected as a plugin.', 'kiss-smart-batch-installer'), $failed_non_plugin);
        // 4) Upgrader dry-run (non-destructive)
        $upgraderCheck = ['pass' => false, 'details' => ''];
        try {
            include_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
            $skin = new \WP_Upgrader_Skin();
            $upgrader = new \Plugin_Upgrader($skin);
            $testRepo = null;
            if (!empty($org) && !is_wp_error($r1 ?? null)) {
                $names = wp_list_pluck($r1['repositories'], 'name');
                foreach ($names as $n) { if ($n && stripos($n, 'framework') === false) { $testRepo = $n; break; } }
            }
            if ($testRepo) {
                $zipUrl = sprintf('https://github.com/%s/%s/archive/refs/heads/main.zip', urlencode($org), urlencode($testRepo));
                // Probe only: verify reachability without installing (HEAD then Range GET fallback)
                $resp = wp_remote_head($zipUrl, ['timeout' => 10, 'redirection' => 5]);
                $code = !is_wp_error($resp) ? (int) wp_remote_retrieve_response_code($resp) : 0;
                $ok = ($code >= 200 && $code < 300) || ($code >= 300 && $code < 400);
                if (!$ok) {
                    // Fallback: GET first byte only to avoid full download
                    $resp = wp_remote_get($zipUrl, ['timeout' => 10, 'redirection' => 5, 'headers' => ['Range' => 'bytes=0-0']]);
                    $code = !is_wp_error($resp) ? (int) wp_remote_retrieve_response_code($resp) : 0;
                    $ok = in_array($code, [200, 206], true);
                }
                if ($ok) {
                    $upgraderCheck['pass'] = true;
                    $upgraderCheck['details'] = sprintf(__('Zip reachable for %s (HTTP %d; no install performed).', 'kiss-smart-batch-installer'), esc_html($testRepo), $code);
                } else {
                    $upgraderCheck['details'] = sprintf(__('Could not reach GitHub zip for dry-run (HTTP %d).', 'kiss-smart-batch-installer'), $code);
                }
            } else {
                $upgraderCheck['details'] = __('No suitable repository available for dry-run.', 'kiss-smart-batch-installer');
            }
        } catch (\Throwable $e) {
            $upgraderCheck['details'] = $e->getMessage();
        }
        $results['upgrader_dry_run'] = array_merge(['label' => __('Upgrader dry-run (zip reachable)', 'kiss-smart-batch-installer')], $upgraderCheck);

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
            'details' => $hasPqs ? __('Plugin Quick Search is active.', 'kiss-smart-batch-installer') : __('Plugin Quick Search is not active.', 'kiss-smart-batch-installer') . (strtolower($org) === 'kissplugins' ? ' — <button type="button" class="button button-small" id="kiss-sbi-install-pqs">' . esc_html__('Install PQS from main', 'kiss-smart-batch-installer') . '</button>' : ''),
        ];

        // 6) ajaxGetRowStatus contract (server-side)
        $ajaxContract = ['pass' => false, 'details' => ''];
        $ajax_contract_repo = '';
        try {
            $r = $this->github_scraper->getRepositories(true, 1, 1);
            if (!is_wp_error($r) && !empty($r['repositories'][0]['name'])) {
                $ajax_contract_repo = (string) $r['repositories'][0]['name'];
            }
            if ($ajax_contract_repo) {
                // Expose candidate to browser-side probe to avoid reliance on DOM
                echo '<script>window.kissSbiSelfTestCandidateRepo=' . json_encode($ajax_contract_repo) . ';</script>';

                $resp = wp_remote_post(admin_url('admin-ajax.php'), [
                    'timeout' => 15,
                    // Allow self-signed certificates for local/dev environments during self-test loopback
                    'sslverify' => false,
                    'body' => [
                        'action' => 'kiss_sbi_get_row_status',
                        'nonce' => wp_create_nonce('kiss_sbi_admin_nonce'),
                        'repo_name' => $ajax_contract_repo,
                    ],
                ]);
                if (!is_wp_error($resp)) {
                    $code = (int) wp_remote_retrieve_response_code($resp);
                    $body = wp_remote_retrieve_body($resp);
                    $json = json_decode($body, true);
                    $ok = ($code >= 200 && $code < 300) && is_array($json) && !empty($json['success']) && !empty($json['data']) && is_array($json['data']);
                    $keys = ['repoName','isPlugin','isInstalled','isActive','pluginFile','settingsUrl','checking','installing','error'];
                    $hasKeys = $ok;
                    if ($ok) {
                        foreach ($keys as $k) { if (!array_key_exists($k, $json['data'])) { $hasKeys = false; break; } }
                    }
                    $ajaxContract['pass'] = $ok && $hasKeys;
                    if ($ajaxContract['pass']) {
                        $ajaxContract['details'] = sprintf(__('OK for %s (all keys present).', 'kiss-smart-batch-installer'), esc_html($ajax_contract_repo));
                    } else {
                        $ajaxContract['details'] = sprintf(__('Invalid response (HTTP %d).', 'kiss-smart-batch-installer'), $code);
                        if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
                            $missing = [];
                            foreach ($keys as $k) { if (!array_key_exists($k, $json['data'])) { $missing[] = $k; } }
                            if (!empty($missing)) { $ajaxContract['details'] .= ' Missing: ' . implode(', ', $missing); }
                        }
                    }
                } else {
                    $ajaxContract['details'] = $resp->get_error_message();
                }
            } else {
                $ajaxContract['details'] = __('No repository available to test.', 'kiss-smart-batch-installer');
            }
        } catch (\Throwable $e) {
            $ajaxContract['details'] = $e->getMessage();
        }
        $results['ajax_status_contract'] = array_merge(['label' => __('ajaxGetRowStatus contract (server)', 'kiss-smart-batch-installer')], $ajaxContract);

        // 6) PQS cache usage (client-side verification)
        $results['pqs_used'] = [
            'label' => __('PQS Cache used', 'kiss-smart-batch-installer'),
            'pass' => false,
            'details' => __('Will be verified in-browser using pqsCacheStatus().', 'kiss-smart-batch-installer'),
        ];

        // Filesystem readiness (WP_Filesystem)
        $fsCheck = ['pass' => false, 'details' => ''];
        try {
            if (!function_exists('WP_Filesystem')) {
                require_once ABSPATH . 'wp-admin/includes/file.php';
            }
            $init = WP_Filesystem();
            global $wp_filesystem;
            if (!$init || !$wp_filesystem) {
                $fsCheck['details'] = __('Could not initialize WP_Filesystem (credentials may be required).', 'kiss-smart-batch-installer');
            } else {
                $method = method_exists($wp_filesystem, 'method') ? $wp_filesystem->method : 'unknown';
                // Use uploads dir for safe write
                $uploads = wp_get_upload_dir();
                $base = trailingslashit($uploads['basedir']) . 'kiss-sbi-selftest';
                $file = trailingslashit($base) . 'probe.txt';
                // Ensure base dir
                if (!$wp_filesystem->exists($base)) {
                    $wp_filesystem->mkdir($base);
                }
                $okWrite = $wp_filesystem->put_contents($file, 'ok:' . time());
                $okRead = $okWrite && $wp_filesystem->exists($file) && is_string($wp_filesystem->get_contents($file));
                $okDelete = $okRead && $wp_filesystem->delete($file);
                // Try remove dir (ignore failure if non-empty)
                $wp_filesystem->delete($base, true);
                if ($okWrite && $okRead && $okDelete) {
                    $fsCheck['pass'] = true;
                    $fsCheck['details'] = sprintf(__('WP_Filesystem ready (method=%s). Wrote and removed test file in uploads.', 'kiss-smart-batch-installer'), esc_html($method));
                } else {
                    $fsCheck['details'] = sprintf(__('WP_Filesystem write/read/delete failed (method=%s). Check permissions on uploads.', 'kiss-smart-batch-installer'), esc_html($method));
                }
            }
        } catch (\Throwable $e) {
            $fsCheck['details'] = $e->getMessage();
        }
        $results['fs_ready'] = array_merge(['label' => __('Filesystem readiness (WP_Filesystem)', 'kiss-smart-batch-installer')], $fsCheck);

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

        // Inline script to evaluate PQS usage in the browser + add Refresh button
        ?>
        <script>
        // Add a "Refresh tests" button that re-runs in-browser checks and row-state tests
        (function(){
            function ensureButton(){
                var container = document.querySelector('.wrap h1');
                if (!container) return;
                var btn = document.getElementById('kiss-sbi-refresh-tests');
                if (btn) return;
                btn = document.createElement('a');
                btn.id = 'kiss-sbi-refresh-tests';
                btn.className = 'button';
                btn.style.marginLeft = '10px';
                btn.textContent = 'Refresh tests';
                btn.addEventListener('click', function(){
                    try { document.dispatchEvent(new CustomEvent('kiss-sbi-self-tests-ready',{detail:window.kissSbiSelfTests})); } catch(_) {}
                    try { runPqsProbe(); } catch(_) {}
                    try { runAjaxContractProbe(); } catch(_) {}
                });
                container.parentNode.insertBefore(btn, container.nextSibling);
            }
            if (document.readyState === 'complete' || document.readyState === 'interactive') ensureButton(); else document.addEventListener('DOMContentLoaded', ensureButton);
        })();

        // Enqueue RowStateManager tests into Self Tests page (browser-based)
        (function(){
            var s = document.createElement('script');
            s.src = '<?php echo esc_js( KISS_SBI_PLUGIN_URL . 'assets/tests/row-state-tests.js' ); ?>';
            s.async = true;
            document.currentScript.parentNode.insertBefore(s, document.currentScript);
        })();

        // Ajax contract probe (client-side quick ping)
        function runAjaxContractProbe(){
            var rowId = 'test-ajax_status_contract_client';
            var row = document.getElementById(rowId);
            if (!row) {
                var tbody = document.querySelector('.widefat tbody');
                if (!tbody) return;
                row = document.createElement('tr');
                row.id = rowId;
                row.innerHTML = '<td>ajaxGetRowStatus contract (client)</td><td id="'+rowId+'-result"></td><td id="'+rowId+'-details"></td>';
                tbody.appendChild(row);
            }
            var res = document.getElementById(rowId+'-result');
            var det = document.getElementById(rowId+'-details');
            res.innerHTML = '<span style="color:#666;">RUNNING</span>';
            det.textContent = 'Pinging…';
            try {
                var repo = (window.kissSbiSelfTestCandidateRepo || '').toString().trim();
                if (!repo) {
                    var repoCell = document.querySelector('.wp-list-table tbody tr td strong');
                    repo = repoCell ? repoCell.textContent.trim() : '';
                }
                if (!repo) { res.innerHTML = '<span style="color:#dc3232;font-weight:600;">FAIL</span>'; det.textContent = 'No repo candidate available.'; return; }
                var xhr = new XMLHttpRequest();
                xhr.open('POST', ajaxurl, true);
                xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded; charset=UTF-8');
                xhr.onload = function(){
                    try {
                        var json = JSON.parse(xhr.responseText||'{}');
                        var ok = json && json.success && json.data && typeof json.data === 'object';
                        var keys = ['repoName','isPlugin','isInstalled','isActive','pluginFile','settingsUrl','checking','installing','error'];
                        var hasAll = ok;
                        if (ok) { for (var i=0;i<keys.length;i++){ if(!(keys[i] in json.data)){ hasAll=false; break; } } }
                        if (ok && hasAll) {
                            res.innerHTML = '<span style="color:#46b450;font-weight:600;">PASS</span>';
                            det.textContent = 'OK for ' + json.data.repoName;
                        } else {
                            res.innerHTML = '<span style="color:#dc3232;font-weight:600;">FAIL</span>';
                            det.textContent = 'Invalid response';
                        }
                    } catch(e){ res.innerHTML = '<span style="color:#dc3232;font-weight:600;">FAIL</span>'; det.textContent = 'Exception parsing response.'; }
                };
                xhr.onerror = function(){ res.innerHTML = '<span style="color:#dc3232;font-weight:600;">FAIL</span>'; det.textContent = 'XHR error'; };
                xhr.send('action=kiss_sbi_get_row_status&nonce=<?php echo esc_js(wp_create_nonce('kiss_sbi_admin_nonce')); ?>&repo_name=' + encodeURIComponent(repo));
            } catch(e) {
                res.innerHTML = '<span style="color:#dc3232;font-weight:600;">FAIL</span>';
                det.textContent = e && e.message ? e.message : 'Error';
            }
        }

        // PQS usage probe
        function runPqsProbe(){
            function setRow(pass, details){
                var r = document.getElementById('test-pqs_used-result');
                var d = document.getElementById('test-pqs_used-details');
                if (!r || !d) return;
                r.innerHTML = pass ? '<span style="color:#46b450;font-weight:600;">PASS</span>' : '<span style="color:#dc3232;font-weight:600;">FAIL</span>';
                d.textContent = details;
            }
            try{
                var raw = localStorage.getItem('pqs_plugin_cache');
                var len = 0; try { var arr = JSON.parse(raw||'[]'); len = Array.isArray(arr)?arr.length:0; } catch(e) {}
                var status = (typeof window.pqsCacheStatus === 'function') ? window.pqsCacheStatus() : (len > 0 ? 'unknown' : 'missing');
                var used = (status === 'fresh') || (status === 'unknown' && len > 0);
                var detail = 'status=' + status + ', entries=' + len + (status==='unknown' ? ' (via localStorage)' : '');
                setRow(!!used, detail);
            }catch(e){ setRow(false, 'Error: ' + (e && e.message ? e.message : e)); }
        }

        (function(){
            if (document.readyState === 'complete' || document.readyState === 'interactive') { runPqsProbe(); runAjaxContractProbe(); }
            else document.addEventListener('DOMContentLoaded', function(){ runPqsProbe(); runAjaxContractProbe(); });
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