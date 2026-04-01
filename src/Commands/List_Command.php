<?php
/**
 * WP-CLI command for listing WPDI services
 */

namespace WPDI\Commands;

use WPDI\Auto_Discovery;

use function WP_CLI\Utils\format_items;

/**
 * List services without compiling
 */
class List_Command extends Command {

	/**
	 * List all injectable services
	 *
	 * @subcommand list
	 * @synopsis [--dir=<dir>] [--autowiring-paths=<paths>] [--filter=<filter>] [--format=<format>]
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * [--filter=<filter>]
	 * : Only show services whose fully-qualified class name contains this substring
	 *
	 * [--format=<format>]
	 * : Output format: table (default), ascii, json, yaml, csv
	 *
	 * ## EXAMPLES
	 *
	 *     wp di list
	 *     wp di list --dir=/path/to/module --format=json
	 *     wp di list --filter=Services
	 *     wp di list --autowiring-paths=src,modules/auth/src
	 */
	public function __invoke( $args, $assoc_args ) {
		$path             = $assoc_args['dir'] ?? getcwd();
		$format           = $assoc_args['format'] ?? 'table';
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );
		$this->parse_format_flag( $assoc_args );

		if ( ! is_dir( $path ) ) {
			$this->error( "Directory does not exist: {$path}" );
		}

		$output = array();

		// Discover classes from autowiring paths
		$discovery = new Auto_Discovery();
		$classes   = array();

		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				continue; // Skip non-existent paths silently in list command.
			}

			$discovered = $discovery->discover( $full_path );
			$classes    = array_merge( $classes, $discovered );
		}

		foreach ( $classes as $class => $metadata ) {
			$output[] = array(
				'class'       => $class,
				'type'        => $this->format_type_label( $this->inspector->get_type( $class ) ),
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
					'type'        => $this->format_type_label( $this->inspector->get_type( $class ) ),
					'autowirable' => $this->inspector->is_concrete( $class ) ? 'yes' : 'no',
					'source'      => 'config',
				);
			}
		}

		// Apply --filter substring match against class name.
		if ( isset( $assoc_args['filter'] ) ) {
			$filter = $assoc_args['filter'];
			$output = array_filter(
				$output,
				static fn( array $item ): bool => false !== strpos( $item['class'], $filter )
			);
			$output = array_values( $output );
		}

		if ( empty( $output ) ) {
			$this->log( "No services found in {$path}" );

			return;
		}

		if ( in_array( $format, array( 'json', 'yaml', 'csv' ), true ) ) {
			format_items( $format, $output, array( 'class', 'type', 'autowirable', 'source' ) );
		} else {
			$this->table(
				$output,
				array( 'class', 'type', 'autowirable', 'source' ),
				array(
					'class'       => 'class_name',
					'type'        => 'type_label',
					'autowirable' => 'bool',
				)
			);
		}
	}
}
