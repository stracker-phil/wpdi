<?php

declare( strict_types = 1 );

namespace WPDI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use ReflectionNamedType;

/**
 * Discovers classes for auto-registration
 */
class Auto_Discovery {
	/**
	 * Discover concrete classes in directory
	 *
	 * @return array Array mapping class names to metadata (path, mtime, dependencies)
	 */
	public function discover( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$class_map = array();
		$iterator  = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory )
		);

		foreach ( $iterator as $file ) {
			if ( ! $file->isFile() || 'php' !== $file->getExtension() ) {
				continue;
			}

			$file_path          = $file->getPathname();
			$discovered_classes = $this->extract_classes_from_file( $file_path );

			// Map each class to its file path (temporary, will add metadata later)
			foreach ( $discovered_classes as $class ) {
				$class_map[ $class ] = $file_path;
			}
		}

		return $this->filter_concrete_classes( $class_map );
	}

	/**
	 * Extract class names from PHP file
	 */
	private function extract_classes_from_file( string $file ): array {
		$content = file_get_contents( $file );
		$tokens  = token_get_all( $content );

		$classes   = array();
		$namespace = '';

		foreach ( $tokens as $i => $iValue ) {
			if ( ! is_array( $iValue ) ) {
				continue;
			}

			if ( T_NAMESPACE === $iValue[0] ) {
				$namespace = $this->extract_namespace( $tokens, $i );
				continue;
			}

			if ( T_CLASS === $iValue[0] ) {
				$class_name = $this->extract_class_name( $tokens, $i );

				if ( $class_name ) {
					$full_class_name = $namespace ? $namespace . '\\' . $class_name : $class_name;
					$classes[]       = $full_class_name;
				}
			}
		}

		return $classes;
	}

	/**
	 * Extract namespace from tokens
	 */
	private function extract_namespace( array $tokens, int &$index ): string {
		$namespace = '';
		$index ++; // Skip T_NAMESPACE

		while ( $index < count( $tokens ) ) {
			if ( is_array( $tokens[ $index ] ) ) {
				// PHP 8.0+ uses T_NAME_QUALIFIED for namespaces like "Foo\Bar"
				$token_type = $tokens[ $index ][0];

				if ( T_STRING === $token_type || T_NS_SEPARATOR === $token_type ||
					( defined( 'T_NAME_QUALIFIED' ) && T_NAME_QUALIFIED === $token_type ) ) {
					$namespace .= $tokens[ $index ][1];
				}
			} elseif ( ';' === $tokens[ $index ] ) {
				break;
			}
			$index ++;
		}

		return trim( $namespace );
	}

	/**
	 * Extract class name from tokens
	 */
	private function extract_class_name( array $tokens, int &$index ): string {
		$index ++; // Skip T_CLASS

		while ( $index < count( $tokens ) ) {
			if ( is_array( $tokens[ $index ] ) && T_STRING === $tokens[ $index ][0] ) {
				return $tokens[ $index ][1];
			}
			$index ++;
		}

		return '';
	}

	/**
	 * Parse a single PHP file and return metadata for concrete classes
	 *
	 * @param string $file_path Path to PHP file.
	 * @return array Array mapping class names to metadata (empty if no concrete classes).
	 */
	public function parse_file( string $file_path ): array {
		$classes = $this->extract_classes_from_file( $file_path );
		if ( empty( $classes ) ) {
			return array();
		}

		// Map classes to file path temporarily
		$class_map = array();
		foreach ( $classes as $class ) {
			$class_map[ $class ] = $file_path;
		}

		return $this->filter_concrete_classes( $class_map );
	}

	/**
	 * Filter to only concrete, instantiable classes
	 *
	 * @param array $class_map Array mapping class names to file paths
	 * @return array Filtered array mapping class names to metadata (path, mtime, dependencies)
	 */
	private function filter_concrete_classes( array $class_map ): array {
		$concrete = array();

		foreach ( $class_map as $class => $file_path ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$reflection = new ReflectionClass( $class );
			if ( $reflection->isInstantiable() && ! $reflection->isAbstract() && ! $reflection->isInterface() ) {
				$concrete[ $class ] = array(
					'path'         => $file_path,
					'mtime'        => filemtime( $file_path ),
					'dependencies' => $this->extract_dependencies( $reflection ),
				);
			}
		}

		return $concrete;
	}

	/**
	 * Extract constructor dependencies from class
	 *
	 * @param ReflectionClass $reflection The reflection class instance.
	 * @return array List of dependency class names.
	 */
	private function extract_dependencies( ReflectionClass $reflection ): array {
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
}
