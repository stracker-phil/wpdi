<?php
/**
 * Base class for all WPDI CLI commands that provides common
 * output functions.
 */

declare( strict_types = 1 );

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Class_Inspector;

abstract class Command {

	protected Class_Inspector $inspector;
	protected bool $ascii = false;

	public function __construct() {
		$this->inspector = new Class_Inspector();
	}

	/**
	 * Parse --format flag and set $this->ascii when format is 'ascii'.
	 *
	 * @param array $assoc_args Associative arguments from WP-CLI.
	 */
	protected function parse_format_flag( array $assoc_args ): void {
		$this->ascii = ( 'ascii' === ( $assoc_args['format'] ?? '' ) );
	}

	/**
	 * Return the tree connector string for the current format mode.
	 *
	 * @param bool $is_last Whether this is the last sibling.
	 * @return string Four-char tree connector.
	 */
	protected function tree_connector( bool $is_last ): string {
		return $this->ascii ? '+-- ' : ( $is_last ? '└── ' : '├── ' );
	}

	/**
	 * Return the tree indent string for the current format mode.
	 *
	 * @param bool $is_last Whether the parent was the last sibling.
	 * @return string Four-char continuation indent.
	 */
	protected function tree_indent( bool $is_last ): string {
		return $this->ascii
			? ( $is_last ? '    ' : '|   ' )
			: ( $is_last ? '    ' : '│   ' );
	}

	protected function log( string $message ): void {
		WP_CLI::log( $message );
	}

	protected function success( string $message ): void {
		WP_CLI::success( $message );
	}

	protected function warning( string $message ): void {
		WP_CLI::warning( $message );
	}

	/**
	 * Output an error message and exit.
	 */
	protected function error( string $message ): void {
		WP_CLI::error( $message );
	}

	protected function results_found( array $results, string $one_result, string $n_results, string $no_results ): void {
		$number = count( $results );

		switch ( $number ) {
			case 0:
				$message = $no_results;
				break;
			case 1:
				$message = $one_result;
				break;
			default:
				$message = $n_results;
				break;
		}

		if ( $message ) {
			$message = sprintf( $message, $number );
			$this->log( WP_CLI::colorize( "%y-- $message --%n" ) );
		}
	}

	protected function result_meta( array $details ): void {
		$rows = 0;

		foreach ( $details as $key => $value ) {
			if ( ! $value ) {
				continue;
			}

			switch ( $key ) {
				case 'filter':
					$label = 'Filter for';
					break;
				default:
					continue 2;
			}

			$rows ++;
			$this->log( WP_CLI::colorize( "$label: %8 $value %n" ) );
		}

		if ( $rows > 0 ) {
			$this->log( '' );
		}
	}

	/**
	 * Get the short (unqualified) class name from a FQCN.
	 *
	 * @param string $fqcn Fully-qualified class name.
	 * @return string Short class name.
	 */
	protected function get_short_class_name( string $fqcn ): string {
		$pos = strrpos( $fqcn, '\\' );

		if ( false === $pos ) {
			return $fqcn;
		}

		return substr( $fqcn, $pos + 1 );
	}

	/**
	 * Get the namespace portion of a FQCN.
	 *
	 * Returns an empty string for unqualified (global) class names.
	 *
	 * @param string $fqcn Fully-qualified class name.
	 * @return string Namespace, or empty string when there is none.
	 */
	protected function get_namespace( string $fqcn ): string {
		$pos = strrpos( $fqcn, '\\' );

		if ( false === $pos ) {
			return '';
		}

		return substr( $fqcn, 0, $pos );
	}

	/**
	 * Normalize a raw type from Class_Inspector to a display label.
	 *
	 * Maps 'concrete' to 'class'; all other values pass through unchanged.
	 *
	 * @param string $type Raw type string.
	 * @return string Display label.
	 */
	protected function format_type_label( string $type ): string {
		return 'concrete' === $type ? 'class' : $type;
	}

