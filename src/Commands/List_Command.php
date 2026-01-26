<?php
/**
 * WP-CLI command for listing WPDI services
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Class_Inspector;

/**
 * List services without compiling
 */
class List_Command {
	private Class_Inspector $inspector;

	public function __construct() {
		$this->inspector = new Class_Inspector();
	}
	/**
	 * List all injectable services
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * [--format=<format>]
	 * : Output format (table, json, yaml, csv) (default: table)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di list
	 *     wp di list --path=/path/to/module --format=json
	 *     wp di list --autowiring-paths=src,modules/auth/src
	 */
	public function __invoke( $args, $assoc_args ) {
		$path             = $assoc_args['path'] ?? getcwd();
		$format           = $assoc_args['format'] ?? 'table';
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );

		if ( ! is_dir( $path ) ) {
			WP_CLI::error( "Directory does not exist: {$path}" );
		}

		$output = array();

		// Discover classes from autowiring paths
		$discovery = new Auto_Discovery();
		$classes   = array();

		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				continue; // Skip non-existent paths silently in list command
			}

			$discovered = $discovery->discover( $full_path );
			$classes    = array_merge( $classes, $discovered );
		}

		foreach ( $classes as $class => $metadata ) {
			$output[] = array(
				'class'       => $class,
				'type'        => $this->inspector->get_type( $class ),
				'autowirable' => $this->inspector->is_concrete( $class ) ? 'yes' : 'no',
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
					'type'        => $this->inspector->get_type( $class ),
					'autowirable' => $this->inspector->is_concrete( $class ) ? 'yes' : 'no',
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
	 * Parse autowiring paths from command arguments
	 *
	 * @param array $assoc_args Associative arguments from WP-CLI.
	 * @return array Autowiring paths.
	 */
	private function parse_autowiring_paths( array $assoc_args ): array {
		if ( ! isset( $assoc_args['autowiring-paths'] ) ) {
			return array( 'src' ); // Default
		}

		$paths = explode( ',', $assoc_args['autowiring-paths'] );

		return array_map( 'trim', $paths );
	}
}
