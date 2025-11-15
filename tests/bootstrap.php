<?php
/**
 * PHPUnit bootstrap file for WPDI tests
 */

// Load Composer autoloader
require_once dirname( __DIR__ ) . '/vendor/autoload.php';

// Define WordPress constants that might be used
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', '/tmp/wordpress/' );
}

// Mock WordPress functions that WPDI might use
if ( ! function_exists( 'wp_get_environment_type' ) ) {
	function wp_get_environment_type(): string {
		// Allow tests to override via environment variable
		$env_type = getenv( 'WP_ENVIRONMENT_TYPE' );

		return $env_type !== false ? $env_type : 'development';
	}
}

if ( ! function_exists( 'get_option' ) ) {
	function get_option( string $option, $default = false ) {
		return $default;
	}
}

if ( ! function_exists( 'wp_mkdir_p' ) ) {
	function wp_mkdir_p( string $target ): bool {
		// Simple implementation for testing
		if ( file_exists( $target ) ) {
			return is_dir( $target );
		}

		return mkdir( $target, 0777, true );
	}
}

if ( ! function_exists( 'current_time' ) ) {
	function current_time( string $type = 'mysql', int $gmt = 0 ): string {
		if ( $type === 'mysql' ) {
			return date( 'Y-m-d H:i:s' );
		}

		return date( $type );
	}
}

// Load WPDI core files (since init.php has WordPress checks)
require_once dirname( __DIR__ ) . '/src/Exceptions/Wpdi_Exception.php';
require_once dirname( __DIR__ ) . '/src/Exceptions/Container_Exception.php';
require_once dirname( __DIR__ ) . '/src/Exceptions/Not_Found_Exception.php';
require_once dirname( __DIR__ ) . '/src/Exceptions/Circular_Dependency_Exception.php';
require_once dirname( __DIR__ ) . '/src/Auto_Discovery.php';
require_once dirname( __DIR__ ) . '/src/Compiler.php';
require_once dirname( __DIR__ ) . '/src/Resolver.php';
require_once dirname( __DIR__ ) . '/src/Container.php';
require_once dirname( __DIR__ ) . '/src/Scope.php';

// Load WP_CLI mocks
require_once __DIR__ . '/mocks/WP_CLI.php';
require_once __DIR__ . '/mocks/WP_CLI_Utils.php';

// Load WP-CLI command classes for testing
require_once dirname( __DIR__ ) . '/src/Commands/Compile_Command.php';
require_once dirname( __DIR__ ) . '/src/Commands/Discover_Command.php';
require_once dirname( __DIR__ ) . '/src/Commands/Clear_Command.php';

echo "WPDI Test Bootstrap loaded\n";
