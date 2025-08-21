<?php
if (!defined('ABSPATH')) exit;

// PSR-4 Autoloader for v2
spl_autoload_register(function ($class) {
    if (strpos($class, 'KissSmartBatchInstaller\\V2\\') === 0) {
        $relative_class = str_replace('KissSmartBatchInstaller\\V2\\', '', $class);
        $file = KISS_SBI_PLUGIN_DIR . 'src-v2/' . str_replace('\\\\', '/', $relative_class) . '.php';

        if (file_exists($file)) {
            require $file;
        }
    }
});

// Development constant for easy testing
if (!defined('KISS_SBI_FORCE_V2')) {
    define('KISS_SBI_FORCE_V2', WP_DEBUG && isset($_GET['kiss_sbi_v2']));
}
