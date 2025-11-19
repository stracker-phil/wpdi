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

		$discovery = new Auto_Discovery();
		$classes   = $discovery->discover( $path . '/src' );

		if ( empty( $classes ) ) {
			WP_CLI::log( "No classes found in {$path}/src" );

			return;
		}

		$output = array();
		foreach ( $classes as $class => $file_path ) {
			$output[] = array(
				'class'       => $class,
				'type'        => $this->get_class_type( $class ),
				'autowirable' => $this->is_autowirable( $class ) ? 'yes' : 'no',
			);
		}

		WP_CLI\Utils\format_items( $format, $output, array( 'class', 'type', 'autowirable' ) );
	}

	/**
	 * Get human-readable class type
	 *
	 * @param string $class Fully-qualified class name.
	 * @return string Class type (interface, abstract, concrete, unknown).
	 */
	private function get_class_type( string $class ): string {
		if ( ! class_exists( $class ) ) {
			return 'unknown';
		}

		$reflection = new ReflectionClass( $class );

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
