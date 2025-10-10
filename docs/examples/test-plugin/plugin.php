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
	protected function bootstrap() : void {
		// Composition root - only place with container access
		$app = $this->get( 'Test_Application' );
		$app->run();
	}
}

// Initialize the plugin
new Test_Plugin();