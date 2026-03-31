<?php
/**
 * Standalone WP-CLI command registration.
 *
 * PSR-4 autoloaded — call WPDI\Commands\Cli::register_commands() from any
 * plugin without needing to instantiate Scope first.
 */

declare( strict_types = 1 );

namespace WPDI\Commands;

use WP_CLI;

/**
 * Registers WPDI's "wp di" WP-CLI commands.
 */
class Cli {

	/**
	 * Register the "wp di" WP-CLI commands.
	 *
	 * Safe to call at any point during plugin load. Uses a static flag
	 * internally, so multiple calls (or a later Scope instantiation) are
	 * harmless. A no-op when WP-CLI is not active.
	 *
	 * Usage in a plugin's main file:
	 *
	 *     WPDI\Commands\Cli::register_commands();
	 */
	public static function register_commands(): void {
		static $registered = false;

		if ( $registered || ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		$registered = true;

		require_once __DIR__ . '/Compile_Command.php';
		require_once __DIR__ . '/List_Command.php';
		require_once __DIR__ . '/Clear_Command.php';
		require_once __DIR__ . '/Inspect_Command.php';

		WP_CLI::add_command( 'di compile', Compile_Command::class );
		WP_CLI::add_command( 'di list', List_Command::class );
		WP_CLI::add_command( 'di clear', Clear_Command::class );
		WP_CLI::add_command( 'di inspect', Inspect_Command::class );
	}
}
