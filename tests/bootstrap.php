<?php
/**
 * PHPUnit bootstrap file for WPDI tests
 */

// Load Composer autoloader
require_once dirname(__DIR__) . '/vendor/autoload.php';

// Define WordPress constants that might be used
if (!defined('ABSPATH')) {
    define('ABSPATH', '/tmp/wordpress/');
}

// Mock WordPress functions that WPDI might use
if (!function_exists('wp_get_environment_type')) {
    function wp_get_environment_type(): string {
        return 'development';
    }
}

if (!function_exists('get_option')) {
    function get_option(string $option, $default = false) {
        return $default;
    }
}

if (!function_exists('wp_mkdir_p')) {
    function wp_mkdir_p(string $target): bool {
        // Simple implementation for testing
        if (file_exists($target)) {
            return is_dir($target);
        }
        return mkdir($target, 0777, true);
    }
}

if (!function_exists('current_time')) {
    function current_time(string $type = 'mysql', int $gmt = 0): string {
        if ($type === 'mysql') {
            return date('Y-m-d H:i:s');
        }
        return date($type);
    }
}

// Load WPDI core files (since init.php has WordPress checks)
require_once dirname(__DIR__) . '/src/exceptions/class-container-exception.php';
require_once dirname(__DIR__) . '/src/exceptions/class-not-found-exception.php';
require_once dirname(__DIR__) . '/src/class-auto-discovery.php';
require_once dirname(__DIR__) . '/src/class-compiler.php';
require_once dirname(__DIR__) . '/src/class-container.php';
require_once dirname(__DIR__) . '/src/class-scope.php';

echo "WPDI Test Bootstrap loaded\n";
