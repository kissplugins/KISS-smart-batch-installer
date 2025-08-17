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

        wp_enqueue_script(
            'kiss-sbi-admin',
            KISS_SBI_PLUGIN_URL . 'assets/admin.js',
            ['jquery'],
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
            'strings' => [
                'installing' => __('Installing...', 'kiss-smart-batch-installer'),
                'installed' => __('Installed', 'kiss-smart-batch-installer'),
                'error' => __('Error', 'kiss-smart-batch-installer'),
                'scanning' => __('Scanning...', 'kiss-smart-batch-installer'),
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

        $repositories = $this->github_scraper->getRepositories();

        ?>
        <div class="wrap">
            <h1><?php _e('KISS Smart Batch Installer', 'kiss-smart-batch-installer'); ?></h1>
            
            <div class="kiss-sbi-header">
                <p><?php printf(__('Showing repositories from: <strong>%s</strong>', 'kiss-smart-batch-installer'), esc_html($github_org)); ?></p>

                <div class="kiss-sbi-actions">
                    <button type="button" class="button" id="kiss-sbi-refresh-repos">
                        <?php _e('Refresh Repositories', 'kiss-smart-batch-installer'); ?>
                    </button>

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
                            <button type="button" class="button button-small kiss-sbi-install-single" data-repo="<?php echo esc_attr($repo['name']); ?>" disabled>
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
        </p>
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