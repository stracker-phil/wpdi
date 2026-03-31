<?php
/**
 * WP-CLI command for listing WPDI services
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Class_Inspector;

use function WP_CLI\Utils\format_items;

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
				continue; // Skip non-existent paths silently in list command.
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

		if ( 'table' === $format ) {
			$this->display_colored_table( $output );
		} else {
			format_items( $format, $output, array( 'class', 'type', 'autowirable', 'source' ) );
		}
	}

	/**
	 * Display a colorized table for terminal output
	 *
	 * @param array $items Service list items.
	 */
	private function display_colored_table( array $items ): void {
		$fields = array( 'class', 'type', 'autowirable', 'source' );

		// Calculate column widths from raw (uncolored) values.
		$widths = array();
		foreach ( $fields as $field ) {
			$widths[ $field ] = strlen( $field );
		}
		foreach ( $items as $item ) {
			foreach ( $fields as $field ) {
				$len = strlen( $item[ $field ] );
				if ( $len > $widths[ $field ] ) {
					$widths[ $field ] = $len;
				}
			}
		}

		// Build separator and header.
		$sep_parts    = array();
		$header_parts = array();
		foreach ( $fields as $field ) {
			$sep_parts[]    = str_repeat( '-', $widths[ $field ] + 2 );
			$header_parts[] = ' ' . str_pad( $field, $widths[ $field ] ) . ' ';
		}
		$separator = '+' . implode( '+', $sep_parts ) . '+';
		$header    = '|' . implode( '|', $header_parts ) . '|';

		WP_CLI::log( $separator );
		WP_CLI::log( $header );
		WP_CLI::log( $separator );

		// Build rows with color.
		foreach ( $items as $item ) {
			$cells = array();
			foreach ( $fields as $field ) {
				$raw = $item[ $field ];
				$pad = $widths[ $field ];

				$colored = $this->colorize_cell( $field, $raw );
				// Pad based on raw length, then insert colored value.
				$cells[] = ' ' . $colored . str_repeat( ' ', $pad - strlen( $raw ) ) . ' ';
			}
			WP_CLI::log( '|' . implode( '|', $cells ) . '|' );
		}

		WP_CLI::log( $separator );
	}

	/**
	 * Colorize a single table cell value
	 *
	 * @param string $field Column name.
	 * @param string $value Cell value.
	 * @return string Colorized value.
	 */
	private function colorize_cell( string $field, string $value ): string {
		if ( 'class' === $field ) {
			$short = $this->get_short_class_name( $value );
			$ns    = substr( $value, 0, strlen( $value ) - strlen( $short ) );

			return $ns . WP_CLI::colorize( '%G' . $short . '%n' );
		}

		if ( 'autowirable' === $field && 'no' === $value ) {
			return WP_CLI::colorize( '%r' . $value . '%n' );
		}

		return $value;
	}

	/**
	 * Get the short (unqualified) class name from a FQCN
	 *
	 * @param string $fqcn Fully-qualified class name.
	 * @return string Short class name.
	 */
	private function get_short_class_name( string $fqcn ): string {
		$pos = strrpos( $fqcn, '\\' );

		if ( false === $pos ) {
			return $fqcn;
		}

		return substr( $fqcn, $pos + 1 );
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
