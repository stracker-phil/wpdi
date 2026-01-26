<?php

declare( strict_types = 1 );

namespace WPDI;

use ReflectionClass;
use ReflectionNamedType;

/**
 * Centralized utility for class reflection operations
 *
 * Provides cached reflection to avoid duplicate ReflectionClass instantiation.
 * Single source of truth for class metadata, instantiability checks, and dependency extraction.
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
	 * Check if class is concrete and instantiable
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
	 * @param string $class_name Fully-qualified class name.
	 * @param string $file_path  File path containing the class.
	 * @return array|null Metadata array (path, mtime, dependencies) or null if not concrete.
	 */
	public function get_metadata( string $class_name, string $file_path ): ?array {
		if ( ! $this->is_concrete( $class_name ) ) {
			return null;
		}

		return array(
			'path'         => $file_path,
			'mtime'        => filemtime( $file_path ),
			'dependencies' => $this->get_dependencies( $class_name ),
		);
	}

	/**
	 * Get metadata for a class from reflection (without file path)
	 *
	 * Used for discovering dependencies dynamically where file path comes from reflection.
	 *
	 * @param string $class_name Fully-qualified class name (must exist).
	 * @return array|null Metadata array or null if not discoverable.
	 */
	public function get_metadata_from_reflection( string $class_name ): ?array {
		$reflection = $this->get_reflection( $class_name );

		if ( ! $reflection ) {
			return null;
		}

		// Must be instantiable and concrete
		if ( ! $reflection->isInstantiable() || $reflection->isAbstract() || $reflection->isInterface() ) {
			return null;
		}

		$file_path = $reflection->getFileName();
		if ( ! $file_path ) {
			return null; // Internal class
		}

		return array(
			'path'         => $file_path,
			'mtime'        => filemtime( $file_path ),
			'dependencies' => $this->get_dependencies( $class_name ),
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
