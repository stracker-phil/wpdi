<?php
/**
 * Centralized, cached class reflection utility.
 *
 * @package WPDI
 */

declare( strict_types = 1 );

namespace WPDI;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Single source of truth for class metadata, instantiability checks, and
 * dependency extraction. Caches ReflectionClass instances to avoid duplicate
 * instantiation across discovery and resolution paths.
 */
class Class_Inspector {
	/**
	 * Cache of ReflectionClass instances
	 *
	 * @var array<string, ReflectionClass>
	 */
	private array $reflection_cache = array();

	/**
	 * Get cached reflection for a class
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return ReflectionClass|null Reflection instance or null if class doesn't exist.
	 */
	public function get_reflection( string $class_name ): ?ReflectionClass {
		if ( isset( $this->reflection_cache[ $class_name ] ) ) {
			return $this->reflection_cache[ $class_name ];
		}

		if ( ! class_exists( $class_name ) && ! interface_exists( $class_name ) ) {
			return null;
		}

		$this->reflection_cache[ $class_name ] = new ReflectionClass( $class_name );

		return $this->reflection_cache[ $class_name ];
	}

	/**
	 * Check if a class is concrete and instantiable
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return bool True if class is instantiable, concrete, and not an interface.
	 */
	public function is_concrete( string $class_name ): bool {
		$reflection = $this->get_reflection( $class_name );

		if ( ! $reflection ) {
			return false;
		}

		return $reflection->isInstantiable() && ! $reflection->isAbstract() && ! $reflection->isInterface();
	}

	/**
	 * Extract full constructor parameter descriptors for caching.
	 *
	 * Returns null when the class has no constructor (instantiate with plain `new`),
	 * or an ordered array of parameter descriptors suitable for var_export().
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return array[]|null Null if no constructor, or array of param descriptor arrays.
	 */
	public function get_constructor_params( string $class_name ): ?array {
		$reflection = $this->get_reflection( $class_name );

		if ( ! $reflection ) {
			return null;
		}

		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return null;
		}

		$params = array();
		foreach ( $constructor->getParameters() as $param ) {
			$type      = $param->getType();
			$builtin   = false;
			$type_name = null;

			if ( $type instanceof ReflectionNamedType ) {
				$type_name = $type->getName();
				$builtin   = $type->isBuiltin();
			}

			$descriptor = array(
				'name'        => $param->getName(),
				'type'        => $type_name,
				'builtin'     => $builtin,
				'nullable'    => $param->allowsNull(),
				'has_default' => $param->isDefaultValueAvailable(),
				'default'     => null,
			);

			if ( $descriptor['has_default'] ) {
				try {
					$descriptor['default'] = $param->getDefaultValue();
				} catch ( \ReflectionException $e ) {
					// Non-constant default (e.g. PHP 8.1 `new Foo()`).
					// Omit constructor cache for this class — fall back to reflection.
					return null;
				}
			}

			$params[] = $descriptor;
		}

		return $params;
	}

	/**
	 * Extract constructor dependencies from class
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return array List of dependency class names (empty if no constructor or no dependencies).
	 */
	public function get_dependencies( string $class_name ): array {
		$reflection = $this->get_reflection( $class_name );

		if ( ! $reflection ) {
			return array();
		}

		$constructor = $reflection->getConstructor();

		if ( ! $constructor ) {
			return array();
		}

		$dependencies = array();
		foreach ( $constructor->getParameters() as $param ) {
			$type = $param->getType();
			if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
				$dependencies[] = $type->getName();
			}
		}

		return $dependencies;
	}

	/**
	 * Get complete metadata for a class
	 *
	 * When $file_path is null, derives it from reflection (used for transitive
	 * dependency discovery where the file path isn't known from directory scanning).
	 *
	 * @param string      $class_name Fully-qualified class name.
	 * @param string|null $file_path  File path containing the class, or null to derive from
	 *                                reflection.
	 * @return array|null Metadata array (path, mtime, dependencies) or null if not concrete.
	 */
	public function get_metadata( string $class_name, ?string $file_path = null ): ?array {
		$reflection = $this->get_reflection( $class_name );

		if ( ! $reflection ) {
			return null;
		}

		if ( ! $reflection->isInstantiable() || $reflection->isAbstract() || $reflection->isInterface() ) {
			return null;
		}

		if ( null === $file_path ) {
			$file_path = $reflection->getFileName();
			if ( ! $file_path ) {
				return null; // Internal class.
			}
		}

		return array(
			'path'         => $file_path,
			'mtime'        => filemtime( $file_path ),
			'dependencies' => $this->get_dependencies( $class_name ),
			'constructor'  => $this->get_constructor_params( $class_name ),
		);
	}

	/**
	 * Classify class type (interface, abstract, concrete, unknown)
	 *
	 * @param string $class_name Fully-qualified class name.
	 * @return string Class type: 'interface', 'abstract', 'concrete', or 'unknown'.
	 */
	public function get_type( string $class_name ): string {
		if ( interface_exists( $class_name ) ) {
			return 'interface';
		}

		if ( ! class_exists( $class_name ) ) {
			return 'unknown';
		}

		$reflection = $this->get_reflection( $class_name );

		if ( ! $reflection ) {
			return 'unknown';
		}

		if ( $reflection->isAbstract() ) {
			return 'abstract';
		}

		return 'concrete';
	}

	/**
	 * Clear reflection cache
	 *
	 * Useful for testing or when processing large numbers of classes.
	 */
	public function clear_cache(): void {
		$this->reflection_cache = array();
	}
}
