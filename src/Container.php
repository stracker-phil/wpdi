<?php
/**
 * PSR-11 dependency injection container with zero-config autowiring.
 *
 * @package WPDI
 */

namespace WPDI;

use Psr\Container\ContainerInterface;
use WPDI\Exceptions\Container_Exception;
use WPDI\Exceptions\Not_Found_Exception;
use WPDI\Exceptions\Circular_Dependency_Exception;
use ReflectionClass;
use ReflectionParameter;
use ReflectionNamedType;

/**
 * PSR-11 compatible DI container with zero-config autowiring via reflection.
 */
class Container implements ContainerInterface {
	/**
	 * Service bindings keyed by class/interface name.
	 *
	 * @var array
	 */
	private array $bindings = array();

	/**
	 * Contextual bindings keyed by abstract name.
	 *
	 * @var array
	 */
	private array $contextual_bindings = array();

	/**
	 * Cached constructor parameter descriptors from compiled cache.
	 *
	 * Keyed by class name. A value of `null` means the class has no constructor.
	 * Missing keys fall back to reflection-based autowiring.
	 *
	 * @var array<string, array[]|null>
	 */
	private array $class_constructors = array();

	/**
	 * Cached singleton instances (shared across all Container instances).
	 *
	 * Static so that multiple plugin Scopes on the same request share object
	 * identity for the same class. First container to resolve a class caches
	 * it; all subsequent containers reuse the cached instance.
	 *
	 * @var array
	 */
	private static array $instances = array();

	/**
	 * Classes currently being resolved (circular dependency guard).
	 *
	 * @var array
	 */
	private array $resolving = array();

	/**
	 * Cached Resolver wrapper.
	 *
	 * @var Resolver|null
	 */
	private ?Resolver $resolver = null;

