<?php
/**
 * WP-CLI command for inspecting WPDI service resolution
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Class_Inspector;
use ReflectionNamedType;

/**
 * Inspect how a class is resolved by WPDI
 */
class Inspect_Command {
	private Class_Inspector $inspector;

	public function __construct() {
		$this->inspector = new Class_Inspector();
	}

	/**
	 * Inspect a class and display its dependency tree
	 *
	 * Accepts a fully-qualified class name or a short (unqualified) name.
	 * Short names are resolved by scanning the autodiscovery paths.
	 *
	 * @subcommand inspect
	 * @synopsis <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--depth=<depth>]
	 *
	 * ## OPTIONS
	 *
	 * <class>
	 * : Class or interface name to inspect (short or fully-qualified)
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * [--depth=<depth>]
	 * : Maximum tree depth to display (default: unlimited)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di inspect CensusRunner
	 *     wp di inspect 'My_Plugin\Services\Payment_Gateway'
	 *     wp di inspect Payment_Gateway --depth=2
	 */
	public function __invoke( $args, $assoc_args ) {
		$class_name       = $args[0];
		$path             = $assoc_args['dir'] ?? getcwd();
		$max_depth        = isset( $assoc_args['depth'] ) ? (int) $assoc_args['depth'] : 0;
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );

