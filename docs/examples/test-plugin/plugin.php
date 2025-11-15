<?php
/**
 * Plugin Name: WPDI Test Plugin
 * Description: Example plugin using WordPress-native WPDI
 * Version: 1.0.0
 */

// Prevent direct access
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/vendor/wpdi/init.php';

class Test_Plugin extends WPDI\Scope {
	protected function bootstrap( WPDI\Resolver $r ): void {
		// Composition root - only place with service resolution
		$app = $r->get( Test_Application::class );
		$app->run();
	}
}

// Initialize the plugin
new Test_Plugin( __FILE__ );