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
	 * : Output format (table, json, yaml, csv) (default: table)
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

		// Apply --filter substring match against class name.
		if ( isset( $assoc_args['filter'] ) ) {
			$filter = $assoc_args['filter'];
			$output = array_filter(
				$output,
				function ( array $item ) use ( $filter ): bool {
					return false !== strpos( $item['class'], $filter );
				}
			);
			$output = array_values( $output );
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

		// Build border lines using box-drawing characters.
		$h_bars = array();
		foreach ( $fields as $field ) {
			$h_bars[ $field ] = str_repeat( "\xE2\x94\x80", $widths[ $field ] + 2 );
		}

		$top_border = "\xE2\x94\x8C" . implode( "\xE2\x94\xAC", $h_bars ) . "\xE2\x94\x90";
		$mid_border = "\xE2\x94\x9C" . implode( "\xE2\x94\xBC", $h_bars ) . "\xE2\x94\xA4";
		$bot_border = "\xE2\x94\x94" . implode( "\xE2\x94\xB4", $h_bars ) . "\xE2\x94\x98";

		$header_parts = array();
		foreach ( $fields as $field ) {
			$header_parts[] = ' ' . str_pad( $field, $widths[ $field ] ) . ' ';
		}
		$header = "\xE2\x94\x82" . implode( "\xE2\x94\x82", $header_parts ) . "\xE2\x94\x82";

		WP_CLI::log( $top_border );
		WP_CLI::log( $header );
		WP_CLI::log( $mid_border );

		// Build rows with color.
		foreach ( $items as $item ) {
			$cells = array();
			foreach ( $fields as $field ) {
				$raw = $item[ $field ];
				$pad = $widths[ $field ];

				$colored = $this->colorize_cell( $field, $raw, $item );
				// Pad based on raw length, then insert colored value.
				$cells[] = ' ' . $colored . str_repeat( ' ', $pad - strlen( $raw ) ) . ' ';
			}
			WP_CLI::log( "\xE2\x94\x82" . implode( "\xE2\x94\x82", $cells ) . "\xE2\x94\x82" );
		}

		WP_CLI::log( $bot_border );
	}

	/**
	 * Colorize a single table cell value
	 *
	 * @param string $field Column name.
	 * @param string $value Cell value.
	 * @return string Colorized value.
	 */
	private function colorize_cell( string $field, string $value, array $item ): string {
		if ( 'class' === $field ) {
			$short = $this->get_short_class_name( $value );
			$ns    = substr( $value, 0, strlen( $value ) - strlen( $short ) );
			$color = 'interface' === $item['type'] ? '%c' : '%G';

			return $ns . WP_CLI::colorize( $color . $short . '%n' );
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
