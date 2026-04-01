<?php
/**
 * WP-CLI command for finding classes that depend on a given class or interface
 */

namespace WPDI\Commands;

use Closure;
use ReflectionException;
use ReflectionFunction;
use ReflectionNamedType;
use WP_CLI;
use WPDI\Auto_Discovery;

/**
 * Find all classes that depend on a given class or interface
 */
class Depends_Command extends Command {

	/**
	 * List all classes that depend on a given class or interface
	 *
	 * Accepts a fully-qualified class or interface name, or a short (unqualified) name.
	 * Short names are resolved by scanning the autodiscovery paths and their dependencies.
	 *
	 * @subcommand depends
	 * @synopsis <class> [--dir=<dir>] [--autowiring-paths=<paths>] [--format=<format>]
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
	 * [--format=<format>]
	 * : Output format: ascii uses +/- borders instead of box-drawing characters
	 *
	 * ## EXAMPLES
	 *
	 *     wp di depends LoggerInterface
	 *     wp di depends 'My_Plugin\Contracts\PaymentGatewayInterface'
	 *     wp di depends SimpleClass --autowiring-paths=src,lib
	 */
	public function __invoke( $args, $assoc_args ) {
		$class_name       = $args[0];
		$path             = $assoc_args['dir'] ?? getcwd();
		$autowiring_paths = $this->parse_autowiring_paths( $assoc_args );
		$this->parse_format_flag( $assoc_args );

		// Resolve short class/interface names via autodiscovery paths.
		// Only treat as a short name when there is no namespace separator — a FQCN
		// is used as-is so that unloaded interfaces are handled correctly by
		// find_dependents() via reflection rather than short-name matching.
		$is_fqcn = false !== strpos( $class_name, '\\' );
		if ( ! $is_fqcn && ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			$class_name = $this->resolve_short_name( $class_name, $path, $autowiring_paths );
		}

		$type = $this->inspector->get_type( $class_name );
		$this->log_class_header( $class_name, $type, $path );

		$dependents = $this->find_dependents( $class_name, $path, $autowiring_paths );

		if ( empty( $dependents ) ) {
			$this->log( WP_CLI::colorize( '%y-- no dependents found --%n' ) );

			return;
		}

		$config = $this->load_config( $path );
		$rows   = array();
		foreach ( $dependents as $entry ) {
			$rows[] = array(
				'type'           => $this->format_type_label( $entry['type'] ),
				'class'          => $entry['fqcn'],
				'param'          => $entry['param'],
				'config mapping' => $this->build_config_mapping_label( $class_name, $entry, $config ),
			);
		}

		$this->table(
			$rows,
			array( 'type', 'class', 'param', 'config mapping' ),
			array(
				'type'           => 'type_label',
				'class'          => 'class_name',
				'param'          => 'param',
				'config mapping' => 'via',
			)
		);
	}

	/**
	 * Find all concrete classes in autowiring paths that depend on the target class
	 *
	 * Also finds classes that inject an interface the target implements when that
	 * interface is bound in wpdi-config.php (i.e. the target is the runtime implementation).
	 *
	 * @param string $target_fqcn      Fully-qualified target class/interface name.
	 * @param string $path             Module base path.
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

		$config = $this->load_config( $path );

		return array_values( array_intersect( $implemented, array_keys( $config ) ) );
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
	 * Load wpdi-config.php and return its array, or an empty array if absent.
	 *
	 * @param string $path Module base path.
	 * @return array Config bindings.
	 */
	private function load_config( string $path ): array {
		$config_file = $path . '/wpdi-config.php';

		if ( ! file_exists( $config_file ) ) {
			return array();
		}

		$config = require $config_file;

		return is_array( $config ) ? $config : array();
	}

	/**
	 * Build the "config mapping" label for a single dependent entry.
	 *
	 * Binding lookup order (first match wins):
	 *   1. Indirect match (entry has a 'via' interface)       → "via InterfaceName"
	 *   2. $config[DependentClass]['$param']                  → "as ConcreteClass"
	 *   3. $config[TargetInterface]['$param']                 → "as ConcreteClass"
	 *   4. $config[TargetInterface] (simple non-array value)  → "as ConcreteClass"
	 *   5. No binding found                                   → "-"
	 *
	 * Both string class names and typed closures (fn():Concrete => ...) are resolved.
	 *
	 * @param string $target_fqcn FQCN of the class/interface being depended on.
	 * @param array  $entry       Dependent entry: {fqcn, param, type, via}.
	 * @param array  $config      Loaded wpdi-config.php array.
	 * @return string Display label.
	 */
	private function build_config_mapping_label( string $target_fqcn, array $entry, array $config ): string {
		if ( ! empty( $entry['via'] ) ) {
			return 'via ' . $this->get_short_class_name( $entry['via'] );
		}

		// Contextual binding keyed by dependent class: $config[Dependent]['$param'].
		$binding = $config[ $entry['fqcn'] ][ $entry['param'] ] ?? null;

		// Contextual binding keyed by target interface: $config[Interface]['$param'].
		if ( null === $binding && is_array( $config[ $target_fqcn ] ?? null ) ) {
			$binding = $config[ $target_fqcn ][ $entry['param'] ] ?? null;
		}

		// Simple global binding: $config[Interface] = ConcreteClass or closure.
		if ( null === $binding ) {
			$candidate = $config[ $target_fqcn ] ?? null;
			if ( ! is_array( $candidate ) ) {
				$binding = $candidate;
			}
		}

		$class = $this->resolve_binding_class( $binding );

		return null !== $class ? 'as ' . $this->get_short_class_name( $class ) : '-';
	}

	/**
	 * Extract the concrete class name from a binding value.
	 *
	 * Handles string class names and typed closures (reads the declared return type).
	 * Returns null when the class cannot be determined (untyped closure, scalar, etc.).
	 *
	 * @param mixed $binding Raw binding value from wpdi-config.php.
	 * @return string|null Resolved FQCN or short class name, or null.
	 */
	private function resolve_binding_class( $binding ): ?string {
		if ( is_string( $binding ) ) {
			return $binding;
		}

		if ( $binding instanceof Closure ) {
			try {
				$rt = ( new ReflectionFunction( $binding ) )->getReturnType();
				if ( $rt instanceof ReflectionNamedType && ! $rt->isBuiltin() ) {
					return $rt->getName();
				}
			} catch ( ReflectionException $e ) {
				return null;
			}
		}

		return null;
	}

}