	/**
	 * Register a service binding in the container.
	 *
	 * Only accepts class/interface names -- no magic strings. When no factory is
	 * provided, the class is autowired via reflection on first resolution.
	 *
	 * @param string        $abstract  Class or interface name.
	 * @param callable|null $factory   Factory receiving a Resolver; null = autowire.
	 * @param bool          $singleton Cache the instance after first resolution.
	 *
	 * @throws Not_Found_Exception When $abstract is not a valid class or interface.
	 */
	public function bind( string $abstract, ?callable $factory = null, bool $singleton = true ): void {
		// Validate that abstract is a class or interface name.
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
	 * Resolve a service from the container (PSR-11).
	 *
	 * Resolution cascades through four strategies, returning the first match:
	 *
	 * 1. Singleton cache   -- avoids re-resolution; ensures object identity.
	 * 2. Explicit binding   -- factory registered via bind() or load_config().
	 * 3. Contextual binding -- direct get() has no parameter context, so the
	 *                          'default' branch is the only viable option.
	 * 4. Autowiring         -- reflection-based instantiation; result is cached
	 *                          as a singleton (see Singleton Scope in autowire()).
	 *
	 * @param string $id Class or interface name.
	 * @return mixed Service instance.
	 *
	 * @throws Not_Found_Exception          When $id is not a valid class/interface or cannot be
	 *                                      resolved.
	 * @throws Circular_Dependency_Exception When a circular dependency chain is detected.
	 * @throws Container_Exception          When a dependency is not instantiable or unresolvable.
	 */
	public function get( string $id ) {
		if ( ! class_exists( $id ) && ! interface_exists( $id ) ) {
			throw new Not_Found_Exception( "'{$id}' must be a valid class or interface name" );
		}

		// 1. Singleton cache (static — shared across all Container instances).
		if ( isset( self::$instances[ $id ] ) ) {
			return self::$instances[ $id ];
		}

		// 2. Explicit binding.
		if ( isset( $this->bindings[ $id ] ) ) {
			return $this->resolve_binding( $id );
		}

		// 3. Contextual binding (direct call — default branch only).
		if ( isset( $this->contextual_bindings[ $id ] ) ) {
			return $this->resolve_contextual_binding( $id, 'default' );
		}

		// 4. Autowiring fallback.
		if ( class_exists( $id ) ) {
			return $this->autowire( $id );
		}

		throw new Not_Found_Exception( "Service {$id} not found" );
	}

	/**
	 * Check whether a service can be resolved (PSR-11).
	 *
	 * Returns true for explicit bindings, contextual bindings, cached singletons,
	 * and any existing class (autowirable on demand).
	 *
	 * @param string $id Class or interface name.
	 * @return bool
	 */
	public function has( string $id ): bool {
		return isset( $this->bindings[ $id ] ) ||
			isset( $this->contextual_bindings[ $id ] ) ||
			isset( self::$instances[ $id ] ) ||
			class_exists( $id );
	}

	/**
	 * Register a contextual binding for an interface or class.
	 *
	 * Allows different concrete implementations per consumer parameter name.
	 * Each branch is cached as a separate singleton (keyed by abstract::$param).
	 *
	 * @param string $abstract Interface or class name.
	 * @param array  $bindings Map of '$param_name' => concrete class name strings.
	 *                         Use 'default' as the fallback key.
	 *
	 * @throws Container_Exception When $abstract or a binding value is invalid.
	 */
	public function bind_contextual( string $abstract, array $bindings ): void {
		if ( ! class_exists( $abstract ) && ! interface_exists( $abstract ) ) {
			throw new Container_Exception( "'{$abstract}' must be a valid class or interface name" );
		}

		$validated = array();

		foreach ( $bindings as $key => $concrete ) {
			if ( 'default' !== $key && ! preg_match( '/^\$[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $key ) ) {
				throw new Container_Exception( "Invalid contextual binding key '{$key}': must be a \$variable_name or 'default'" );
			}

			if ( ! is_string( $concrete ) || ( ! class_exists( $concrete ) && ! interface_exists( $concrete ) ) ) {
				throw new Container_Exception( "Contextual binding for '{$abstract}[{$key}]' must be a valid class or interface name" );
			}

			$validated[ $key ] = $concrete;
		}

		$this->contextual_bindings[ $abstract ] = $validated;
	}

	/**
	 * Load interface bindings from configuration
	 *
	 * @param array $config Map of interface => concrete class name (string) or
	 *                      interface => array of contextual bindings.
	 *
	 * @throws Container_Exception When a binding value is not a valid class or interface name.
	 */
	public function load_config( array $config ): void {
		foreach ( $config as $abstract => $concrete ) {
			if ( is_array( $concrete ) ) {
				$this->bind_contextual( $abstract, $concrete );
			} else {
				if ( ! is_string( $concrete ) || ( ! class_exists( $concrete ) && ! interface_exists( $concrete ) ) ) {
					throw new Container_Exception(
						"Binding for '{$abstract}' must be a valid class or interface name, got " .
						( is_string( $concrete ) ? "'{$concrete}'" : gettype( $concrete ) )
					);
				}

				$this->bind( $abstract, fn() => $this->get( $concrete ) );
			}
		}
	}

	/**
	 * Load compiled cache — registers interface bindings from cache
	 *
	 * Concrete classes from the 'classes' section are not pre-bound here;
	 * they are autowired on demand when first requested via get().
	 *
	 * @param array $cache Array with 'classes' (metadata) and 'bindings' (interface => class)
	 *                     sections.
	 */
	public function load_compiled( array $cache ): void {
		$bindings = $cache['bindings'] ?? array();

		if ( ! empty( $bindings ) ) {
			$this->load_config( $bindings );
		}

		// Store cached constructor descriptors for reflection-free autowiring.
		$classes = $cache['classes'] ?? array();
		foreach ( $classes as $class => $metadata ) {
			if ( is_array( $metadata ) && array_key_exists( 'constructor', $metadata ) ) {
				$this->class_constructors[ $class ] = $metadata['constructor'];
			}
		}
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
			self::$instances[ $abstract ] = $instance;
		}

		return $instance;
	}

	/**
	 * Instantiate a class by resolving its constructor dependencies via reflection.
	 *
	 * Singleton Scope: every autowired instance is cached, so subsequent get() calls
	 * return the same object. WordPress modules rarely need transient (per-call)
	 * service instances; caching avoids redundant reflection and ensures identity.
	 *
	 * @throws Circular_Dependency_Exception When a circular dependency chain is detected.
	 * @throws Container_Exception          When the class is not instantiable or a dependency is
	 *                                      unresolvable.
	 */
	private function autowire( string $class_name ): object {
		// Check for circular dependency.
		if ( isset( $this->resolving[ $class_name ] ) ) {
			$chain = implode( ' -> ', array_keys( $this->resolving ) );
			throw new Circular_Dependency_Exception(
				"Circular dependency detected: {$chain} -> {$class_name}"
			);
		}

		// Mark as currently resolving.
		$this->resolving[ $class_name ] = true;

		try {
			// Prefer cached constructor descriptors (no reflection needed).
			if ( array_key_exists( $class_name, $this->class_constructors ) ) {
				$instance = $this->autowire_from_cache( $class_name );
			} else {
				$instance = $this->autowire_from_reflection( $class_name );
			}

			// Auto-wiring treats every class as a singleton.
			self::$instances[ $class_name ] = $instance;

			return $instance;
		} finally {
			// Always remove from the resolution stack.
			unset( $this->resolving[ $class_name ] );
		}
	}

	/**
	 * Instantiate a class using cached constructor descriptors (no reflection).
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return object New instance.
	 * @throws Container_Exception When a dependency is unresolvable.
	 */
	private function autowire_from_cache( string $class_name ): object {
		$params = $this->class_constructors[ $class_name ];

		if ( null === $params ) {
			return new $class_name();
		}

		$dependencies = array_map(
			array( $this, 'resolve_cached_parameter' ),
			$params
		);

		return new $class_name( ...$dependencies );
	}

	/**
	 * Instantiate a class via reflection (fallback when no cache available).
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return object New instance.
	 * @throws Container_Exception When the class is not instantiable or a dependency is unresolvable.
	 */
	private function autowire_from_reflection( string $class_name ): object {
		$reflection = new ReflectionClass( $class_name );

		if ( ! $reflection->isInstantiable() ) {
			throw new Container_Exception( "Class {$class_name} is not instantiable" );
		}

		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return new $class_name();
		}

		$dependencies = array_map(
			array( $this, 'resolve_parameter' ),
			$constructor->getParameters()
		);

		return new $class_name( ...$dependencies );
	}

