<?php
/**
 * Token-based PHP class discovery for autowiring registration.
 *
 * @package WPDI
 */

declare( strict_types = 1 );

namespace WPDI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans PHP files for concrete classes and extracts their metadata for the
 * container's zero-config autowiring.
 */
class Auto_Discovery {

	/**
	 * Class metadata inspector.
	 *
	 * @var Class_Inspector
	 */
	private Class_Inspector $inspector;

	/**
	 * Constructor.
	 *
	 * @param Class_Inspector|null $inspector Optional inspector instance.
	 */
	public function __construct( ?Class_Inspector $inspector = null ) {
		$this->inspector = $inspector ?? new Class_Inspector();
	}

	/**
	 * Discover concrete classes in a directory tree.
	 *
	 * @param string $directory Absolute path to scan recursively.
	 * @return array Array mapping FQCN to metadata (path, mtime, dependencies).
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

			$class_map = array_merge( $class_map, $this->parse_file( $file->getPathname() ) );
		}

		return $class_map;
	}

	/**
	 * Extract fully-qualified class names from a PHP file using token analysis.
	 *
	 * @param string $file Absolute file path.
	 * @return string[] List of FQCNs found in the file.
	 */
	private function extract_classes_from_file( string $file ): array {
		$content = file_get_contents( $file );
		$tokens  = token_get_all( $content );

		$classes   = array();
		$namespace = '';

		foreach ( $tokens as $i => $i_value ) {
			if ( ! is_array( $i_value ) ) {
				continue;
			}

			if ( T_NAMESPACE === $i_value[0] ) {
				$namespace = $this->extract_namespace( $tokens, $i );
				continue;
			}

			if ( T_CLASS === $i_value[0] ) {
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
	 * Walk tokens forward from T_NAMESPACE to collect the namespace string.
	 *
	 * Handles PHP 8.0+ T_NAME_QUALIFIED tokens alongside T_STRING / T_NS_SEPARATOR.
	 *
	 * @param array $tokens PHP token array.
	 * @param int   $index  Current token index (modified by reference).
	 * @return string Namespace string.
	 */
	private function extract_namespace( array $tokens, int &$index ): string {
		$namespace = '';
		$index ++; // Skip T_NAMESPACE.
		$count = count( $tokens );

		while ( $index < $count ) {
			if ( is_array( $tokens[ $index ] ) ) {
				// PHP 8.0+ uses T_NAME_QUALIFIED for namespaces like "Foo\Bar".
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
	 * Walk tokens forward from T_CLASS to find the class identifier.
	 *
	 * @param array $tokens PHP token array.
	 * @param int   $index  Current token index (modified by reference).
	 * @return string Class name, or empty string if not found.
	 */
	private function extract_class_name( array $tokens, int &$index ): string {
		$index ++; // Skip T_CLASS.
		$count = count( $tokens );

		while ( $index < $count ) {
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

		// Map classes to file path temporarily.
		$class_map = array();
		foreach ( $classes as $class ) {
			$class_map[ $class ] = $file_path;
		}

		return $this->filter_concrete_classes( $class_map );
	}

	/**
	 * Filter to only concrete, instantiable classes
	 *
	 * @param array $class_map Array mapping class names to file paths.
	 * @return array Filtered array mapping class names to metadata (path, mtime, dependencies).
	 */
	private function filter_concrete_classes( array $class_map ): array {
		$concrete = array();

		foreach ( $class_map as $class => $file_path ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			$metadata = $this->inspector->get_metadata( $class, $file_path );
			if ( $metadata ) {
				$concrete[ $class ] = $metadata;
			}
		}

		return $concrete;
	}
}
