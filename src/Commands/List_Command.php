<?php
/**
 * WP-CLI command for listing WPDI services
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use Exception;
use ReflectionClass;

/**
 * List services without compiling
 */
class List_Command {
	/**
	 * List all injectable services
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv) (default: table)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di list
	 *     wp di list --path=/path/to/module --format=json
	 */
	public function __invoke( $args, $assoc_args ) {
		$path   = $assoc_args['path'] ?? getcwd();
		$format = $assoc_args['format'] ?? 'table';

		if ( ! is_dir( $path ) ) {
			WP_CLI::error( "Directory does not exist: {$path}" );
		}

		$output = array();

		// Discover classes from src/
		$discovery = new Auto_Discovery();
		$classes   = $discovery->discover( $path . '/src' );

		foreach ( $classes as $class => $metadata ) {
			$output[] = array(
				'class'       => $class,
				'type'        => $this->get_class_type( $class ),
				'autowirable' => $this->is_autowirable( $class ) ? 'yes' : 'no',
				'source'      => 'src',
			);
		}

		// Load configured services from wpdi-config.php
		$config_file = $path . '/wpdi-config.php';
		if ( file_exists( $config_file ) ) {
			$config = require $config_file;
			foreach ( array_keys( $config ) as $class ) {
				$output[] = array(
					'class'       => $class,
					'type'        => $this->get_class_type( $class ),
					'autowirable' => $this->is_autowirable( $class ) ? 'yes' : 'no',
					'source'      => 'config',
				);
			}
		}

		if ( empty( $output ) ) {
			WP_CLI::log( "No services found in {$path}" );

			return;
		}

		WP_CLI\Utils\format_items( $format, $output, array( 'class', 'type', 'autowirable', 'source' ) );
	}

	/**
	 * Get human-readable class type
	 *
	 * @param string $class Fully-qualified class name.
	 * @return string Class type (interface, abstract, concrete, unknown).
	 */
	private function get_class_type( string $class ): string {
		if ( interface_exists( $class ) ) {
			return 'interface';
		}

		if ( ! class_exists( $class ) ) {
			return 'unknown';
		}

		$reflection = new ReflectionClass( $class );

		if ( $reflection->isAbstract() ) {
			return 'abstract';
		}

		return 'concrete';
	}

	/**
	 * Check if class is autowirable
	 *
	 * @param string $class Fully-qualified class name.
	 * @return bool True if class can be autowired.
	 */
	private function is_autowirable( string $class ): bool {
		if ( ! class_exists( $class ) ) {
			return false;
		}

		try {
			$reflection = new ReflectionClass( $class );

			return $reflection->isInstantiable() && ! $reflection->isAbstract();
		} catch ( Exception $e ) {
			return false;
		}
	}
}
