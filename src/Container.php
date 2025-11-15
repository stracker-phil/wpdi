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
	private ?Resolver $resolver = null;

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
			$factory = fn() => $this->autowire( $abstract );
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
	 * @param array $class_map Array mapping class names to metadata (path, mtime, dependencies)
	 */
	public function load_compiled( array $class_map ): void {
		// Bind each cached class (autowiring will recreate factories)
		foreach ( $class_map as $class => $metadata ) {
			if ( isset( $this->bindings[ $class ] ) ) {
				continue;
			}

			$this->bind( $class );
		}
	}

	/**
	 * Initialize container with auto-discovery
	 *
	 * @param string $scope_file Path to the Scope implementation file (e.g., __FILE__)
	 */
	public function initialize( string $scope_file ): void {
		$base_path   = dirname( $scope_file );
		$cache_file  = $base_path . '/cache/wpdi-container.php';
		$config_file = $base_path . '/wpdi-config.php';
		$src_path    = $base_path . '/src';

		// Load user configuration first
		if ( file_exists( $config_file ) ) {
			$this->load_config( require $config_file );
		}

		$not_production = 'production' !== wp_get_environment_type();

		// Load cached services if cache exists
		if ( file_exists( $cache_file ) ) {
			$cached_map = require $cache_file;

			// On non-production, check for incremental updates
			if ( $not_production ) {
				$cached_map = $this->update_stale_cache( $cached_map, $cache_file, $scope_file, $src_path );
			}

			$this->load_compiled( $cached_map );

			return;
		}

		// No cache file exists, auto-discover classes and cache them
		$discovery = new Auto_Discovery();
		$services  = $discovery->discover( $src_path );

		// $services is class => metadata mapping
		foreach ( $services as $class => $metadata ) {
			if ( ! isset( $this->bindings[ $class ] ) ) {
				$this->bind( $class );
			}
		}

		// Generate cache file with class => metadata mapping
		$compiler = new Compiler();
		$compiler->compile( $services, $cache_file );
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
		$this->bindings  = array();
		$this->instances = array();
		$this->resolving = array();
		$this->resolver  = null;
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

	/**
	 * Update cache incrementally if files have changed
	 *
	 * Checks the Scope implementation file first (catches bootstrap() changes),
	 * then incrementally updates only modified files. New dependencies are discovered
	 * when they're referenced by modified files.
	 *
	 * @param array  $cached_map Array mapping class names to metadata.
	 * @param string $cache_file Path to cache file.
	 * @param string $scope_file Path to scope file.
	 * @param string $src_path   Path to src directory.
	 * @return array Updated class map.
	 */
	private function update_stale_cache( array $cached_map, string $cache_file, string $scope_file, string $src_path ): array {
		// Not an array or empty? Full rebuild needed
		if ( empty( $cached_map ) ) {
			@unlink( $cache_file );

			return $this->full_discovery( $src_path, $cache_file );
		}

		// Check Scope implementation file first - if changed, do full rebuild
		$cache_time = filemtime( $cache_file );
		if ( file_exists( $scope_file ) && filemtime( $scope_file ) > $cache_time ) {
			@unlink( $cache_file );

			return $this->full_discovery( $src_path, $cache_file );
		}

		// Check wpdi-config.php - if changed, do full rebuild
		$config_file = dirname( $scope_file ) . '/wpdi-config.php';
		if ( file_exists( $config_file ) && filemtime( $config_file ) > $cache_time ) {
			@unlink( $cache_file );

			return $this->full_discovery( $src_path, $cache_file );
		}

		$needs_update = false;
		$updated_map  = array();

		// Check each cached class for staleness
		foreach ( $cached_map as $class => $metadata ) {
			// Invalid metadata format? Full rebuild needed
			if ( ! is_array( $metadata ) || ! isset( $metadata['path'], $metadata['mtime'] ) ) {
				@unlink( $cache_file );

				return $this->full_discovery( $src_path, $cache_file );
			}

			$file_path = $metadata['path'];

			// File deleted/renamed? Skip (don't re-add)
			if ( ! file_exists( $file_path ) ) {
				$needs_update = true;
				continue;
			}

			// File modified? Re-parse it
			if ( filemtime( $file_path ) > $metadata['mtime'] ) {
				$parsed_classes = $this->reparse_class_file( $file_path );

				// Add all classes found in the file (handles renames/additions)
				foreach ( $parsed_classes as $parsed_class => $parsed_metadata ) {
					$updated_map[ $parsed_class ] = $parsed_metadata;
				}

				$needs_update = true;
				continue;
			}

			// File unchanged, keep cached
			$updated_map[ $class ] = $metadata;
		}

		// Discover NEW dependencies from modified/existing classes
		$updated_map = $this->discover_new_dependencies( $updated_map );

		// Write updated cache if anything changed
		if ( $needs_update || count( $updated_map ) !== count( $cached_map ) ) {
			$compiler = new Compiler();
			$compiler->compile( $updated_map, $cache_file );
		}

		return $updated_map;
	}

	/**
	 * Re-parse a single class file to get updated metadata
	 *
	 * @param string $file_path Path to PHP file.
	 * @return array Array mapping class names to metadata (may contain multiple classes).
	 */
	private function reparse_class_file( string $file_path ): array {
		return ( new Auto_Discovery() )->parse_file( $file_path );
	}

	/**
	 * Discover new dependencies referenced by cached classes
	 *
	 * @param array $class_map Current class map.
	 * @return array Updated class map with new dependencies.
	 */
	private function discover_new_dependencies( array $class_map ): array {
		$to_check = $class_map;

		// Iteratively discover dependencies until no new ones found
		do {
			$new_deps = array();

			foreach ( $to_check as $metadata ) {
				if ( ! isset( $metadata['dependencies'] ) || ! is_array( $metadata['dependencies'] ) ) {
					continue;
				}

				foreach ( $metadata['dependencies'] as $dep ) {
					// Skip if already in map or not a class
					if ( isset( $class_map[ $dep ] ) || ! class_exists( $dep ) ) {
						continue;
					}

					// Discover this new dependency
					$dep_metadata = $this->discover_single_class( $dep );
					if ( $dep_metadata ) {
						$class_map[ $dep ] = $dep_metadata;
						$new_deps[ $dep ]  = $dep_metadata;
					}
				}
			}

			$to_check = $new_deps;
		} while ( ! empty( $new_deps ) );

		return $class_map;
	}

	/**
	 * Discover metadata for a single class
	 *
	 * @param string $class_name Fully qualified class name.
	 * @return array|null Metadata array or null if not discoverable.
	 */
	private function discover_single_class( string $class_name ): ?array {
		if ( ! class_exists( $class_name ) ) {
			return null;
		}

		$reflection = new \ReflectionClass( $class_name );

		// Must be instantiable and concrete
		if ( ! $reflection->isInstantiable() || $reflection->isAbstract() || $reflection->isInterface() ) {
			return null;
		}

		$file_path = $reflection->getFileName();
		if ( ! $file_path ) {
			return null; // Internal class
		}

		// Extract dependencies
		$dependencies = array();
		$constructor  = $reflection->getConstructor();
		if ( $constructor ) {
			foreach ( $constructor->getParameters() as $param ) {
				$type = $param->getType();
				if ( $type instanceof \ReflectionNamedType && ! $type->isBuiltin() ) {
					$dependencies[] = $type->getName();
				}
			}
		}

		return array(
			'path'         => $file_path,
			'mtime'        => filemtime( $file_path ),
			'dependencies' => $dependencies,
		);
	}

	/**
	 * Perform full discovery and cache
	 *
	 * @param string $src_path   Path to src directory.
	 * @param string $cache_file Path to cache file.
	 * @return array Discovered class map.
	 */
	private function full_discovery( string $src_path, string $cache_file ): array {
		$discovery = new Auto_Discovery();
		$services  = $discovery->discover( $src_path );

		$compiler = new Compiler();
		$compiler->compile( $services, $cache_file );

		return $services;
	}
}
