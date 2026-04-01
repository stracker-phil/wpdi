<?php
/**
 * WP-CLI command for finding classes that depend on a given class or interface
 */

namespace WPDI\Commands;

use WP_CLI;
use WPDI\Auto_Discovery;
use WPDI\Class_Inspector;
use ReflectionNamedType;

/**
 * Find all classes that depend on a given class or interface
 */
class Depends_Command {
	private Class_Inspector $inspector;

	public function __construct() {
		$this->inspector = new Class_Inspector();
	}

	/**
	 * List all classes that depend on a given class or interface
	 *
	 * Accepts a fully-qualified class or interface name, or a short (unqualified) name.
	 * Short names are resolved by scanning the autodiscovery paths and their dependencies.
	 *
	 * @subcommand depends
	 * @synopsis <class> [--dir=<dir>] [--autowiring-paths=<paths>]
	 *
	 * ## OPTIONS
	 *
	 * <class>
	 * : Class or interface name to find dependents for (short or fully-qualified)
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * [--autowiring-paths=<paths>]
	 * : Comma-separated autowiring paths relative to module (default: src)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di depends LoggerInterface
	 *     wp di dep LoggerInterface
	 *     wp di depends 'My_Plugin\Contracts\PaymentGatewayInterface'
	 *     wp di depends SimpleClass --autowiring-paths=src,lib
	 */
	public function __invoke( $args, $assoc_args ) {
		$class_name       = $args[0];
		$path             = $assoc_args['dir'] ?? getcwd();
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );

		// Resolve short class/interface names via autodiscovery paths.
		// Only treat as a short name when there is no namespace separator — a FQCN
		// is used as-is so that unloaded interfaces are handled correctly by
		// find_dependents() via reflection rather than short-name matching.
		$is_fqcn = false !== strpos( $class_name, '\\' );
		if ( ! $is_fqcn && ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			$class_name = $this->resolve_short_name( $class_name, $path, $autowiring_paths );
		}

		$dependents = $this->find_dependents( $class_name, $path, $autowiring_paths );

		$short_name = $this->get_short_class_name( $class_name );
		$namespace  = $this->get_namespace( $class_name );

		WP_CLI::log( WP_CLI::colorize( "%WDependents of {$short_name}%n" ) . ( $namespace ? WP_CLI::colorize( " %8({$namespace})%n" ) : '' ) . ':' );
		WP_CLI::log( '' );

		if ( empty( $dependents ) ) {
			WP_CLI::log( WP_CLI::colorize( '%y-- no dependents found --%n' ) );
			return;
		}

