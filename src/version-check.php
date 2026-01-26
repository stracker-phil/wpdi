<?php
/**
 * WPDI Version Compatibility Check
 *
 * Ensures only one version of WPDI loads when multiple plugins bundle it.
 * This file is loaded before any WPDI classes are declared.
 */

$module_version = '1.0.2';

if ( ! defined( 'WPDI_VERSION' ) ) {
	define( 'WPDI_VERSION', $module_version );
}

// Check if WPDI is already loaded by another plugin
if ( class_exists( 'WPDI\Scope' ) ) {
	// ONLY fail if the loaded version is older than required
	if ( version_compare( WPDI_VERSION, $module_version, '<' ) ) {
		/** @noinspection ForgottenDebugOutputInspection */
		wp_die(
			sprintf(
				'<h1>WPDI Version Conflict</h1>' .
				'<p>A plugin requires WPDI v%s, but an older version (v%s) is already loaded.</p>' .
				'<p><strong>Solution:</strong> Use a scoper for this plugin OR update WPDI in all plugins to their latest versions OR install WPDI as a mu-plugin to control the version. ' .
				'See: <a href="https://github.com/stracker-phil/wpdi/blob/main/docs/mu-plugin-installation.md" target="_blank">MU-Plugin Installation Guide</a></p>',
				esc_html( $module_version ),
				esc_html( WPDI_VERSION )
			),
			'WPDI Version Conflict'
		);
	}
}

// When reaching this point:
// No older version of the WPDI library was loaded. Proceed as planned.
