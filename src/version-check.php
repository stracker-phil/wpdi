<?php
/**
 * WPDI Version Compatibility Check
 *
 * Ensures only one version of WPDI loads when multiple plugins bundle it.
 * This file is loaded before any WPDI classes are declared.
 */

$module_version = '1.0.3';

if ( ! defined( 'WPDI_VERSION' ) ) {
	define( 'WPDI_VERSION', $module_version );
}

// Check if WPDI is already loaded by another plugin
if ( class_exists( 'WPDI\Scope' ) ) {
	// ONLY fail if the loaded version is older than required
	if ( version_compare( WPDI_VERSION, $module_version, '<' ) ) {
		$message = sprintf(
			'WPDI Version Conflict: requires v%s, but v%s is already loaded. '
			. 'Use a scoper, update WPDI in all plugins, or install as a mu-plugin. '
			. 'See: https://github.com/stracker-phil/wpdi/blob/main/docs/mu-plugin-installation.md',
			$module_version,
			WPDI_VERSION
		);

		if ( function_exists( 'wp_die' ) ) {
			wp_die( esc_html( $message ), 'WPDI Version Conflict' );
		}

		throw new RuntimeException( $message );
	}
}

// When reaching this point:
// No older version of the WPDI library was loaded. Proceed as planned.
