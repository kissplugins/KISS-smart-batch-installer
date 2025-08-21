<?php
namespace KissSmartBatchInstaller\V2;

use KissSmartBatchInstaller\V2\Admin\Controllers\PluginsController;
use KissSmartBatchInstaller\V2\Admin\Controllers\SettingsController;
use KissSmartBatchInstaller\V2\Admin\Views\PluginsListTable;
use KissSmartBatchInstaller\V2\Admin\AjaxHandler;
use KissSmartBatchInstaller\V2\Core\Services\PluginService;
use KissSmartBatchInstaller\V2\Core\Services\GitHubService;
use KissSmartBatchInstaller\V2\Core\Services\InstallationService;
use KissSmartBatchInstaller\V2\Core\Services\CacheService;
use KissSmartBatchInstaller\V2\Core\Integration\PQSIntegration;

class Container
{
    private $instances = [];

    public function get(string $id)
    {
        if (!isset($this->instances[$id])) {
            $this->instances[$id] = $this->create($id);
        }
        return $this->instances[$id];
    }

    private function create(string $id)
    {
        switch ($id) {
            case 'PluginsController':
                return new PluginsController($this);
            case 'SettingsController':
                return new SettingsController($this);
            case 'PluginsListTable':
                return new PluginsListTable($this->get('PluginService'));
            case 'AjaxHandler':
                return new AjaxHandler($this->get('PluginService'), $this->get('InstallationService'));
            case 'PluginService':
                return new PluginService();
            case 'GitHubService':
                return new GitHubService();
            case 'InstallationService':
                return new InstallationService();
            case 'CacheService':
                return new CacheService();
            case 'PQSIntegration':
                return new PQSIntegration();
            default:
                throw new \InvalidArgumentException('Unknown service: ' . $id);
        }
    }
}
