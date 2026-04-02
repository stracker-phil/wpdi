<?php
/**
 * WP-CLI command for compiling WPDI container cache
 */

namespace WPDI\Commands;

use WPDI\Auto_Discovery;
use WPDI\Compiler;

/**
 * Compile WPDI container for production performance
 */
class Compile_Command extends Command {
	/**
	 * Compile WPDI container cache
	 *
	 * @subcommand compile
	 * @synopsis [--dir=<dir>] [--autowiring-paths=<paths>] [--force] [--format=<format>]
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * [--force]
	 * : Force recompilation even if cache exists
	 *
	 * [--format=<format>]
	 * : Output format: ascii uses +/- borders instead of box-drawing characters
	 *
	 * ## EXAMPLES
	 *
	 *     wp di compile
	 *     wp di compile --dir=/path/to/module --force
	 *     wp di compile --autowiring-paths=src,modules/auth/src
	 */
	public function __invoke( $args, $assoc_args ) {
		$path             = $assoc_args['dir'] ?? getcwd();
		$force            = isset( $assoc_args['force'] );
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );
		$this->parse_format_flag( $assoc_args );

		if ( ! is_dir( $path ) ) {
			$this->error( "Directory does not exist: {$path}" );
		}

		$compiler = new Compiler( $path );

		// Check cache directory is writable before doing any work
		if ( ! $compiler->ensure_dir() ) {
			$this->error( "Cache directory is not writable: {$path}/cache\nEnsure the directory exists and has write permissions." );
		}

		if ( $compiler->exists() && ! $force ) {
			$this->warning( 'Cache file already exists. Use --force to overwrite.' );

			return;
		}

		$this->log( 'Discovering classes:' );

		$discovery = new Auto_Discovery();
		$classes   = array();

		// Discover from each autowiring path and render a table per source.
		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				$this->warning( "Autowiring path does not exist: {$full_path}" );
				continue;
			}

			$discovered = $discovery->discover( $full_path );
			$classes    = array_merge( $classes, $discovered );

			$rows = array();
			foreach ( $discovered as $class => $metadata ) {
				$rows[] = array(
					'type'  => $this->format_type_label( $this->inspector->get_type( $class ) ),
					'class' => $class,
				);
			}

			$this->table(
				$rows,
				array( 'type', 'class' ),
				array(
					'type'  => 'type_label',
					'class' => 'class_name',
				),
				$autowiring_path
			);
		}

		if ( empty( $classes ) ) {
			$this->warning( 'No classes found to compile.' );

			return;
		}

		$this->log( 'Found ' . count( $classes ) . ' classes.' );

		// Check for manual configuration
		$config_file    = $path . '/wpdi-config.php';
		$config         = array();
		$manual_configs = array();
		if ( file_exists( $config_file ) ) {
			$this->log( 'Loading configuration from wpdi-config.php...' );
			$config         = require $config_file;
			$manual_configs = array_keys( $config );

			$rows = array();
			foreach ( $manual_configs as $config_class ) {
				$rows[] = array(
					'type'  => $this->format_type_label( $this->inspector->get_type( $config_class ) ),
					'class' => $config_class,
				);
			}

			$this->table(
				$rows,
				array( 'type', 'class' ),
				array(
					'type'  => 'type_label',
					'class' => 'class_name',
				),
				'wpdi-config.php'
			);
		}

		$this->log( 'Compiling container cache...' );

		if ( $compiler->write( $classes, $config ) ) {
			$this->success( 'Container compiled successfully to ' . $compiler->get_cache_file() );

			// Show statistics
			$this->log( 'Total discovered classes: ' . count( $classes ) );
			if ( ! empty( $manual_configs ) ) {
				$this->log( 'Manual configurations: ' . count( $manual_configs ) );
			}
		} else {
			$this->error( 'Failed to compile container cache' );
		}
	}

}
