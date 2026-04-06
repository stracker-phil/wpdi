<?php
/**
 * WP-CLI command for inspecting WPDI service resolution
 *
 * @package WPDI\Commands
 */

namespace WPDI\Commands;

/**
 * Inspect how a class is resolved by WPDI
 */
class Inspect_Command extends Command {

	/**
	 * Inspect a class and display its dependency tree
	 *
	 * Accepts a fully qualified class name or a short (unqualified) name.
	 * Short names are resolved by scanning the autodiscovery paths.
	 *
	 * @subcommand inspect
	 * @synopsis <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--depth=<depth>]
	 *           [--format=<format>]
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
	 * : Output format: table (default), ascii, json, yaml, csv
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
		$this->load_module_cache( $path, $autowiring_paths );

		// Resolve short class names from cache.
		if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			$class_name = $this->resolve_short_name( $class_name );
		}

		if ( $this->is_data_format() ) {
			$data = $this->collect_tree_data( $class_name, 0, array(), $max_depth );

			if ( 'json' === $this->format ) {
				echo wp_json_encode( $data ) . "\n";
			} else {
				$flat = array();
				$this->flatten_tree_data( $data, $flat );
				$this->format_items( $flat, array( 'depth', 'param', 'type', 'class' ) );
			}

			return;
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
		$this->results_found( $rows, '1 dependency', '%d dependencies', 'no dependencies' );
	}

	/**
	 * Check if a class has a binding in wpdi-config.php.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $path       Module base path.
	 * @return bool True if bound in config.
	 */
	private function get_config_binding( string $class_name, string $path ): bool {
		$config = null !== $this->cache_data
			? $this->cache_data['bindings']
			: $this->load_config( $path );

		return isset( $config[ $class_name ] );
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
				'fqcn'         => $this->get_namespace( $class_name ) ? $this->get_namespace( $class_name ) : $class_name,
			);
		}

		if ( in_array( $class_name, $visited, true ) ) {
			return;
		}

		$visited[] = $class_name;

		if ( $max_depth > 0 && $depth >= $max_depth ) {
			return;
		}

		$param_map = $this->get_full_param_map( $class_name );
		$count     = count( $param_map );
		$index     = 0;

		foreach ( $param_map as $param_name => $param_info ) {
			$dep_fqcn   = $param_info['type'];
			$is_builtin = $param_info['builtin'];
			$is_last    = ( $index === $count - 1 );
			$connector  = $this->tree_connector( $is_last );
			$next_pad   = $this->tree_indent( $is_last );
			$row_prefix = $child_prefix . $connector;
			$row_width  = $prefix_width + 4;

			if ( $is_builtin ) {
				// Built-in types (string, bool, array, callable, etc.) are leaf
				// nodes — the container cannot autowire them.
				$rows[] = array(
					'prefix'       => $row_prefix,
					'prefix_width' => $row_width,
					'label'        => $param_name,
					'type'         => 'builtin',
					'fqcn'         => $dep_fqcn,
				);
			} elseif ( in_array( $dep_fqcn, $visited, true ) ) {
				$dep_type = $this->format_type_label( $this->inspector->get_type( $dep_fqcn ) );
				$rows[]   = array(
					'prefix'       => $row_prefix,
					'prefix_width' => $row_width,
					'label'        => $param_name,
					'type'         => $dep_type,
					'fqcn'         => $dep_fqcn . ' [CIRCULAR]',
				);
			} else {
				$dep_type = $this->format_type_label( $this->inspector->get_type( $dep_fqcn ) );
				$rows[]   = array(
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
	 * Recursively collect a hierarchical data structure for machine-readable output.
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param int    $depth      Current tree depth.
	 * @param array  $visited    Classes already visited (circular detection).
	 * @param int    $max_depth  Maximum depth (0 = unlimited).
	 * @return array Hierarchical tree data.
	 */
	private function collect_tree_data( string $class_name, int $depth, array $visited, int $max_depth ): array {
		$type_label = $this->format_type_label( $this->inspector->get_type( $class_name ) );
		$data       = array(
			'class'        => $class_name,
			'type'         => $type_label,
			'dependencies' => array(),
		);

		if ( in_array( $class_name, $visited, true ) ) {
			$data['circular'] = true;

			return $data;
		}

		$visited[] = $class_name;

		if ( $max_depth > 0 && $depth >= $max_depth ) {
			return $data;
		}

		foreach ( $this->get_full_param_map( $class_name ) as $param_name => $param_info ) {
			$dep_fqcn   = $param_info['type'];
			$is_builtin = $param_info['builtin'];

			if ( $is_builtin ) {
				$data['dependencies'][] = array(
					'param'        => $param_name,
					'class'        => $dep_fqcn,
					'type'         => 'builtin',
					'dependencies' => array(),
				);
			} elseif ( in_array( $dep_fqcn, $visited, true ) ) {
				$dep_type               = $this->format_type_label( $this->inspector->get_type( $dep_fqcn ) );
				$data['dependencies'][] = array(
					'param'        => $param_name,
					'class'        => $dep_fqcn,
					'type'         => $dep_type,
					'circular'     => true,
					'dependencies' => array(),
				);
			} else {
				$child          = $this->collect_tree_data( $dep_fqcn, $depth + 1, $visited, $max_depth );
				$child['param'] = $param_name;

				$data['dependencies'][] = $child;
			}
		}

		return $data;
	}

	/**
	 * Flatten a hierarchical tree data structure into rows for csv/yaml output.
	 *
	 * @param array $node  Tree node from collect_tree_data().
	 * @param array $flat  Flat rows collected by reference.
	 * @param int   $depth Current depth level.
	 */
	private function flatten_tree_data( array $node, array &$flat, int $depth = 0 ): void {
		$flat[] = array(
			'depth' => $depth,
			'param' => $node['param'] ?? '',
			'type'  => $node['type'],
			'class' => $node['class'] . ( ! empty( $node['circular'] ) ? ' [CIRCULAR]' : '' ),
		);

		foreach ( $node['dependencies'] as $child ) {
			$this->flatten_tree_data( $child, $flat, $depth + 1 );
		}
	}

}