	/**
	 * WP_CLI color token for a normalized type label.
	 *
	 * @param string $type Normalized type: 'class', 'interface', 'abstract', or other.
	 * @return string WP_CLI color token.
	 */
	protected function get_type_color( string $type ): string {
		switch ( $type ) {
			case 'class':
				return '%g';
			case 'interface':
				return '%c';
			case 'abstract':
				return '%p';
			default:
				return '%y';
		}
	}

	/**
	 * Format a class or interface name with consistent color conventions.
	 *
	 * The leaf (short name) is colored green for classes, cyan for interfaces,
	 * purple for abstract classes. When a FQCN is given (contains \), the
	 * namespace is rendered plain and only the leaf is colorized.
	 *
	 * Accepts both raw Class_Inspector types ('concrete') and normalized
	 * display labels ('class').
	 *
	 * @param string $fqcn  FQCN or bare class name.
	 * @param string $type  Type string ('concrete'/'class', 'interface', 'abstract').
	 * @param bool   $short Return only the colored leaf without the namespace prefix.
	 * @return string Colorized string.
	 */
	protected function format_class_name( string $fqcn, string $type, bool $short = false ): string {
		$leaf    = $this->get_short_class_name( $fqcn );
		$color   = $this->get_type_color( $this->format_type_label( $type ) );
		$colored = WP_CLI::colorize( $color . $leaf . '%n' );

		if ( $short ) {
			return $colored;
		}

		$ns = $this->get_namespace( $fqcn );

		return ( '' !== $ns ? $ns . '\\' : '' ) . $colored;
	}

	/**
	 * Parse autowiring paths from command arguments.
	 *
	 * @param array $assoc_args Associative arguments from WP-CLI.
	 * @return array Autowiring paths.
	 */
	protected function parse_autowiring_paths( array $assoc_args ): array {
		if ( ! isset( $assoc_args['autowiring-paths'] ) ) {
			return array( 'src' );
		}

		$paths = explode( ',', $assoc_args['autowiring-paths'] );

		return array_map( 'trim', $paths );
	}

	/**
	 * Render a box-drawing table with optional per-field formatting.
	 *
	 * Column widths are computed from raw (uncolored) values so that
	 * ANSI escape codes do not distort alignment.
	 *
	 * The $types map associates field names with format identifiers:
	 *   - 'class_name'  — colorizes the value via format_class_name(), reading
	 *                     the type from $item['type'].
	 *   - 'class_fqcn'  — like 'class_name' but also detects and colors [CIRCULAR].
	 *   - 'type_label'  — colors a normalized type label with its type color.
	 *   - 'via'         — colors the class name in a "via ClassName" string cyan.
	 *   - 'bool'        — colors false-like values ('no', 'false', '0') red.
	 *
	 * When $title is provided, a full-width title row is prepended above the
	 * column headers.
	 *
	 * When $separators is provided, a mid-border line is emitted before each
	 * row whose index appears in the array.
	 *
	 * @param array  $items      Rows as associative arrays.
	 * @param array  $fields     Ordered list of field names to display as columns.
	 * @param array  $types      Map of field name => format identifier.
	 * @param string $title      Optional full-width title row.
	 * @param array  $separators Row indices before which to emit a mid-border line.
	 */
	protected function table( array $items, array $fields, array $types = array(), string $title = '', array $separators = array() ): void {
		// Select border characters based on format mode.
		$h  = $this->ascii ? '-' : '─';
		$v  = $this->ascii ? '|' : '│';
		$tl = $this->ascii ? '+' : '┌';
		$tr = $this->ascii ? '+' : '┐';
		$bl = $this->ascii ? '+' : '└';
		$br = $this->ascii ? '+' : '┘';
		$ml = $this->ascii ? '+' : '├';
		$mr = $this->ascii ? '+' : '┤';
		$mt = $this->ascii ? '+' : '┬';
		$mb = $this->ascii ? '+' : '┴';
		$mm = $this->ascii ? '+' : '┼';

		// Calculate column widths from raw (uncolored) values.
		$widths = array();
		foreach ( $fields as $field ) {
			$widths[ $field ] = strlen( $field );
		}
		foreach ( $items as $item ) {
			foreach ( $fields as $field ) {
				$len = mb_strlen( (string) ( $item[ $field ] ?? '' ), 'UTF-8' );
				if ( $len > $widths[ $field ] ) {
					$widths[ $field ] = $len;
				}
			}
		}

		// Build border lines.
		$h_bars = array();
		foreach ( $fields as $field ) {
			$h_bars[ $field ] = str_repeat( $h, $widths[ $field ] + 2 );
		}

		// Total inner width: column content + spaces + inner separators.
		$total_inner = count( $fields ) - 1;
		foreach ( $fields as $field ) {
			$total_inner += $widths[ $field ] + 2;
		}

		// Emit optional full-width title row before the column headers.
		if ( '' !== $title ) {
			$this->log( $tl . str_repeat( $h, $total_inner ) . $tr );
			$title_pad = max( 0, $total_inner - mb_strlen( $title, 'UTF-8' ) - 1 );
			$this->log( $v . ' ' . $title . str_repeat( ' ', $title_pad ) . $v );
			$top_border = $ml . implode( $mt, $h_bars ) . $mr;
		} else {
			$top_border = $tl . implode( $mt, $h_bars ) . $tr;
		}

		$mid_border = $ml . implode( $mm, $h_bars ) . $mr;
		$bot_border = $bl . implode( $mb, $h_bars ) . $br;

		$header_parts = array();
		foreach ( $fields as $field ) {
			$header_parts[] = ' ' . str_pad( $field, $widths[ $field ] ) . ' ';
		}
		$header = $v . implode( $v, $header_parts ) . $v;

		$this->log( $top_border );
		$this->log( $header );
		$this->log( $mid_border );

		foreach ( $items as $index => $item ) {
			if ( in_array( $index, $separators, true ) ) {
				$this->log( $mid_border );
			}
			$cells = array();
			foreach ( $fields as $field ) {
				$raw     = (string) ( $item[ $field ] ?? '' );
				$colored = isset( $types[ $field ] )
					? $this->apply_cell_format( $types[ $field ], $raw, $item )
					: $raw;
				$cells[] =
					' ' . $colored . str_repeat( ' ', $widths[ $field ] - mb_strlen( $raw, 'UTF-8' ) ) . ' ';
			}
			$this->log( $v . implode( $v, $cells ) . $v );
		}

		$this->log( $bot_border );
	}

