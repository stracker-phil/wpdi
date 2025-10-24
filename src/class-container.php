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
	private array $instances = array();
	private array $resolving = array();
	private bool $is_compiled = false;

	/**
	 * Bind a service to the container
	 * Only accepts class names or interfaces - no magic strings
	 */
	public function bind( string $abstract, ?callable $factory = null, bool $singleton = true ): void {
		// Validate that abstract is a class or interface name
		if ( ! class_exists( $abstract ) && ! interface_exists( $abstract ) ) {
			throw new Container_Exception( "'{$abstract}' must be a valid class or interface name" );
		}

		if ( null === $factory ) {
			$factory = function() use ( $abstract ) {
				return $this->autowire( $abstract );
			};
		}

		// Note: is_callable() check omitted - the ?callable type hint ensures this at compile time

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
			isset( $this->instances[ $id ] ) ||
			class_exists( $id );
	}

	/**
	 * Load configuration from file
	 */
	public function load_config( array $config ): void {
		foreach ( $config as $abstract => $factory ) {
			$this->bind( $abstract, $factory );
		}
	}

	/**
	 * Load compiled class list from cache
	 *
	 * @param array $classes Array of discovered class names from cache
	 */
	public function load_compiled( array $classes ): void {
		// Bind each cached class (autowiring will recreate factories)
		foreach ( $classes as $class ) {
			if ( ! isset( $this->bindings[ $class ] ) ) {
				$this->bind( $class );
			}
		}
		$this->is_compiled = true;
	}

	/**
	 * Initialize container with auto-discovery
	 */
	public function initialize( string $base_path ): void {
		$cache_file = $base_path . '/cache/wpdi-container.php';
		$config_file = $base_path . '/wpdi-config.php';

		// Load user configuration first
		if ( file_exists( $config_file ) ) {
			$this->load_config( require $config_file );
		}

		// Load cached services or auto-discover
		if ( file_exists( $cache_file ) && 'production' === wp_get_environment_type() ) {
			$this->load_compiled( require $cache_file );
		} else {
			$discovery = new Auto_Discovery();
			$services = $discovery->discover( $base_path . '/src' );

			foreach ( $services as $class ) {
				if ( ! isset( $this->bindings[ $class ] ) ) {
					$this->bind( $class );
				}
			}

			// Generate cache file with discovered classes (not bindings)
			if ( ! file_exists( $cache_file ) ) {
				$compiler = new Compiler();
				$compiler->compile( $services, $cache_file );
			}
		}
	}

	/**
	 * Resolve a binding
	 *
	 * @return mixed Service instance
	 */
	private function resolve_binding( string $abstract ) {
		$binding = $this->bindings[ $abstract ];
		$instance = $binding['factory']();

		if ( $binding['singleton'] ) {
			$this->instances[ $abstract ] = $instance;
		}

		return $instance;
	}

	/**
	 * Autowire a class using reflection
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
				$instance = new $class_name( ...$dependencies );
			}

			// Cache as singleton by default
			$this->instances[ $class_name ] = $instance;

			return $instance;
		} finally {
			// Always remove from resolution stack
			unset( $this->resolving[ $class_name ] );
		}
	}

	/**
	 * Resolve constructor dependencies
	 */
	private function resolve_dependencies( array $parameters ): array {
		$dependencies = array();

		foreach ( $parameters as $parameter ) {
			$dependencies[] = $this->resolve_parameter( $parameter );
		}

		return $dependencies;
	}

	/**
	 * Resolve a single parameter
	 *
	 * @return mixed Resolved parameter value
	 */
	private function resolve_parameter( ReflectionParameter $parameter ) {
		$type = $parameter->getType();

		if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
			$type_name = $type->getName();

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
		$this->bindings = array();
		$this->instances = array();
		$this->resolving = array();
	}
}