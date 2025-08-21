<?php
/**
 * WordPress List Table for KISS Smart Batch Installer v2
 * 
 * Provides a familiar WordPress-native interface for managing GitHub repositories
 * that extends the standard WP_List_Table class.
 */

namespace KissSmartBatchInstaller\V2\Admin\Views;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class PluginsListTable extends \WP_List_Table
{
    private $pluginService;
    
    public function __construct($pluginService)
    {
        parent::__construct([
            'singular' => 'repository',
            'plural' => 'repositories',
            'ajax' => true
        ]);
        
        $this->pluginService = $pluginService;
    }

    /**
     * Define table columns
     */
    public function get_columns(): array
    {
        return [
            'cb' => '<input type="checkbox" />',
            'plugin' => __('Plugin', 'kiss-smart-batch-installer'),
            'description' => __('Description', 'kiss-smart-batch-installer'),
            'actions' => __('Actions', 'kiss-smart-batch-installer')
        ];
    }

    /**
     * Render checkbox column
     */
    public function column_cb($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $disabled = $plugin->isInstalled() ? 'disabled' : '';
        
        return sprintf(
            '<input type="checkbox" name="repositories[]" value="%s" %s />',
            esc_attr($item['name']),
            $disabled
        );
    }

    /**
     * Render plugin column (name + state)
     */
    public function column_plugin($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        
        $title = sprintf(
            '<strong><a href="%s" target="_blank">%s</a></strong>',
            esc_url($item['url']),
            esc_html($item['name'])
        );

        // Add state indicator like WordPress plugins page
        $state_label = $plugin->getStateLabel();
        if ($state_label) {
            $title .= ' — <span class="plugin-state">' . esc_html($state_label) . '</span>';
        }

        return $title;
    }

    /**
     * Render description column
     */
    public function column_description($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $html = esc_html($item['description']);
        
        // Add metadata row like WordPress plugins page
        $meta = [];
        if ($plugin->getVersion()) {
            $meta[] = sprintf(__('Version %s', 'kiss-smart-batch-installer'), esc_html($plugin->getVersion()));
        }
        if (!empty($item['language'])) {
            $meta[] = esc_html($item['language']);
        }
        if (!empty($item['updated_at'])) {
            $meta[] = sprintf(
                __('Updated %s ago', 'kiss-smart-batch-installer'),
                human_time_diff(strtotime($item['updated_at']))
            );
        }
        
        if (!empty($meta)) {
            $html .= '<br><em>' . implode(' | ', $meta) . '</em>';
        }

        return $html;
    }

    /**
     * Render actions column
     */
    public function column_actions($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $buttons = $plugin->getActionButtons();
        
        $html = [];
        foreach ($buttons as $button) {
            // Skip buttons with false condition
            if (isset($button['condition']) && !$button['condition']) {
                continue;
            }
            
            $classes = ['button'];
            if ($button['primary'] ?? false) $classes[] = 'button-primary';
            if ($button['secondary'] ?? false) $classes[] = 'button-secondary';
            
            if (isset($button['url'])) {
                $html[] = sprintf(
                    '<a href="%s" class="%s">%s</a>',
                    esc_url($button['url']),
                    implode(' ', $classes),
                    esc_html($button['text'])
                );
            } else {
                $html[] = sprintf(
                    '<button type="button" class="%s" data-action="%s" data-repo="%s">%s</button>',
                    implode(' ', $classes),
                    esc_attr($button['type']),
                    esc_attr($item['name']),
                    esc_html($button['text'])
                );
            }
        }
        
        return implode(' ', $html);
    }

    /**
     * Prepare table items
     */
    public function prepare_items(): void
    {
        // Items will be set by the controller
        $this->_column_headers = [$this->get_columns(), [], []];
    }

    /**
     * Handle bulk actions
     */
    public function get_bulk_actions(): array
    {
        return [
            'install' => __('Install', 'kiss-smart-batch-installer')
        ];
    }

    /**
     * Display when no items found
     */
    public function no_items(): void
    {
        _e('No repositories found. Please check your GitHub organization settings.', 'kiss-smart-batch-installer');
    }

    /**
     * Add row attributes for styling
     */
    public function single_row($item): void
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $class = $plugin->isInstalled() ? 'plugin-installed' : '';
        
        echo '<tr class="' . esc_attr($class) . '" data-repo="' . esc_attr($item['name']) . '">';
        $this->single_row_columns($item);
        echo '</tr>';
    }
}
