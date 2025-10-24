<?php

declare( strict_types = 1 );

namespace WPDI;

use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use Exception;

/**
 * Discovers classes for auto-registration
 */
class Auto_Discovery {
	/**
	 * Discover concrete classes in directory
	 */
	public function discover( string $directory ): array {
		if ( ! is_dir( $directory ) ) {
			return array();
		}

		$classes  = array();
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory )
		);

		foreach ( $iterator as $file ) {
			if ( $file->isFile() && 'php' === $file->getExtension() ) {
				$discovered_classes = $this->extract_classes_from_file( $file->getPathname() );
				$classes            = array_merge( $classes, $discovered_classes );
			}
		}

		return $this->filter_concrete_classes( $classes );
	}

	/**
	 * Extract class names from PHP file
	 */
	private function extract_classes_from_file( string $file ): array {
		$content = file_get_contents( $file );
		$tokens  = token_get_all( $content );

		$classes   = array();
		$namespace = '';

		for ( $i = 0; $i < count( $tokens ); $i ++ ) {
			if ( is_array( $tokens[ $i ] ) ) {
				if ( T_NAMESPACE === $tokens[ $i ][0] ) {
					$namespace = $this->extract_namespace( $tokens, $i );
				} elseif ( T_CLASS === $tokens[ $i ][0] ) {
					$class_name = $this->extract_class_name( $tokens, $i );
					if ( $class_name ) {
						$full_class_name = $namespace ? $namespace . '\\' . $class_name : $class_name;
						$classes[]       = $full_class_name;
					}
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
	 * Filter to only concrete, instantiable classes
	 */
	private function filter_concrete_classes( array $classes ): array {
		$concrete = array();

		foreach ( $classes as $class ) {
			if ( ! class_exists( $class ) ) {
				continue;
			}

			try {
				$reflection = new ReflectionClass( $class );
				if ( $reflection->isInstantiable() && ! $reflection->isAbstract() && ! $reflection->isInterface() ) {
					$concrete[] = $class;
				}
			} catch ( Exception $e ) {
				// Skip classes that can't be reflected
				continue;
			}
		}

		return $concrete;
	}
}
