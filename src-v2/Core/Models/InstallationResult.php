<?php
namespace KissSmartBatchInstaller\V2\Core\Models;

class InstallationResult
{
    public bool $success = false;
    public string $plugin_dir = '';
    public string $plugin_file = '';
    public bool $activated = false;
    public array $logs = [];
}
