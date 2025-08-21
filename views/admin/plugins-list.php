<?php
use KissSmartBatchInstaller\V2\Admin\Views\PluginsListTable;
/** @var PluginsListTable $listTable */
?>
<div class="wrap">
    <h1><?php echo esc_html__('GitHub Repositories', 'kiss-smart-batch-installer'); ?></h1>
    <form method="post">
        <?php
        $listTable->prepare_items();
        $listTable->display();
        ?>
    </form>
</div>