	/**
	 * Apply a named cell format to a value.
	 *
	 * @param string $format Format identifier ('class_name', 'bool').
	 * @param string $value  Cell value.
	 * @param array  $item   Full row, available for context-aware formats.
	 * @return string Formatted cell content.
	 */
	private function apply_cell_format( string $format, string $value, array $item ): string {
		switch ( $format ) {
			case 'param':
				// Color every $word token yellow; leave tree-drawing prefix chars plain.
				return preg_replace_callback(
					'/\$\w+/',
					function ( array $m ): string {
						return WP_CLI::colorize( '%y' . $m[0] . '%n' );
					},
					$value
				);
			case 'class_fqcn':
				// Like 'class_name' but strips and re-colorizes a [CIRCULAR] suffix.
				$fqcn   = $value;
				$suffix = '';
				if ( false !== strpos( $fqcn, ' [CIRCULAR]' ) ) {
					$fqcn   = str_replace( ' [CIRCULAR]', '', $fqcn );
					$suffix = ' ' . WP_CLI::colorize( '%r[CIRCULAR]%n' );
				}

				return $this->format_class_name( $fqcn, (string) ( $item['type'] ?? '' ) ) . $suffix;
			case 'class_name':
				// $item['type'] must be the pre-normalized label ('class', 'interface', …).
				return $this->format_class_name( $value, (string) ( $item['type'] ?? '' ) );
			case 'type_label':
				// Value must already be a normalized type label.
				return WP_CLI::colorize( $this->get_type_color( $value ) . $value . '%n' );
			case 'via':
				// Value is pre-formatted as 'via ShortName' or 'as ShortName', or empty.
				if ( '' === $value ) {
					return $value;
				}
				$space = (int) strpos( $value, ' ' );
				$color = 0 === strpos( $value, 'as ' ) ? '%g' : '%c';

				return substr( $value, 0, $space + 1 ) . WP_CLI::colorize( $color . substr( $value, $space + 1 ) . '%n' );
			case 'bool':
				if ( 'no' === $value || 'false' === $value || '0' === $value ) {
					return WP_CLI::colorize( '%r' . $value . '%n' );
				}

				return $value;
		}

		return $value;
	}

