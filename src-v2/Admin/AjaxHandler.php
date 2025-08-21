<?php
namespace KissSmartBatchInstaller\V2\Admin;

class AjaxHandler
{
    private $pluginService;
    private $installationService;

    public function __construct($pluginService, $installationService)
    {
        $this->pluginService = $pluginService;
        $this->installationService = $installationService;
    }

    public function init(): void
    {
        // Placeholder for AJAX actions
    }
}
