<?php
namespace KissSmartBatchInstaller\V2\Core\Services;

use KissSmartBatchInstaller\V2\Core\Models\Plugin;

class PluginService
{
    public function getPlugin(string $repositoryName): Plugin
    {
        return new Plugin($repositoryName);
    }
}
