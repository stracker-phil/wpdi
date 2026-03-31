<?php
/**
 * WP-CLI command for inspecting WPDI service resolution
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Class_Inspector;
use ReflectionClass;
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
	 * Inspect a class and explain its autowiring resolution path
	 *
	 * ## OPTIONS
	 *
	 * <class>
	 * : Fully-qualified class or interface name to inspect
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di inspect 'My_Plugin\Services\Payment_Gateway'
	 *     wp di inspect 'My_Plugin\Contracts\Logger_Interface' --path=/path/to/module
	 */
	public function __invoke( $args, $assoc_args ) {
		$class_name       = $args[0];
		$path             = $assoc_args['path'] ?? getcwd();
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );

		if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			WP_CLI::error( "Class or interface not found: {$class_name}" );
		}

		$type        = $this->inspector->get_type( $class_name );
		$is_concrete = $this->inspector->is_concrete( $class_name );

		// Determine source.
		$source      = $this->determine_source( $class_name, $path, $autowiring_paths );
		$config_info = $this->get_config_binding( $class_name, $path );

		// Summary.
		WP_CLI::log( "Class:        {$class_name}" );
		WP_CLI::log( "Type:         {$type}" );
		WP_CLI::log( 'Autowirable:  ' . ( $is_concrete ? 'yes' : 'no' ) );
		WP_CLI::log( "Source:       {$source}" );

		if ( $config_info ) {
			WP_CLI::log( 'Config:       bound in wpdi-config.php' );
		}

		if ( 'interface' === $type && ! $config_info ) {
			WP_CLI::warning( 'Interface has no binding in wpdi-config.php - not resolvable' );
		}

		if ( 'abstract' === $type ) {
			WP_CLI::warning( 'Abstract class is not instantiable' );
		}

		// Constructor parameters.
		$params = $this->get_constructor_params( $class_name );

		if ( empty( $params ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( 'Constructor:  no parameters' );
		} else {
			WP_CLI::log( '' );
			WP_CLI::log( 'Constructor Dependencies:' );
			WP_CLI\Utils\format_items( 'table', $params, array( 'parameter', 'type', 'resolution', 'detail' ) );
		}

		// Dependency tree.
		WP_CLI::log( '' );
		WP_CLI::log( 'Dependency Tree:' );

		$tree_lines = array();
		$this->build_dependency_tree( $class_name, $path, 0, array(), $tree_lines );

		foreach ( $tree_lines as $line ) {
			WP_CLI::log( $line );
		}
	}

	/**
	 * Determine the source of a class (autodiscovery, config, or external)
	 *
	 * @param string $class_name       Fully-qualified class name.
	 * @param string $path             Module base path.
	 * @param array  $autowiring_paths Autowiring paths.
	 * @return string Source description.
	 */
	private function determine_source( string $class_name, string $path, array $autowiring_paths ): string {
		// Check autodiscovery paths.
		$discovery  = new Auto_Discovery();
		$discovered = array();

		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;
			if ( is_dir( $full_path ) ) {
				$discovered = array_merge( $discovered, $discovery->discover( $full_path ) );
			}
		}

		if ( isset( $discovered[ $class_name ] ) ) {
			$relative = $this->make_relative( $discovered[ $class_name ]['path'], $path );

			return "autodiscovery ({$relative})";
		}

		// Check config.
		$config_file = $path . '/wpdi-config.php';
		if ( file_exists( $config_file ) ) {
			$config = require $config_file;
			if ( isset( $config[ $class_name ] ) ) {
				return 'wpdi-config.php';
			}
		}

		return 'external (not in autodiscovery or config)';
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
	 * Get constructor parameter details for display
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return array Array of parameter info for table display.
	 */
	private function get_constructor_params( string $class_name ): array {
		$reflection = $this->inspector->get_reflection( $class_name );

		if ( ! $reflection ) {
			return array();
		}

		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return array();
		}

		$params = array();

		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();

			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				$type_name  = $type->getName();
				$type_label = $this->inspector->get_type( $type_name );
				$resolution = $this->inspector->is_concrete( $type_name ) ? 'autowiring' : 'config';
				$detail     = $type_name;

				if ( $type->allowsNull() ) {
					$detail .= ' (nullable)';
				}

				$params[] = array(
					'parameter'  => '$' . $param->getName(),
					'type'       => $type_label,
					'resolution' => $resolution,
					'detail'     => $detail,
				);
			} elseif ( $type instanceof ReflectionNamedType ) {
				$default = $this->format_default_value( $param );

				$params[] = array(
					'parameter'  => '$' . $param->getName(),
					'type'       => 'scalar',
					'resolution' => 'value',
					'detail'     => $type->getName() . ' (' . $default . ')',
				);
			} else {
				$params[] = array(
					'parameter'  => '$' . $param->getName(),
					'type'       => 'untyped',
					'resolution' => 'value',
					'detail'     => $this->format_default_value( $param ),
				);
			}
		}

		return $params;
	}

	/**
	 * Format a parameter's default value for display.
	 *
	 * @param \ReflectionParameter $param Reflection parameter.
	 * @return string Formatted default value string.
	 */
	private function format_default_value( \ReflectionParameter $param ): string {
		if ( $param->isDefaultValueAvailable() ) {
			$value = $param->getDefaultValue();

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- CLI debug output.
			return 'default: ' . var_export( $value, true );
		}

		if ( $param->allowsNull() ) {
			return 'nullable';
		}

		return 'required';
	}

	/**
	 * Build dependency tree recursively
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @param string $path       Module base path.
	 * @param int    $depth      Current tree depth.
	 * @param array  $visited    Classes already visited (for circular detection).
	 * @param array  $lines      Output lines (passed by reference).
	 */
	private function build_dependency_tree( string $class_name, string $path, int $depth, array $visited, array &$lines ): void {
		$indent = str_repeat( '  ', $depth );
		$prefix = 0 === $depth ? '' : '-> ';

		// Circular dependency detection.
		if ( in_array( $class_name, $visited, true ) ) {
			$lines[] = $indent . $prefix . $class_name . ' [CIRCULAR]';

			return;
		}

		$lines[] = $indent . $prefix . $class_name;

		$visited[] = $class_name;

		$dependencies = $this->inspector->get_dependencies( $class_name );

		if ( empty( $dependencies ) ) {
			$lines[] = $indent . '  (no dependencies)';

			return;
		}

		foreach ( $dependencies as $dep ) {
			$this->build_dependency_tree( $dep, $path, $depth + 1, $visited, $lines );
		}
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
}