		$this->display_dependents( $dependents );
	}

	/**
	 * Find all concrete classes in autowiring paths that depend on the target class
	 *
	 * Also finds classes that inject an interface the target implements when that
	 * interface is bound in wpdi-config.php (i.e. the target is the runtime implementation).
	 *
	 * @param string $target_fqcn     Fully-qualified target class/interface name.
	 * @param string $path            Module base path.
	 * @param array  $autowiring_paths Autowiring paths.
	 * @return array List of dependent entries: {fqcn, param, type, via}.
	 */
	private function find_dependents( string $target_fqcn, string $path, array $autowiring_paths ): array {
		$via_interfaces = $this->get_config_bound_interfaces( $target_fqcn, $path );
		$discovery      = new Auto_Discovery();
		$dependents     = array();

		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				continue;
			}

			foreach ( $discovery->discover( $full_path ) as $fqcn => $metadata ) {
				$param_map    = $this->get_constructor_param_map( $fqcn );
				$direct_match = null;
				$via_match    = null;

				foreach ( $param_map as $param_name => $dep_fqcn ) {
					if ( $dep_fqcn === $target_fqcn ) {
						$direct_match = array(
							'fqcn'  => $fqcn,
							'param' => $param_name,
							'type'  => $this->inspector->get_type( $fqcn ),
							'via'   => null,
						);
						break; // Direct match wins — no need to look further.
					}

					if ( null === $via_match && in_array( $dep_fqcn, $via_interfaces, true ) ) {
						$via_match = array(
							'fqcn'  => $fqcn,
							'param' => $param_name,
							'type'  => $this->inspector->get_type( $fqcn ),
							'via'   => $dep_fqcn,
						);
						// Keep iterating: a direct match later in the param list takes priority.
					}
				}

				if ( null !== $direct_match ) {
					$dependents[] = $direct_match;
				} elseif ( null !== $via_match ) {
					$dependents[] = $via_match;
				}
			}
		}

		return $dependents;
	}

	/**
	 * Get interfaces implemented by the target that are also bound in wpdi-config.php
	 *
	 * Used to surface classes that depend on the target indirectly via a config-bound interface.
	 *
	 * @param string $target_fqcn Target class FQCN.
	 * @param string $path        Module base path.
	 * @return array Interface FQCNs.
	 */
	private function get_config_bound_interfaces( string $target_fqcn, string $path ): array {
		$reflection = $this->inspector->get_reflection( $target_fqcn );

		if ( ! $reflection || ! $reflection->isInstantiable() ) {
			return array();
		}

		$implemented = $reflection->getInterfaceNames();

		if ( empty( $implemented ) ) {
			return array();
		}

		$config_file = $path . '/wpdi-config.php';

		if ( ! file_exists( $config_file ) ) {
			return array();
		}

		$config = require $config_file;

		return array_values( array_intersect( $implemented, array_keys( $config ) ) );
	}

	/**
	 * Display dependent entries as a formatted, column-aligned list
	 *
	 * @param array $dependents List of dependent entries.
	 */
	private function display_dependents( array $dependents ): void {
		$max_name  = 0;
		$max_type  = 0;
		$max_param = 0;

		foreach ( $dependents as $entry ) {
			$name_len = strlen( $this->get_short_class_name( $entry['fqcn'] ) );
			if ( $name_len > $max_name ) {
				$max_name = $name_len;
			}

			$type_label = $this->format_type_label( $entry['type'] );
			$type_len   = strlen( $type_label );
			if ( $type_len > $max_type ) {
				$max_type = $type_len;
			}

			$param_len = strlen( $entry['param'] );
			if ( $param_len > $max_param ) {
				$max_param = $param_len;
			}
		}

		foreach ( $dependents as $entry ) {
			$short_name = $this->get_short_class_name( $entry['fqcn'] );
			$type_label = $this->format_type_label( $entry['type'] );
			$type_color = $this->get_type_color( $type_label );

			$pad_name  = $max_name - strlen( $short_name ) + 4;
			$pad_type  = $max_type - strlen( $type_label ) + 4;
			$pad_param = $max_param - strlen( $entry['param'] ) + 4;

			$via_suffix = '';
			if ( ! empty( $entry['via'] ) ) {
				$via_short  = $this->get_short_class_name( $entry['via'] );
				$via_suffix = '    ' . WP_CLI::colorize( "via %c{$via_short}%n" );
			}

			WP_CLI::log(
				WP_CLI::colorize( "%W{$short_name}%n" )
				. str_repeat( ' ', $pad_name )
				. WP_CLI::colorize( $type_color . $type_label . '%n' )
				. str_repeat( ' ', $pad_type )
				. WP_CLI::colorize( '%y' . $entry['param'] . '%n' )
				. str_repeat( ' ', $pad_param )
				. $this->get_namespace( $entry['fqcn'] )
				. $via_suffix
			);
		}
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
	 * Resolve a short (unqualified) class or interface name to its FQCN
	 *
	 * Scans discovered classes and their dependencies to find a match.
	 * Errors if no match or multiple ambiguous matches are found.
	 *
	 * @param string $short_name       Short class or interface name.
	 * @param string $path             Module base path.
	 * @param array  $autowiring_paths Autowiring paths.
	 * @return string Resolved FQCN.
	 */
	private function resolve_short_name( string $short_name, string $path, array $autowiring_paths ): string {
		$discovery = new Auto_Discovery();
		$all_fqcns = array();

		foreach ( $autowiring_paths as $autowiring_path ) {
			$full_path = $path . '/' . $autowiring_path;

			if ( ! is_dir( $full_path ) ) {
				continue;
			}

			foreach ( $discovery->discover( $full_path ) as $fqcn => $metadata ) {
				$all_fqcns[] = $fqcn;

				foreach ( $metadata['dependencies'] as $dep ) {
					$all_fqcns[] = $dep;
				}
			}
		}

		$matches = array();

		foreach ( array_unique( $all_fqcns ) as $fqcn ) {
			if ( $this->get_short_class_name( $fqcn ) === $short_name ) {
				$matches[] = $fqcn;
			}
		}

		if ( empty( $matches ) ) {
			WP_CLI::error( "Class or interface not found: {$short_name}" );
		}

		if ( count( $matches ) > 1 ) {
			WP_CLI::log( "Ambiguous name '{$short_name}'. Did you mean:" );

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
			return '';
		}

		return substr( $fqcn, 0, $pos );
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
}
