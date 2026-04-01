<?php
/**
 * Core WPDI Container implementing PSR-11 with WordPress coding standards
 */

namespace WPDI;

use Psr\Container\ContainerInterface;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;

class Container implements ContainerInterface {
	private array $bindings = array();
	private array $contextual_bindings = array();
	private array $instances = array();
	private array $resolving = array();
	private ?Resolver $resolver = null;

	/**
	 * Bind a service to the container
	 * Only accepts class names or interfaces - no magic strings
	 *
	 * @throws Not_Found_Exception When the requested (abstract) class or interface does not exist.
	 */
	public function bind( string $abstract, ?callable $factory = null, bool $singleton = true ): void {
		// Validate that abstract is a class or interface name
		if ( ! class_exists( $abstract ) && ! interface_exists( $abstract ) ) {
			throw new Not_Found_Exception( "'{$abstract}' must be a valid class or interface name" );
		}

		if ( null === $factory ) {
			$factory = fn() => $this->autowire( $abstract );
		}

		// Note: is_callable() check omitted - the ?callable type hint ensures this at compile time.

		$this->bindings[ $abstract ] = array(
			'factory'   => $factory,
			'singleton' => $singleton,
		);
	}

	/**
	 * Get service from container (PSR-11)
	 * Only accepts class names - no magic strings
	 *
	 * @return mixed Service instance
	 *
	 * @throws Not_Found_Exception When the requested class or interface does not exist.
	 * @throws Circular_Dependency_Exception When depending on a parent class
	 * @throws Container_Exception Dependency is not instantiable.
	 * @throws Container_Exception Unresolvable dependency.
	 */
	public function get( string $id ) {
		// Validate that id is a class name
		if ( ! class_exists( $id ) && ! interface_exists( $id ) ) {
			throw new Not_Found_Exception( "'{$id}' must be a valid class or interface name" );
		}

		// Return cached singleton
		if ( isset( $this->instances[ $id ] ) ) {
			return $this->instances[ $id ];
		}

		// Use explicit binding
		if ( isset( $this->bindings[ $id ] ) ) {
			return $this->resolve_binding( $id );
		}

		// Contextual binding called directly — use default branch
		if ( isset( $this->contextual_bindings[ $id ] ) ) {
			return $this->resolve_contextual_binding( $id, '' );
		}

		// Try autowiring
		if ( class_exists( $id ) ) {
			return $this->autowire( $id );
		}

		throw new Not_Found_Exception( "Service {$id} not found" );
	}

