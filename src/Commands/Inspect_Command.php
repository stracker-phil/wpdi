<?php
/**
 * WP-CLI command for inspecting WPDI service resolution
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use ReflectionNamedType;

/**
 * Inspect how a class is resolved by WPDI
 */
class Inspect_Command extends Command {

	/**
	 * Inspect a class and display its dependency tree
	 *
	 * Accepts a fully-qualified class name or a short (unqualified) name.
	 * Short names are resolved by scanning the autodiscovery paths.
	 *
	 * @subcommand inspect
	 * @synopsis <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--depth=<depth>] [--format=<format>]
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
	 * [--format=<format>]
	 * : Output format: ascii uses +/- borders instead of box-drawing characters
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
		$this->parse_format_flag( $assoc_args );

		// Resolve short class names via autodiscovery.
		if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			$class_name = $this->resolve_short_name( $class_name, $path, $autowiring_paths );
		}

		$type        = $this->inspector->get_type( $class_name );
		$config_info = $this->get_config_binding( $class_name, $path );

		$this->log_class_header( $class_name, $type, $path );

		// Warnings.
		if ( 'interface' === $type && ! $config_info ) {
			$this->warning( 'Interface has no binding in wpdi-config.php - not resolvable' );
		}

		if ( 'abstract' === $type ) {
			$this->warning( 'Abstract class is not instantiable' );
		}

		// Build and display tree.
		$rows = array();
		$this->collect_tree_rows( $class_name, 0, array(), $rows, '', 0, $max_depth );

		// Skip the root row — the class identity is already shown by log_class_header.
		$rows = array_slice( $rows, 1 );
		$this->render_tree( $rows );
		$this->results_found( $rows, '1 dependency', '%d dependencies', 'no dependencies'  );
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
				'fqcn'         => $this->get_namespace( $class_name ) ?: $class_name,
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
			$connector  = $this->tree_connector( $is_last );
			$next_pad   = $this->tree_indent( $is_last );
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
			$this->error( "Class or interface not found: {$short_name}" );
		}

		if ( count( $matches ) > 1 ) {
			$this->log( "Ambiguous class name '{$short_name}'. Did you mean:" );

			foreach ( $matches as $match ) {
				$this->log( "  - {$match}" );
			}

			$this->error( 'Please use a fully-qualified class name.' );
		}

		return $matches[0];
	}

}
