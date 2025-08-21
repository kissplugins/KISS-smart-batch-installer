<?php
namespace KissSmartBatchInstaller\V2\Core\Services;

class CacheService
{
    public function get(string $key)
    {
        return get_transient($key);
    }

    public function set(string $key, $value, int $expiration = 0): void
    {
        set_transient($key, $value, $expiration);
    }
}
