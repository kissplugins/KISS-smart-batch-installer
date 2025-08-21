<?php
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
            'plural'   => 'repositories',
            'ajax'     => true,
        ]);
        $this->pluginService = $pluginService;
    }

    public function get_columns(): array
    {
        return [
            'cb'         => '<input type="checkbox" />',
            'plugin'     => __('Plugin', 'kiss-smart-batch-installer'),
            'description'=> __('Description', 'kiss-smart-batch-installer'),
            'actions'    => __('Actions', 'kiss-smart-batch-installer'),
        ];
    }

    public function column_cb($item): string
    {
        $plugin   = $this->pluginService->getPlugin($item['name']);
        $disabled = $plugin->isInstalled() ? 'disabled' : '';
        return sprintf('<input type="checkbox" name="repositories[]" value="%s" %s />', esc_attr($item['name']), $disabled);
    }

    public function column_plugin($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $title  = sprintf('<strong><a href="%s" target="_blank">%s</a></strong>', esc_url($item['url']), esc_html($item['name']));
        $state_label = $plugin->getStateLabel();
        if ($state_label) {
            $title .= ' — <span class="plugin-state">' . esc_html($state_label) . '</span>';
        }
        return $title;
    }

    public function column_description($item): string
    {
        $plugin = $this->pluginService->getPlugin($item['name']);
        $html   = esc_html($item['description']);
        $meta   = [];
        if ($plugin->getVersion()) {
            $meta[] = sprintf(__('Version %s', 'kiss-smart-batch-installer'), esc_html($plugin->getVersion()));
        }
        if (!empty($item['language'])) {
            $meta[] = esc_html($item['language']);
        }
        if (!empty($item['updated_at'])) {
            $meta[] = sprintf(__('Updated %s ago', 'kiss-smart-batch-installer'), human_time_diff(strtotime($item['updated_at'])));
        }
        if (!empty($meta)) {
            $html .= '<br><em>' . implode(' | ', $meta) . '</em>';
        }
        return $html;
    }

    public function column_actions($item): string
    {
        $plugin  = $this->pluginService->getPlugin($item['name']);
        $buttons = $plugin->getActionButtons();
        $html    = [];
        foreach ($buttons as $button) {
            if (isset($button['condition']) && !$button['condition']) {
                continue;
            }
            $classes = ['button'];
            if ($button['primary'] ?? false) {
                $classes[] = 'button-primary';
            }
            if ($button['secondary'] ?? false) {
                $classes[] = 'button-secondary';
            }
            if (isset($button['url'])) {
                $html[] = sprintf('<a href="%s" class="%s">%s</a>', esc_url($button['url']), implode(' ', $classes), esc_html($button['text']));
            } else {
                $html[] = sprintf('<button type="button" class="%s" data-action="%s" data-repo="%s">%s</button>', implode(' ', $classes), esc_attr($button['type']), esc_attr($item['name']), esc_html($button['text']));
            }
        }
        return implode(' ', $html);
    }

    public function prepare_items(): void
    {
        $this->items = []; // Placeholder
        $this->_column_headers = [$this->get_columns(), [], []];
    }
}
