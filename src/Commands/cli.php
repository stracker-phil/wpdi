<?php
/**
 * WP-CLI command registration for WPDI
 *
 * Registers all WPDI WP-CLI commands. This file is loaded only when WP-CLI is available.
 */

namespace WPDI\Commands;

use WP_CLI;

// Exit if WP-CLI is not available
if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Load individual command classes
require_once __DIR__ . '/Compile_Command.php';
require_once __DIR__ . '/Discover_Command.php';
require_once __DIR__ . '/Clear_Command.php';

// Register commands with WP-CLI
WP_CLI::add_command( 'di compile', __NAMESPACE__ . '\\Compile_Command' );
WP_CLI::add_command( 'di discover', __NAMESPACE__ . '\\Discover_Command' );
WP_CLI::add_command( 'di clear', __NAMESPACE__ . '\\Clear_Command' );
