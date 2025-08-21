<?php
namespace KissSmartBatchInstaller\V2\Core\Models;

class Repository
{
    public string $name;
    public string $url;
    public string $description = '';
    public string $language = '';
    public ?string $updated_at = null;

    public function __construct(string $name, string $url)
    {
        $this->name = $name;
        $this->url = $url;
    }
}