		// Resolve short class names via autodiscovery.
		if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			$class_name = $this->resolve_short_name( $class_name, $path, $autowiring_paths );
		}

		$type        = $this->inspector->get_type( $class_name );
		$config_info = $this->get_config_binding( $class_name, $path );

		// Path header.
		$file_path = $this->get_class_file_path( $class_name, $path );
		WP_CLI::log( WP_CLI::colorize( "Path: %W{$file_path}%n" ) );
		WP_CLI::log( '' );

		// Warnings.
		if ( 'interface' === $type && ! $config_info ) {
			WP_CLI::warning( 'Interface has no binding in wpdi-config.php - not resolvable' );
		}

		if ( 'abstract' === $type ) {
			WP_CLI::warning( 'Abstract class is not instantiable' );
		}

		// Build and display tree.
		$rows = array();
		$this->collect_tree_rows( $class_name, 0, array(), $rows, '', 0, $max_depth );
		$lines = $this->format_tree_rows( $rows );

		foreach ( $lines as $line ) {
			WP_CLI::log( $line );
		}
	}

	/**
	 * Get the file path for a class using reflection
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $base_path  Base path for relative display.
	 * @return string File path (relative if possible).
	 */
	private function get_class_file_path( string $class_name, string $base_path ): string {
		$reflection = $this->inspector->get_reflection( $class_name );

		if ( ! $reflection ) {
			return '(unknown)';
		}

		$file = $reflection->getFileName();

		if ( ! $file ) {
			return '(internal)';
		}

		return $this->make_relative( $file, $base_path );
	}

	/**
	 * Check if a class has a binding in wpdi-config.php
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $path       Module base path.
	 * @return bool True if bound in config.
	 */
	private function get_config_binding( string $class_name, string $path ): bool {
		$config_file = $path . '/wpdi-config.php';
		if ( ! file_exists( $config_file ) ) {
			return false;
		}

		$config = require $config_file;

		return isset( $config[ $class_name ] );
	}

	/**
	 * Get constructor parameter names mapped to their dependency class names
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return array Associative array of parameter name => FQCN.
	 */
	private function get_constructor_param_map( string $class_name ): array {
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
	 * Recursively collect tree rows for column-aligned display
	 *
	 * Each row contains prefix (tree chars), label ($param or class name),
	 * type (class/interface/abstract), and FQCN.
	 *
	 * @param string $class_name   Fully-qualified class name.
	 * @param int    $depth        Current tree depth.
	 * @param array  $visited      Classes already visited (circular detection).
	 * @param array  $rows         Collected rows (by reference).
	 * @param string $child_prefix Tree-drawing prefix for children.
	 * @param int    $prefix_width Display width of $child_prefix.
	 * @param int    $max_depth    Maximum depth (0 = unlimited).
	 */
	private function collect_tree_rows(
		string $class_name,
		int $depth,
		array $visited,
		array &$rows,
		string $child_prefix,
		int $prefix_width,
		int $max_depth
	): void {
		$type_label = $this->format_type_label( $this->inspector->get_type( $class_name ) );

		// Root row.
		if ( 0 === $depth ) {
			$rows[] = array(
				'prefix'       => '',
				'prefix_width' => 0,
				'label'        => $this->get_short_class_name( $class_name ),
				'type'         => $type_label,
				'fqcn'         => $this->get_namespace( $class_name ),
			);
		}

		if ( in_array( $class_name, $visited, true ) ) {
			return;
		}

		$visited[] = $class_name;

		if ( $max_depth > 0 && $depth >= $max_depth ) {
			return;
		}

		$param_map = $this->get_constructor_param_map( $class_name );
		$count     = count( $param_map );
		$index     = 0;

		foreach ( $param_map as $param_name => $dep_fqcn ) {
			$is_last    = ( $index === $count - 1 );
			$connector  =
				$is_last ? "\xE2\x94\x94\xE2\x94\x80\xE2\x94\x80 " : "\xE2\x94\x9C\xE2\x94\x80\xE2\x94\x80 ";
			$next_pad   = $is_last ? '    ' : "\xE2\x94\x82   ";
			$row_prefix = $child_prefix . $connector;
			$row_width  = $prefix_width + 4;
			$dep_type   = $this->format_type_label( $this->inspector->get_type( $dep_fqcn ) );

			if ( in_array( $dep_fqcn, $visited, true ) ) {
				$rows[] = array(
					'prefix'       => $row_prefix,
					'prefix_width' => $row_width,
					'label'        => $param_name,
					'type'         => $dep_type,
					'fqcn'         => $dep_fqcn . ' [CIRCULAR]',
				);
			} else {
				$rows[] = array(
					'prefix'       => $row_prefix,
					'prefix_width' => $row_width,
					'label'        => $param_name,
					'type'         => $dep_type,
					'fqcn'         => $dep_fqcn,
				);

				$this->collect_tree_rows(
					$dep_fqcn,
					$depth + 1,
					$visited,
					$rows,
					$child_prefix . $next_pad,
					$prefix_width + 4,
					$max_depth
				);
			}

			$index ++;
		}
	}

	/**
	 * Format collected tree rows into column-aligned output lines
	 *
	 * @param array $rows Collected tree rows.
	 * @return array Formatted output lines.
	 */
	private function format_tree_rows( array $rows ): array {
		if ( empty( $rows ) ) {
			return array();
		}

		$max_col1 = 0;
		$max_col2 = 0;

		foreach ( $rows as $row ) {
			$col1_width = $row['prefix_width'] + strlen( $row['label'] );

			if ( $col1_width > $max_col1 ) {
				$max_col1 = $col1_width;
			}

			$col2_width = strlen( $row['type'] );

			if ( $col2_width > $max_col2 ) {
				$max_col2 = $col2_width;
			}
		}

		$lines = array();

		foreach ( $rows as $row ) {
			$col1_width = $row['prefix_width'] + strlen( $row['label'] );
			$pad1       = $max_col1 - $col1_width + 4;
			$pad2       = $max_col2 - strlen( $row['type'] ) + 4;

			$label_color = $this->get_label_color( $row );
			$type_color  = $this->get_type_color( $row['type'] );
			$fqcn_str    = $this->colorize_fqcn( $row['fqcn'] );

			$line = $row['prefix']
				. WP_CLI::colorize( $label_color . $row['label'] . '%n' )
				. str_repeat( ' ', $pad1 )
				. WP_CLI::colorize( $type_color . $row['type'] . '%n' )
				. str_repeat( ' ', $pad2 )
				. $fqcn_str;

			$lines[] = $line;
		}

		return $lines;
	}

	/**
	 * Format a type string for display
	 *
	 * @param string $type Raw type from Class_Inspector.
	 * @return string Display label.
	 */
	private function format_type_label( string $type ): string {
		if ( 'concrete' === $type ) {
			return 'class';
		}

		return $type;
	}

	/**
	 * Get the color token for a tree row label
	 *
	 * @param array $row Tree row data.
	 * @return string WP_CLI color token.
	 */
	private function get_label_color( array $row ): string {
		// Root row (class name) gets bright white.
		if ( '' === $row['prefix'] ) {
			return '%W';
		}

		// Parameter names get yellow.
		return '%y';
	}

	/**
	 * Get the color token for a type label
	 *
	 * @param string $type Type label (class, interface, abstract, unknown).
	 * @return string WP_CLI color token.
	 */
	private function get_type_color( string $type ): string {
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
	 * Colorize an FQCN string, highlighting [CIRCULAR] markers in red
	 *
	 * @param string $fqcn FQCN string, possibly with [CIRCULAR] suffix.
	 * @return string Colorized string.
	 */
	private function colorize_fqcn( string $fqcn ): string {
		if ( false !== strpos( $fqcn, '[CIRCULAR]' ) ) {
			$fqcn = str_replace( '[CIRCULAR]', WP_CLI::colorize( '%r[CIRCULAR]%n' ), $fqcn );
		}

		return $fqcn;
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
	 * Get the namespace portion of a FQCN
	 *
	 * @param string $fqcn Fully-qualified class name.
	 * @return string Namespace (empty string for global namespace).
	 */
	private function get_namespace( string $fqcn ): string {
		$pos = strrpos( $fqcn, '\\' );

		if ( false === $pos ) {
			return $fqcn;
		}

		return substr( $fqcn, 0, $pos );
	}

	/**
	 * Resolve a short (unqualified) class name to its FQCN via autodiscovery
	 *
	 * Scans autodiscovery paths for classes whose short name matches the input.
	 * Errors if no match or multiple ambiguous matches are found.
	 *
	 * @param string $short_name       Short class name.
	 * @param string $path             Module base path.
	 * @param array  $autowiring_paths Autowiring paths.
	 * @return string Resolved FQCN.
	 */
	private function resolve_short_name( string $short_name, string $path, array $autowiring_paths ): string {
		$discovery = new Auto_Discovery();
		$matches   = array();

		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				continue;
			}

			foreach ( array_keys( $discovery->discover( $full_path ) ) as $fqcn ) {
				if ( $this->get_short_class_name( $fqcn ) === $short_name ) {
					$matches[] = $fqcn;
				}
			}
		}

		if ( empty( $matches ) ) {
			WP_CLI::error( "Class or interface not found: {$short_name}" );
		}

		if ( count( $matches ) > 1 ) {
			WP_CLI::log( "Ambiguous class name '{$short_name}'. Did you mean:" );

			foreach ( $matches as $match ) {
				WP_CLI::log( "  - {$match}" );
			}

			WP_CLI::error( 'Please use a fully-qualified class name.' );
		}

		return $matches[0];
	}

	/**
	 * Parse autowiring paths from command arguments
	 *
	 * @param array $assoc_args Associative arguments from WP-CLI.
	 * @return array Autowiring paths.
	 */
	private function parse_autowiring_paths( array $assoc_args ): array {
		if ( ! isset( $assoc_args['autowiring-paths'] ) ) {
			return array( 'src' );
		}

		$paths = explode( ',', $assoc_args['autowiring-paths'] );

		return array_map( 'trim', $paths );
	}

	/**
	 * Make a file path relative to a base path
	 *
	 * @param string $file_path Full file path.
	 * @param string $base_path Base path to make relative to.
	 * @return string Relative path.
	 */
	private function make_relative( string $file_path, string $base_path ): string {
		$base_path = rtrim( $base_path, '/' ) . '/';

		if ( 0 === strpos( $file_path, $base_path ) ) {
			return substr( $file_path, strlen( $base_path ) );
		}

		return $file_path;
	}
}
