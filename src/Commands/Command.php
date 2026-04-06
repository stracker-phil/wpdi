<?php
/**
 * Base class for all WPDI CLI commands that provides common
 * output functions.
 *
 * @package WPDI\Commands
 */

declare( strict_types = 1 );

namespace WPDI\Commands;

use ReflectionNamedType;
use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Cache_Manager;
use WPDI\Cache_Store;
use WPDI\Class_Inspector;

/**
 * Abstract base for all WPDI CLI commands.
 */
abstract class Command {

	/**
	 * Class metadata inspector.
	 *
	 * @var Class_Inspector
	 */
	protected Class_Inspector $inspector;

	/**
	 * Whether to use ASCII box-drawing characters.
	 *
	 * @var bool
	 */
	protected bool $ascii = false;

	/**
	 * Output format string.
	 *
	 * @var string
	 */
	protected string $format = 'table';

	/**
	 * Loaded compiled cache data, or null when no cache is available.
	 *
	 * @var array{classes: array, bindings: array}|null
	 */
	protected ?array $cache_data = null;

	/** Initialize command dependencies. */
	public function __construct() {
		$this->inspector = new Class_Inspector();
	}

	/**
	 * Load the container cache through the same pipeline as Scope::__construct().
	 *
	 * Instantiates Cache_Manager and calls get_cache() — the identical path
	 * used by the runtime. This means:
	 *   - If no cache exists, a full rebuild (discovery + reflection) runs once.
	 *   - If the cache is stale, an incremental mtime-based update runs.
	 *   - If the cache is fresh, only N file_exists()/filemtime() checks run.
	 *
	 * The result is stored in $this->cache_data for use by get_constructor_param_map(),
	 * get_full_param_map(), resolve_short_name(), and the commands themselves.
	 *
	 * @param string $path             Module root directory.
	 * @param array  $autowiring_paths Relative autowiring paths (e.g. ['src']).
	 */
	protected function load_module_cache( string $path, array $autowiring_paths ): void {
		$config_file     = $path . '/wpdi-config.php';
		$config_bindings = file_exists( $config_file ) ? require $config_file : array();

		$store     = new Cache_Store( $path );
		$discovery = new Auto_Discovery( $this->inspector );

		$abs_paths = array();
		foreach ( $autowiring_paths as $rel_path ) {
			$abs_paths[] = $path . '/' . $rel_path;
		}

		// CLI commands always check for staleness — wp_get_environment_type() defaults to
		// 'production' when WP_ENVIRONMENT_TYPE is not defined, which would cause Cache_Manager
		// to skip all staleness checks and return stale data after constructor changes.
		$environment = 'development';
		$cache_manager = new Cache_Manager(
			$store,
			$discovery,
			$this->inspector,
			$abs_paths,
			$path,
			$environment
		);

		$this->cache_data = $cache_manager->get_cache( '', $config_bindings );
	}

	// --- Output wrappers ---

	/**
	 * Log a message.
	 *
	 * @param string $message Log message.
	 */
	protected function log( string $message ): void {
		WP_CLI::log( $message );
	}

	/**
	 * Log a success message.
	 *
	 * @param string $message Success message.
	 */
	protected function success( string $message ): void {
		WP_CLI::success( $message );
	}

	/**
	 * Log a warning message.
	 *
	 * @param string $message Warning message.
	 */
	protected function warning( string $message ): void {
		WP_CLI::warning( $message );
	}

	/**
	 * Output an error message and exit.
	 *
	 * @param string $message Error message.
	 */
	protected function error( string $message ): void {
		WP_CLI::error( $message );
	}

	/**
	 * Log a results-count summary line.
	 *
	 * @param array  $results    Result set.
	 * @param string $one_result Template for exactly one result.
	 * @param string $n_results  Template for multiple results (with %d).
	 * @param string $no_results Template for zero results.
	 */
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

	/**
	 * Log filter metadata above a results table.
	 *
	 * @param array $details Associative array of meta keys to values.
	 */
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

	// --- Class name formatting ---

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
			case 'builtin':
				return '%r';
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

	// --- File path helpers ---

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

	// --- Reflection helpers ---

	/**
	 * Get constructor parameter names mapped to their dependency class names.
	 *
	 * Only includes parameters with non-builtin type hints (class/interface types).
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return array Associative array of '$param_name' => FQCN.
	 */
	protected function get_constructor_param_map( string $class_name ): array {
		// Try cached constructor descriptors first.
		if ( null !== $this->cache_data ) {
			$metadata = $this->cache_data['classes'][ $class_name ] ?? null;

			if ( null !== $metadata && array_key_exists( 'constructor', $metadata ) ) {
				$constructor = $metadata['constructor'];

				if ( null === $constructor ) {
					return array();
				}

				$map = array();

				foreach ( $constructor as $param ) {
					if ( null !== $param['type'] && ! $param['builtin'] ) {
						$map[ '$' . $param['name'] ] = $param['type'];
					}
				}

				return $map;
			}
		}

		// Fall back to reflection.
		$reflection = $this->inspector->get_reflection( $class_name );

		if ( ! $reflection ) {
			return array();
		}

		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return array();
		}

