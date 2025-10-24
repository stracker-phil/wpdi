<?php
/**
 * WP-CLI command for compiling WPDI containers (WordPress Coding Standards)
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Compiler;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

WP_CLI::add_command( 'di compile', __NAMESPACE__ . '\\Compile_Command' );
WP_CLI::add_command( 'di discover', __NAMESPACE__ . '\\Compile_Command::discover' );
WP_CLI::add_command( 'di clear', __NAMESPACE__ . '\\Compile_Command::clear' );

class Compile_Command {
	/**
	 * Compile WPDI container for production performance
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

		$cache_file = $path . '/cache/wpdi-container.php';

		if ( file_exists( $cache_file ) && ! $force ) {
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
		foreach ( $classes as $class ) {
			WP_CLI::log( "  - {$class}" );
		}

		// Create bindings for auto-discovered classes
		$bindings = array();
		foreach ( $classes as $class ) {
			$bindings[ $class ] = array(
				'factory'   => function () use ( $class ) {
					// This would be the autowiring logic
					return "autowire:{$class}";
				},
				'singleton' => true,
			);
		}

		// Load manual configuration if exists
		$config_file = $path . '/wpdi-config.php';
		if ( file_exists( $config_file ) ) {
			WP_CLI::log( 'Loading configuration from wpdi-config.php...' );
			$config = require $config_file;
			foreach ( $config as $abstract => $factory ) {
				$bindings[ $abstract ] = array(
					'factory'   => $factory,
					'singleton' => true,
				);
			}
		}

		WP_CLI::log( 'Compiling container cache...' );

		$compiler = new Compiler();
		if ( $compiler->compile( $bindings, $cache_file ) ) {
			WP_CLI::success( "Container compiled successfully to {$cache_file}" );

			// Show analysis
			$analysis = $compiler->analyze_dependencies( $bindings );
			WP_CLI::log( "Total services: {$analysis['total_services']}" );
			WP_CLI::log( 'Autowired: ' . count( $analysis['autowired'] ) );
			WP_CLI::log( 'Manual: ' . count( $analysis['manual'] ) );
			WP_CLI::log( 'Interfaces: ' . count( $analysis['interfaces'] ) );
		} else {
			WP_CLI::error( 'Failed to compile container cache' );
		}
	}

	/**
	 * Discover classes without compiling
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv) (default: table)
	 */
	public function discover( $args, $assoc_args ) {
		$path   = $assoc_args['path'] ?? getcwd();
		$format = $assoc_args['format'] ?? 'table';

		if ( ! is_dir( $path ) ) {
			WP_CLI::error( "Directory does not exist: {$path}" );
		}

		$discovery = new Auto_Discovery();
		$classes   = $discovery->discover( $path . '/src' );

		if ( empty( $classes ) ) {
			WP_CLI::log( "No classes found in {$path}/src" );

			return;
		}

		$output = array();
		foreach ( $classes as $class ) {
			$output[] = array(
				'class'       => $class,
				'type'        => $this->get_class_type( $class ),
				'autowirable' => $this->is_autowirable( $class ) ? 'yes' : 'no',
			);
		}

		WP_CLI\Utils\format_items( $format, $output, array( 'class', 'type', 'autowirable' ) );
	}

	/**
	 * Clear compiled cache files
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 */
	public function clear( $args, $assoc_args ) {
		$path       = $assoc_args['path'] ?? getcwd();
		$cache_file = $path . '/cache/wpdi-container.php';

		if ( file_exists( $cache_file ) ) {
			if ( unlink( $cache_file ) ) {
				WP_CLI::success( "Cache cleared: {$cache_file}" );
			} else {
				WP_CLI::error( "Failed to delete cache file: {$cache_file}" );
			}
		} else {
			WP_CLI::log( "No cache file found at: {$cache_file}" );
		}

		// Clear entire cache directory if empty
		$cache_dir = dirname( $cache_file );
		if ( is_dir( $cache_dir ) && 2 === count( scandir( $cache_dir ) ) ) { // Only . and ..
			if ( rmdir( $cache_dir ) ) {
				WP_CLI::success( 'Removed empty cache directory' );
			}
		}
	}

	/**
	 * Get human-readable class type
	 */
	private function get_class_type( string $class ) : string {
		if ( ! class_exists( $class ) ) {
			return 'unknown';
		}

		$reflection = new \ReflectionClass( $class );

		if ( $reflection->isInterface() ) {
			return 'interface';
		}

		if ( $reflection->isAbstract() ) {
			return 'abstract';
		}

		return 'concrete';
	}

	/**
	 * Check if class is autowirable
	 */
	private function is_autowirable( string $class ) : bool {
		if ( ! class_exists( $class ) ) {
			return false;
		}

		try {
			$reflection = new \ReflectionClass( $class );

			return $reflection->isInstantiable() && ! $reflection->isAbstract();
		} catch ( \Exception $e ) {
			return false;
		}
	}
}