	/**
	 * Check if container has service (PSR-11)
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) ||
			isset( $this->contextual_bindings[ $id ] ) ||
			isset( $this->instances[ $id ] ) ||
			class_exists( $id );
	}

	/**
	 * Bind a contextual service to the container
	 *
	 * Accepts an array of factories keyed by parameter name (prefixed with '$')
	 * or an empty string for the default. Each branch is cached as a separate singleton.
	 *
	 * @param string $abstract  Interface or class name.
	 * @param array  $factories Map of '$param_name' => callable factories.
	 */
	public function bind_contextual( string $abstract, array $factories ): void {
		if ( ! class_exists( $abstract ) && ! interface_exists( $abstract ) ) {
			throw new Container_Exception( "'{$abstract}' must be a valid class or interface name" );
		}

		$validated = array();

		foreach ( $factories as $key => $factory ) {
			if ( '' !== $key && ! preg_match( '/^\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key ) ) {
				throw new Container_Exception( "Invalid contextual binding key '{$key}': must be a \$variable_name or empty string" );
			}

			if ( ! is_callable( $factory ) ) {
				throw new Container_Exception( "Contextual binding factory for '{$abstract}[{$key}]' must be callable" );
			}

			$validated[ $key ] = $factory;
		}

		$this->contextual_bindings[ $abstract ] = $validated;
	}

	/**
	 * Load configuration from file
	 */
	public function load_config( array $config ): void {
		foreach ( $config as $abstract => $factory ) {
			if ( is_array( $factory ) ) {
				$this->bind_contextual( $abstract, $factory );
			} else {
				$this->bind( $abstract, $factory );
			}
		}
	}

	/**
	 * Load compiled class list from cache
	 *
	 * Skips stale entries (classes that no longer exist after refactoring).
	 * The cache will be rebuilt on next compile or when staleness is detected.
	 *
	 * @param array $class_map Array mapping class names to metadata (path, mtime, dependencies)
	 */
	public function load_compiled( array $class_map ): void {
		// Bind each cached class (autowiring will recreate factories)
		foreach ( $class_map as $class => $metadata ) {
			if ( isset( $this->bindings[ $class ] ) ) {
				continue;
			}

			try {
				$this->bind( $class );
			} catch ( Container_Exception $e ) {
				// Skip stale cache entries (classes that no longer exist)
				continue;
			}
		}
	}

	/**
	 * Initialize container with auto-discovery
	 *
	 * @param string $scope_file       Path to the Scope implementation file (e.g., __FILE__)
	 * @param array  $autowiring_paths Relative paths to scan for autowirable classes (relative to
	 *                                 dirname($scope_file))
	 * @param string $environment      Environment type for cache behavior ('production' skips
	 *                                 staleness checks).
	 */
	public function initialize( string $scope_file, array $autowiring_paths = array( 'src' ), string $environment = 'development' ): void {
		$base_path   = dirname( $scope_file );
		$config_file = $base_path . '/wpdi-config.php';

		// Load user configuration first
		if ( file_exists( $config_file ) ) {
			$this->load_config( require $config_file );
		}

		// Delegate cache management
		$cache_manager = new Cache_Manager( $base_path, $autowiring_paths, $environment );
		$class_map     = $cache_manager->get_class_map( $scope_file );

		$this->load_compiled( $class_map );
	}

	/**
	 * Resolve a binding
	 *
	 * @return mixed Service instance
	 */
	private function resolve_binding( string $abstract ) {
		$binding  = $this->bindings[ $abstract ];
		$instance = $binding['factory']( $this->resolver() );

		if ( $binding['singleton'] ) {
			$this->instances[ $abstract ] = $instance;
		}

		return $instance;
	}

	/**
	 * Autowire a class using reflection
	 *
	 * @throws Circular_Dependency_Exception When depending on a parent class
	 * @throws Container_Exception Dependency is not instantiable.
	 * @throws Container_Exception Unresolvable dependency.
	 */
	private function autowire( string $class_name ): object {
		// Check for circular dependency
		if ( isset( $this->resolving[ $class_name ] ) ) {
			$chain = implode( ' -> ', array_keys( $this->resolving ) );
			throw new Circular_Dependency_Exception(
				"Circular dependency detected: {$chain} -> {$class_name}"
			);
		}

		// Mark as currently resolving
		$this->resolving[ $class_name ] = true;

		try {
			$reflection = new ReflectionClass( $class_name );

			if ( ! $reflection->isInstantiable() ) {
				throw new Container_Exception( "Class {$class_name} is not instantiable" );
			}

			$constructor = $reflection->getConstructor();

			if ( ! $constructor ) {
				$instance = new $class_name();
			} else {
				$dependencies = $this->resolve_dependencies( $constructor->getParameters() );
				$instance     = new $class_name( ...$dependencies );
			}

			// Auto-wiring treats every class as a singleton
			$this->instances[ $class_name ] = $instance;

			return $instance;
		} finally {
			// Always remove from the resolution stack
			unset( $this->resolving[ $class_name ] );
		}
	}

	/**
	 * Resolve constructor dependencies
	 *
	 * @throws Container_Exception Unresolvable dependency
	 */
	private function resolve_dependencies( array $parameters ): array {
		$dependencies = array();

		foreach ( $parameters as $parameter ) {
			$dependencies[] = $this->resolve_parameter( $parameter );
		}

		return $dependencies;
	}

	/**
	 * Resolve a contextual binding by parameter name
	 *
	 * @param string $abstract   Interface or class name.
	 * @param string $param_name Parameter name prefixed with '$', or empty string for default.
	 * @return mixed Service instance.
	 *
	 * @throws Container_Exception Depending on an undefined class implementation (problem in
	 *                             wpdi-config.php)
	 *                             {@see https://github.com/stracker-phil/wpdi/blob/main/docs/configuration.md#conditional-bindings}
	 */
	private function resolve_contextual_binding( string $abstract, string $param_name ) {
		$factories = $this->contextual_bindings[ $abstract ];

		// Try an exact match first, then fall back to default
		if ( isset( $factories[ $param_name ] ) ) {
			$key = $param_name;
		} elseif ( isset( $factories[''] ) ) {
			$key = '';
		} else {
			throw new Container_Exception(
				"No contextual binding for '{$abstract}' matching parameter '{$param_name}' and no default '' binding defined"
			);
		}

		$cache_key = $abstract . '::' . $key;

		if ( isset( $this->instances[ $cache_key ] ) ) {
			return $this->instances[ $cache_key ];
		}

		$instance                      = $factories[ $key ]( $this->resolver() );
		$this->instances[ $cache_key ] = $instance;

		return $instance;
	}

	/**
	 * Resolve a single parameter
	 *
	 * @return mixed Resolved parameter value
	 * @throws Container_Exception Unresolvable dependency
	 */
	private function resolve_parameter( ReflectionParameter $parameter ) {
		$type = $parameter->getType();

		if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
			$type_name = $type->getName();

			// Check for contextual binding first
			if ( isset( $this->contextual_bindings[ $type_name ] ) ) {
				$param_name = '$' . $parameter->getName();

				return $this->resolve_contextual_binding( $type_name, $param_name );
			}

			if ( $this->has( $type_name ) ) {
				return $this->get( $type_name );
			}
		}

		if ( $parameter->isDefaultValueAvailable() ) {
			return $parameter->getDefaultValue();
		}

		if ( $parameter->allowsNull() ) {
			return null;
		}

		$type_name = $type ? $type->getName() : 'unknown';
		throw new Container_Exception(
			"Cannot resolve parameter '{$parameter->getName()}' of type '{$type_name}' " .
			"in class '{$parameter->getDeclaringClass()->getName()}'"
		);
	}

	/**
	 * Get all registered services (for debugging)
	 */
	public function get_registered(): array {
		return array_keys( $this->bindings );
	}

	/**
	 * Clear all bindings and instances (for testing)
	 */
	public function clear(): void {
		$this->bindings            = array();
		$this->contextual_bindings = array();
		$this->instances           = array();
		$this->resolving           = array();
		$this->resolver            = null;
	}

	/**
	 * Get the cached resolver instance
	 *
	 * Returns a Resolver with limited API (get/has only).
	 * Used by factory functions and Scope::bootstrap().
	 */
	public function resolver(): Resolver {
		if ( null === $this->resolver ) {
			$this->resolver = new Resolver( $this );
		}

		return $this->resolver;
	}
}