		$map = array();

		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();

			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				$map[ '$' . $param->getName() ] = $type->getName();
			}
		}

		return $map;
	}

	/**
	 * Get all constructor parameters, including built-in types.
	 *
	 * Unlike get_constructor_param_map() which only returns class/interface
	 * dependencies, this method includes built-in types (string, bool, array,
	 * callable, etc.) so they can be displayed in diagnostic output.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return array Map of '$param_name' => type name string. Built-in types
	 *               are returned as-is (e.g. 'bool', 'array'). Untyped params
	 *               use 'mixed'.
	 */
	protected function get_full_param_map( string $class_name ): array {
		// Try cached constructor descriptors first.
		if ( null !== $this->cache_data ) {
			$metadata = $this->cache_data['classes'][ $class_name ] ?? null;

			if ( null !== $metadata && array_key_exists( 'constructor', $metadata ) ) {
				$constructor = $metadata['constructor'];

				if ( null === $constructor ) {
					return array();
				}

				$map = array();

				foreach ( $constructor as $param ) {
					if ( null !== $param['type'] ) {
						$map[ '$' . $param['name'] ] = array(
							'type'    => $param['type'],
							'builtin' => $param['builtin'],
						);
					} else {
						$map[ '$' . $param['name'] ] = array(
							'type'    => 'mixed',
							'builtin' => true,
						);
					}
				}

				return $map;
			}
		}

		// Fall back to reflection.
		$reflection = $this->inspector->get_reflection( $class_name );

		if ( ! $reflection ) {
			return array();
		}

		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return array();
		}

		$map = array();

		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();

			if ( $type instanceof ReflectionNamedType ) {
				$map[ '$' . $param->getName() ] = array(
					'type'    => $type->getName(),
					'builtin' => $type->isBuiltin(),
				);
			} else {
				$map[ '$' . $param->getName() ] = array(
					'type'    => 'mixed',
					'builtin' => true,
				);
			}
		}

		return $map;
	}

	/**
	 * Resolve a short (unqualified) class or interface name to its FQCN.
	 *
	 * Searches cached classes, their transitive dependencies, and binding keys.
	 * Errors if no match or multiple ambiguous matches are found.
	 *
	 * Requires load_module_cache() to have been called first.
	 *
	 * @param string $short_name Short class or interface name.
	 * @return string Resolved FQCN.
	 */
	protected function resolve_short_name( string $short_name ): string {
		$all_fqcns = array();

		foreach ( $this->cache_data['classes'] as $fqcn => $metadata ) {
			$all_fqcns[] = $fqcn;

			foreach ( $metadata['dependencies'] as $dep ) {
				$all_fqcns[] = $dep;
			}
		}

		foreach ( array_keys( $this->cache_data['bindings'] ?? array() ) as $binding_key ) {
			$all_fqcns[] = $binding_key;
		}

		$matches = array();

		foreach ( array_unique( $all_fqcns ) as $fqcn ) {
			if ( $this->get_short_class_name( $fqcn ) === $short_name ) {
				$matches[] = $fqcn;
			}
		}

		if ( empty( $matches ) ) {
			$this->error( "Class or interface not found: {$short_name}" );
		}

		if ( count( $matches ) > 1 ) {
			$this->log( "Ambiguous name '{$short_name}'. Did you mean:" );

			foreach ( $matches as $match ) {
				$this->log( "  - {$match}" );
			}

			$this->error( 'Please use a fully-qualified class name.' );
		}

		return $matches[0];
	}

	/**
	 * Load wpdi-config.php from a module directory.
	 *
	 * @param string $path Module base path.
	 * @return array Config bindings, or empty array if file is absent or invalid.
	 */
	protected function load_config( string $path ): array {
		$config_file = $path . '/wpdi-config.php';

		if ( ! file_exists( $config_file ) ) {
			return array();
		}

		$config = require $config_file;

		return is_array( $config ) ? $config : array();
	}

	// --- CLI argument parsing ---

	/**
	 * Parse --format flag and set $this->ascii when format is 'ascii'.
	 *
	 * @param array $assoc_args Associative arguments from WP-CLI.
	 */
	protected function parse_format_flag( array $assoc_args ): void {
		$this->format = $assoc_args['format'] ?? 'table';
		$this->ascii  = ( 'ascii' === $this->format );
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
	 * Whether the current format is a machine-readable data format.
	 *
	 * @return bool True for json, csv, or yaml.
	 */
	protected function is_data_format(): bool {
		return in_array( $this->format, array( 'json', 'csv', 'yaml' ), true );
	}

	/**
	 * Output items in the current data format via WP-CLI's format_items.
	 *
	 * @param array $items  Rows as associative arrays.
	 * @param array $fields Ordered list of field names to display.
	 */
	protected function format_items( array $items, array $fields ): void {
		\WP_CLI\Utils\format_items( $this->format, $items, $fields );
	}

	// --- Table rendering ---

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
			case 'class_binding':
				// Like 'class_name' but colors the binding target using $item['binding_type'].
				return $this->format_class_name( $value, (string) ( $item['binding_type'] ?? '' ) );
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
			case 'binding_label':
				// 'no config' in red, $param tokens in yellow, 'default' plain.
				if ( 'no config' === $value ) {
					return WP_CLI::colorize( '%r' . $value . '%n' );
				}

				return preg_replace_callback(
					'/\$\w+/',
					function ( array $m ): string {
						return WP_CLI::colorize( '%y' . $m[0] . '%n' );
					},
					$value
				);
			case 'mapped_class':
				// Color the mapped concrete class name green.
				if ( '' === $value ) {
					return $value;
				}

				return WP_CLI::colorize( '%g' . $value . '%n' );
		}

		return $value;
	}

	// --- Tree rendering ---

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
