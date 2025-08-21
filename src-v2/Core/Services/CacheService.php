<?php
/**
 * Cache Service for KISS Smart Batch Installer v2
 * 
 * Provides caching functionality using WordPress transients
 * for compatibility with v1 data.
 */

namespace KissSmartBatchInstaller\V2\Core\Services;

class CacheService
{
    /**
     * Get cached value
     */
    public function get(string $key, $default = null)
    {
        // Use WordPress transients for compatibility with v1
        $transient_key = 'kiss_sbi_' . sanitize_key($key);
        $value = get_transient($transient_key);
        
        return $value !== false ? $value : $default;
    }
    
    /**
     * Set cached value
     */
    public function set(string $key, $value, int $ttl = 3600): bool
    {
        $transient_key = 'kiss_sbi_' . sanitize_key($key);
        return set_transient($transient_key, $value, $ttl);
    }
    
    /**
     * Delete cached value
     */
    public function delete(string $key): bool
    {
        $transient_key = 'kiss_sbi_' . sanitize_key($key);
        return delete_transient($transient_key);
    }
    
    /**
     * Clear cache with optional prefix
     */
    public function clear(string $prefix = ''): int
    {
        global $wpdb;
        
        $pattern = $wpdb->esc_like('_transient_kiss_sbi_' . sanitize_key($prefix)) . '%';
        $timeout_pattern = $wpdb->esc_like('_transient_timeout_kiss_sbi_' . sanitize_key($prefix)) . '%';
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
            $pattern,
            $timeout_pattern
        ));
        
        return (int) $deleted;
    }
}
