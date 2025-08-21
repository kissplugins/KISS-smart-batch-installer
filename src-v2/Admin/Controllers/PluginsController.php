<?php
namespace KissSmartBatchInstaller\V2\Admin\Controllers;

use KissSmartBatchInstaller\V2\Admin\Views\PluginsListTable;

class PluginsController
{
    private $container;
    private $listTable;

    public function __construct($container)
    {
        $this->container = $container;
        $this->listTable = $container->get('PluginsListTable');
    }

    public function render(): void
    {
        $listTable = $this->listTable;
        include KISS_SBI_PLUGIN_DIR . 'views/admin/plugins-list.php';
    }
}
