<?php
/**
 * Manages WPDI container cache with incremental updates
 */

namespace WPDI;

use ReflectionNamedType;
use ReflectionClass;

class Cache_Manager {
	private string $base_path;
	private string $src_path;
	private Compiler $compiler;

	public function __construct( string $base_path ) {
		$this->base_path = $base_path;
		$this->src_path  = $base_path . '/src';
		$this->compiler  = new Compiler( $base_path );
	}

	/**
	 * Get class map - handles cache loading, staleness, updates
	 *
	 * @param string $scope_file Path to scope file for staleness checks.
	 * @return array Class map (class => metadata).
	 */
	public function get_class_map( string $scope_file ): array {
		if ( ! $this->compiler->exists() ) {
			return $this->rebuild_cache();
		}

		$cached_map = $this->compiler->load();

		if ( 'production' !== wp_get_environment_type() ) {
			return $this->update_if_stale( $cached_map, $scope_file );
		}

		return $cached_map;
	}

	/**
	 * Update cache if any files are stale
	 *
	 * @param array  $cached_map Current cached class map.
	 * @param string $scope_file Path to scope file.
	 * @return array Updated class map.
	 */
	private function update_if_stale( array $cached_map, string $scope_file ): array {
		// Invalid cache format? Full rebuild
		if ( empty( $cached_map ) ) {
			$this->compiler->delete();

			return $this->rebuild_cache();
		}

		// Scope file changed? Full rebuild
		$cache_time = $this->compiler->get_mtime();
		if ( file_exists( $scope_file ) && filemtime( $scope_file ) > $cache_time ) {
			$this->compiler->delete();

			return $this->rebuild_cache();
		}

		// Config file changed? Full rebuild
		$config_file = $this->base_path . '/wpdi-config.php';
		if ( file_exists( $config_file ) && filemtime( $config_file ) > $cache_time ) {
			$this->compiler->delete();

			return $this->rebuild_cache();
		}

		return $this->incremental_update( $cached_map );
	}

	/**
	 * Perform incremental cache update
	 *
	 * @param array $cached_map Current cached class map.
	 * @return array Updated class map.
	 */
	private function incremental_update( array $cached_map ): array {
		$needs_update = false;
		$updated_map  = array();

		// Check each cached class for staleness
		foreach ( $cached_map as $class => $metadata ) {
			// Invalid metadata format? Full rebuild
			if ( ! is_array( $metadata ) || ! isset( $metadata['path'], $metadata['mtime'] ) ) {
				$this->compiler->delete();

				return $this->rebuild_cache();
			}

			$file_path = $metadata['path'];

			// File deleted/renamed? Skip (don't re-add)
			if ( ! file_exists( $file_path ) ) {
				$needs_update = true;
				continue;
			}

			// File modified? Re-parse it
			if ( filemtime( $file_path ) > $metadata['mtime'] ) {
				$parsed_classes = $this->reparse_file( $file_path );

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
			$this->compiler->write( $updated_map );
		}

		return $updated_map;
	}

	/**
	 * Rebuild entire cache from scratch
	 *
	 * @return array Discovered class map.
	 */
	private function rebuild_cache(): array {
		$discovery = new Auto_Discovery();
		$class_map = $discovery->discover( $this->src_path );

		$this->compiler->write( $class_map );

		return $class_map;
	}

	/**
	 * Re-parse a single PHP file
	 *
	 * @param string $file_path Path to PHP file.
	 * @return array Class map for this file.
	 */
	private function reparse_file( string $file_path ): array {
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
		while ( ! empty( $to_check ) ) {
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
		}

		return $class_map;
	}

	/**
	 * Discover metadata for a single class
	 *
	 * @param string $class_name Fully qualified class name (must exist).
	 * @return array|null Metadata array or null if not discoverable.
	 */
	private function discover_single_class( string $class_name ): ?array {
		$reflection = new ReflectionClass( $class_name );

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
				if ( $type instanceof ReflectionNamedType && ! $type->isBuiltin() ) {
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
}
