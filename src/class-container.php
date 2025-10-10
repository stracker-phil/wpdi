<?php
/**
 * Core WPDI Container implementing PSR-11 with WordPress coding standards
 */

namespace WPDI;

use Psr\Container\ContainerInterface;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;

class Container implements ContainerInterface {
	private array $bindings = array();
	private array $instances = array();
	private bool $is_compiled = false;

	/**
	 * Bind a service to the container
	 * Only accepts class names or interfaces - no magic strings
	 */
	public function bind( string $abstract, callable $factory = null, bool $singleton = true ): void {
		// Validate that abstract is a class or interface name
		if ( ! class_exists( $abstract ) && ! interface_exists( $abstract ) ) {
			throw new Container_Exception( "'{$abstract}' must be a valid class or interface name" );
		}

		if ( null === $factory ) {
			$factory = function() use ( $abstract ) {
				return $this->autowire( $abstract );
			};
		}

		if ( ! is_callable( $factory ) ) {
			throw new Container_Exception( "Factory must be callable for {$abstract}" );
		}

		$this->bindings[ $abstract ] = array(
			'factory'   => $factory,
			'singleton' => $singleton,
		);
	}

	/**
	 * Get service from container (PSR-11)
	 * Only accepts class names - no magic strings
	 */
	public function get( string $id ): mixed {
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
	 * Load compiled service definitions
	 */
	public function load_compiled( array $compiled ): void {
		$this->bindings = array_merge( $this->bindings, $compiled );
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

			// Generate cache file
			if ( ! file_exists( $cache_file ) ) {
				$compiler = new Compiler();
				$compiler->compile( $this->bindings, $cache_file );
			}
		}
	}

	/**
	 * Resolve a binding
	 */
	private function resolve_binding( string $abstract ): mixed {
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
	 */
	private function resolve_parameter( ReflectionParameter $parameter ): mixed {
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

		throw new Container_Exception(
			"Cannot resolve parameter '{$parameter->getName()}' of type '{$type?->getName()}' " .
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
	}
}