	/**
	 * Render a consistent header for commands that target a specific class.
	 *
	 * Output:
	 *   Path: {file path relative to base_path}
	 *   {colored type} {namespace\}{colored leaf}
	 *   {blank line}
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $type       Raw type from Class_Inspector.
	 * @param string $base_path  Module base path (used for relative path display).
	 */
	protected function log_class_header( string $class_name, string $type, string $base_path ): void {
		$type_label = $this->format_type_label( $type );
		$type_color = $this->get_type_color( $type_label );
		$file_path  = $this->get_class_file_path( $class_name, $base_path );

		$this->log( '' );
		$this->log( 'Path: ' . $file_path );
		$this->log(
			WP_CLI::colorize( $type_color . $type_label . '%n' )
			. ' '
			. $this->format_class_name( $class_name, $type )
		);
		$this->log( '' );
	}

	/**
	 * Get the file path for a class, relative to a base path when possible.
	 *
	 * Returns '(unknown)' when reflection is unavailable, or '(internal)' for
	 * built-in PHP classes.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $base_path  Base path for relative display.
	 * @return string File path (relative if possible).
	 */
	protected function get_class_file_path( string $class_name, string $base_path ): string {
		$reflection = $this->inspector->get_reflection( $class_name );

		if ( ! $reflection ) {
			return '(unknown)';
		}

		$file = $reflection->getFileName();

		if ( ! $file ) {
			return '(internal)';
		}

		$relative = $this->make_relative( $file, $base_path );

		if ( defined( 'ABSPATH' ) && 0 === strpos( $relative, ABSPATH ) ) {
			$relative = substr( $relative, strlen( ABSPATH ) );
		}

		return $relative;
	}

	/**
	 * Make a file path relative to a base path.
	 *
	 * @param string $file_path Full file path.
	 * @param string $base_path Base path to make relative to.
	 * @return string Relative path, or original path when it is outside base_path.
	 */
	protected function make_relative( string $file_path, string $base_path ): string {
		$base_path = rtrim( $base_path, '/' ) . '/';

		if ( 0 === strpos( $file_path, $base_path ) ) {
			return substr( $file_path, strlen( $base_path ) );
		}

		return $file_path;
	}

	/**
	 * Render a dependency tree as a bordered table.
	 *
	 * Expected row shape:
	 *   - prefix       (string) Tree-drawing characters prefix (may contain multi-byte chars).
	 *   - prefix_width (int)    Display character-width of prefix (used for column alignment).
	 *   - label        (string) Short class name (root) or $param name (children).
	 *   - type         (string) Normalized type label: 'class', 'interface', or 'abstract'.
	 *   - fqcn         (string) FQCN; may end with ' [CIRCULAR]'.
	 *
	 * @param array $rows Rows produced by collect_tree_rows().
	 */
	protected function render_tree( array $rows ): void {
		if ( empty( $rows ) ) {
			return;
		}

		$table_rows = array();
		$separators = array();

		foreach ( $rows as $row ) {
			$prefix = $row['prefix'];

			if ( 4 === $row['prefix_width'] ) {
				// Depth-1: strip the tree connector; emit a separator before all but the first.
				if ( count( $table_rows ) > 0 ) {
					$separators[] = count( $table_rows );
				}
				$prefix = ' ';
			} else {
				// Depth-2+: collapse the depth-1 continuation (first 4 display chars) to one space.
				$prefix = ' ' . mb_substr( $prefix, 4, null, 'UTF-8' );
			}

			$table_rows[] = array(
				'param' => $prefix . $row['label'],
				'type'  => $row['type'],
				'class' => $row['fqcn'],
			);
		}

		$this->table(
			$table_rows,
			array( 'param', 'type', 'class' ),
			array(
				'param' => 'param',
				'type'  => 'type_label',
				'class' => 'class_fqcn',
			),
			'',
			$separators
		);
	}

}