	/**
	 * Resolve a contextual binding by parameter name.
	 *
	 * @param string $abstract   Interface or class name.
	 * @param string $param_name Parameter name prefixed with '$', or 'default' for default.
	 * @return mixed Service instance.
	 *
	 * @throws Container_Exception Depending on an undefined class implementation (problem in wpdi-config.php).
	 */
	private function resolve_contextual_binding( string $abstract, string $param_name ) {
		$bindings = $this->contextual_bindings[ $abstract ];

		// Try an exact match first, then fall back to default.
		if ( isset( $bindings[ $param_name ] ) ) {
			$key = $param_name;
		} elseif ( isset( $bindings['default'] ) ) {
			$key = 'default';
		} else {
			throw new Container_Exception(
				"No contextual binding for '{$abstract}' matching parameter '{$param_name}' and no 'default' binding defined"
			);
		}

		$cache_key = $abstract . '::' . $key;

		if ( isset( self::$instances[ $cache_key ] ) ) {
			return self::$instances[ $cache_key ];
		}

		$instance                      = $this->get( $bindings[ $key ] );
		self::$instances[ $cache_key ] = $instance;

		return $instance;
	}

	/**
	 * Resolve a single parameter from a cached descriptor (no reflection).
	 *
	 * @param array $param Cached parameter descriptor with name, type, builtin, nullable, has_default, default.
	 * @return mixed Resolved parameter value.
	 * @throws Container_Exception Unresolvable dependency.
	 */
	private function resolve_cached_parameter( array $param ) {
		$type_name = $param['type'];

		if ( null !== $type_name && ! $param['builtin'] ) {
			// Check for contextual binding first.
			if ( isset( $this->contextual_bindings[ $type_name ] ) ) {
				return $this->resolve_contextual_binding( $type_name, '$' . $param['name'] );
			}

			if ( $this->has( $type_name ) ) {
				return $this->get( $type_name );
			}
		}

		if ( $param['has_default'] ) {
			return $param['default'];
		}

		if ( $param['nullable'] ) {
			return null;
		}

		$type_label = $type_name ?? 'unknown';
		throw new Container_Exception(
			"Cannot resolve parameter '{$param['name']}' of type '{$type_label}'"
		);
	}

	/**
	 * Resolve a single parameter
	 *
	 * @return mixed Resolved parameter value
	 * @throws Container_Exception Unresolvable dependency.
	 */
	private function resolve_parameter( ReflectionParameter $parameter ) {
		$type = $parameter->getType();

		if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
			$type_name = $type->getName();

			// Check for contextual binding first.
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
	 * Get all explicitly registered service identifiers (for debugging).
	 *
	 * @return string[] Class/interface names.
	 */
	public function get_registered(): array {
		return array_keys( $this->bindings );
	}

	/**
	 * Reset the container to its initial empty state (for testing).
	 */
	public function clear(): void {
		$this->bindings            = array();
		$this->contextual_bindings = array();
		$this->class_constructors  = array();
		self::$instances           = array();
		$this->resolving           = array();
		$this->resolver            = null;
	}

	/**
	 * Clear the shared singleton instance cache.
	 *
	 * Since $instances is static (shared across all Container instances),
	 * this method ensures test teardown fully resets singleton state.
	 * Called by Scope::clear() during test teardown.
	 */
	public static function clear_instances(): void {
		self::$instances = array();
	}

	/**
	 * Get the cached Resolver instance.
	 *
	 * Interface Segregation: Resolver exposes only get()/has(), preventing factory
	 * functions and bootstrap code from calling bind() or other mutation methods.
	 *
	 * @return Resolver
	 */
	public function resolver(): Resolver {
		if ( null === $this->resolver ) {
			$this->resolver = new Resolver( $this );
		}

		return $this->resolver;
	}
}
