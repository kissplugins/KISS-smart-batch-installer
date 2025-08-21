<?php
/**
 * Main template for KISS Smart Batch Installer v2 plugins list
 * 
 * This template will be used when the full functionality is implemented in Phase 2.
 * For Phase 1, the controller renders a basic interface directly.
 */

if (!defined('ABSPATH')) {
    exit;
}

?>
<div class="wrap">
    <h1>
        <?php _e('GitHub Repositories (v2)', 'kiss-smart-batch-installer'); ?>
        <?php if (isset($pagination['total_items'])): ?>
            <span class="title-count theme-count"><?php echo $pagination['total_items']; ?></span>
        <?php endif; ?>
    </h1>
    
    <?php if (!empty($github_org)): ?>
        <p class="description">
            <?php printf(__('Showing repositories from: <strong>%s</strong>', 'kiss-smart-batch-installer'), esc_html($github_org)); ?>
        </p>
    <?php endif; ?>
    
    <div class="kiss-sbi-v2-phase1">
        <h3><?php _e('Phase 2 Coming Soon', 'kiss-smart-batch-installer'); ?></h3>
        <p><?php _e('This template will be used when the full v2 functionality is implemented in Phase 2. The new interface will feature a WordPress-native list table with improved performance and user experience.', 'kiss-smart-batch-installer'); ?></p>
    </div>
    
    <?php if (isset($list_table)): ?>
        <form id="plugins-filter" method="get">
            <input type="hidden" name="page" value="<?php echo esc_attr($_REQUEST['page']); ?>" />
            
            <?php $list_table->display(); ?>
            
            <div class="tablenav bottom">
                <div class="alignleft actions bulkactions">
                    <select name="action2">
                        <option value="-1"><?php _e('Bulk Actions', 'kiss-smart-batch-installer'); ?></option>
                        <option value="install"><?php _e('Install', 'kiss-smart-batch-installer'); ?></option>
                    </select>
                    <input type="submit" id="bulk-install" class="button action" value="<?php _e('Apply', 'kiss-smart-batch-installer'); ?>">
                    
                    <label>
                        <input type="checkbox" id="activate-after-install" value="1">
                        <?php _e('Activate after installation', 'kiss-smart-batch-installer'); ?>
                    </label>
                </div>
                
                <div class="alignright">
                    <button type="button" class="button" id="refresh-repositories">
                        <?php _e('Refresh Repositories', 'kiss-smart-batch-installer'); ?>
                    </button>
                    <button type="button" class="button" id="clear-cache">
                        <?php _e('Clear Cache', 'kiss-smart-batch-installer'); ?>
                    </button>
                </div>
                
                <?php if (isset($list_table)): ?>
                    <?php $list_table->pagination('bottom'); ?>
                <?php endif; ?>
            </div>
        </form>
    <?php endif; ?>
</div>
