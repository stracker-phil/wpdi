<?php
/**
 * WP-CLI command for compiling WPDI container cache
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Compiler;

/**
 * Compile WPDI container for production performance
 */
class Compile_Command {
	/**
	 * Compile WPDI container cache
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--force]
	 * : Force recompilation even if cache exists
	 *
	 * ## EXAMPLES
	 *
	 *     wp di compile
	 *     wp di compile --path=/path/to/module --force
	 */
	public function __invoke( $args, $assoc_args ) {
		$path  = $assoc_args['path'] ?? getcwd();
		$force = isset( $assoc_args['force'] );

		if ( ! is_dir( $path ) ) {
			WP_CLI::error( "Directory does not exist: {$path}" );
		}

		$compiler = new Compiler( $path );

		if ( $compiler->exists() && ! $force ) {
			WP_CLI::warning( 'Cache file already exists. Use --force to overwrite.' );

			return;
		}

		WP_CLI::log( "Discovering classes in {$path}/src..." );

		$discovery = new Auto_Discovery();
		$classes   = $discovery->discover( $path . '/src' );

		if ( empty( $classes ) ) {
			WP_CLI::warning( 'No classes found to compile.' );

			return;
		}

		WP_CLI::log( 'Found ' . count( $classes ) . ' classes:' );
		foreach ( $classes as $class => $metadata ) {
			WP_CLI::log( "  - {$class}" );
		}

		// Check for manual configuration
		$config_file    = $path . '/wpdi-config.php';
		$manual_configs = array();
		if ( file_exists( $config_file ) ) {
			WP_CLI::log( 'Loading configuration from wpdi-config.php...' );
			$config         = require $config_file;
			$manual_configs = array_keys( $config );
		}

		WP_CLI::log( 'Compiling container cache...' );

		if ( $compiler->write( $classes ) ) {
			WP_CLI::success( 'Container compiled successfully to ' . $compiler->get_cache_file() );

			// Show statistics
			WP_CLI::log( 'Total discovered classes: ' . count( $classes ) );
			if ( ! empty( $manual_configs ) ) {
				WP_CLI::log( 'Manual configurations: ' . count( $manual_configs ) );
				foreach ( $manual_configs as $config_class ) {
					WP_CLI::log( "  - {$config_class}" );
				}
			}
		} else {
			WP_CLI::error( 'Failed to compile container cache' );
		}
	}
}
