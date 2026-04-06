<?php
/**
 * Cache lifecycle management for the WPDI container.
 *
 * @package WPDI
 */

namespace WPDI;

/**
 * Orchestrates cache loading, staleness detection, incremental updates, and full
 * rebuilds. Delegates file I/O to Cache_Store and class discovery to Auto_Discovery.
 */
class Cache_Manager {
	/**
	 * Module root path.
	 *
	 * @var string
	 */
	private string $base_path;

	/**
	 * Absolute paths to scan for classes.
	 *
	 * @var array
	 */
	private array $autowiring_paths;

	/**
	 * Runtime environment name.
	 *
	 * @var string
	 */
	private string $environment;

	/**
	 * Cache file I/O handler.
	 *
	 * @var Cache_Store
	 */
	private Cache_Store $store;

	/**
	 * Class scanner.
	 *
	 * @var Auto_Discovery
	 */
	private Auto_Discovery $discovery;

	/**
	 * Reflection utility.
	 *
	 * @var Class_Inspector
	 */
	private Class_Inspector $inspector;

	/**
	 * Initialize cache manager with its collaborators.
	 *
	 * @param Cache_Store     $store            Cache file I/O.
	 * @param Auto_Discovery  $discovery        Class scanner.
	 * @param Class_Inspector $inspector        Reflection utility.
	 * @param array           $autowiring_paths Absolute paths to scan for classes.
	 * @param string          $base_path        Module root (for config file lookup).
	 * @param string          $environment      'production' skips staleness checks.
	 */
	public function __construct(
		Cache_Store $store,
		Auto_Discovery $discovery,
		Class_Inspector $inspector,
		array $autowiring_paths,
		string $base_path,
		string $environment = 'development'
	) {
		$this->store            = $store;
		$this->discovery        = $discovery;
		$this->inspector        = $inspector;
		$this->autowiring_paths = $autowiring_paths;
		$this->base_path        = $base_path;
		$this->environment      = $environment;
	}

	/**
	 * Get cache - handles cache loading, staleness, updates
	 *
	 * @param string $scope_file      Path to scope file for staleness checks.
	 * @param array  $config_bindings Config bindings from wpdi-config.php.
	 * @return array Cache array with 'classes' and 'bindings' sections.
	 */
	public function get_cache( string $scope_file, array $config_bindings = array() ): array {
		if ( ! $this->store->exists() ) {
			return $this->rebuild_cache( $config_bindings );
		}

		$cached = $this->store->load();

		if ( 'production' !== $this->environment ) {
			return $this->update_if_stale( $cached, $scope_file, $config_bindings );
		}

		// Production: use live config bindings when available (handles stale/old caches);
		// fall back to cached bindings only when no config file is present (deployable artifact).
		return array(
			'classes'  => $cached['classes'] ?? array(),
			'bindings' => ! empty( $config_bindings ) ? $config_bindings : ( $cached['bindings'] ?? array() ),
		);
	}

	/**
	 * Update cache if any files are stale
	 *
	 * @param array  $cached          Current cached data (classes and bindings).
	 * @param string $scope_file      Path to scope file.
	 * @param array  $config_bindings Config bindings from wpdi-config.php.
	 * @return array Updated cache array with 'classes' and 'bindings' sections.
	 */
	private function update_if_stale( array $cached, string $scope_file, array $config_bindings ): array {
		$class_map   = $cached['classes'] ?? array();
		$cache_time  = $this->store->get_mtime();
		$config_file = $this->base_path . '/wpdi-config.php';

		$needs_full_rebuild =
			// Corrupt or empty cache — no class map to update incrementally.
			empty( $class_map )
			// Scope file changed — constructor wiring or autowiring_paths may differ.
			|| ( file_exists( $scope_file ) && filemtime( $scope_file ) > $cache_time )
			// Config file changed — interface bindings may have changed.
			|| ( file_exists( $config_file ) && filemtime( $config_file ) > $cache_time );

		if ( $needs_full_rebuild ) {
			$this->store->delete();

			return $this->rebuild_cache( $config_bindings );
		}

		$updated_classes = $this->incremental_update( $class_map, $config_bindings );

		return array(
			'classes'  => $updated_classes,
			'bindings' => $config_bindings,
		);
	}

	/**
	 * Perform incremental cache update
	 *
	 * @param array $cached_map      Current cached class map.
	 * @param array $config_bindings Config bindings from wpdi-config.php.
	 * @return array Updated class map.
	 */
	private function incremental_update( array $cached_map, array $config_bindings = array() ): array {
		$needs_update = false;
		$updated_map  = array();

		// Check each cached class for staleness.
		foreach ( $cached_map as $class => $metadata ) {
			// Invalid metadata format? Full rebuild.
			if ( ! is_array( $metadata ) || ! isset( $metadata['path'], $metadata['mtime'] ) ) {
				$this->store->delete();

				return $this->rebuild_cache( $config_bindings )['classes'];
			}

			$file_path = $metadata['path'];

			// File deleted/renamed? Skip (don't re-add).
			if ( ! file_exists( $file_path ) ) {
				$needs_update = true;
				continue;
			}

			// File modified? Re-parse it.
			if ( filemtime( $file_path ) > $metadata['mtime'] ) {
				$parsed_classes = $this->discovery->parse_file( $file_path );

				// Add all classes found in the file (handles renames/additions).
				foreach ( $parsed_classes as $parsed_class => $parsed_metadata ) {
					$updated_map[ $parsed_class ] = $parsed_metadata;
				}

				$needs_update = true;
				continue;
			}

			// File unchanged, keep cached.
			$updated_map[ $class ] = $metadata;
		}

		// Discover NEW dependencies from modified/existing classes.
		$updated_map = $this->discover_new_dependencies( $updated_map );

		// Write updated cache if files changed or new classes were discovered.
		if ( $needs_update || count( $updated_map ) !== count( $cached_map ) ) {
			$this->store->write( $updated_map, $config_bindings );
		}

		return $updated_map;
	}

	/**
	 * Rebuild entire cache from scratch
	 *
	 * @param array $config_bindings Config bindings from wpdi-config.php.
	 * @return array Cache array with 'classes' and 'bindings' sections.
	 */
	private function rebuild_cache( array $config_bindings = array() ): array {
		$class_map = array();

		// Discover classes from each autowiring path.
		foreach ( $this->autowiring_paths as $path ) {
			if ( ! is_dir( $path ) ) {
				continue; // Skip non-existent paths silently.
			}

			$discovered = $this->discovery->discover( $path );

			// Merge with existing, later paths override earlier ones.
			$class_map = array_merge( $class_map, $discovered );
		}

		$this->store->write( $class_map, $config_bindings );

		return array(
			'classes'  => $class_map,
			'bindings' => $config_bindings,
		);
	}

	/**
	 * Discover new dependencies referenced by cached classes
	 *
	 * @param array $class_map Current class map.
	 * @return array Updated class map with new dependencies.
	 */
	private function discover_new_dependencies( array $class_map ): array {
		$to_check = $class_map;

		// Iteratively discover dependencies until no new ones found.
		while ( ! empty( $to_check ) ) {
			$new_deps = array();

			foreach ( $to_check as $metadata ) {
				if ( ! isset( $metadata['dependencies'] ) || ! is_array( $metadata['dependencies'] ) ) {
					continue;
				}

				foreach ( $metadata['dependencies'] as $dep ) {
					// Skip if already in map or not a class.
					if ( isset( $class_map[ $dep ] ) || ! class_exists( $dep ) ) {
						continue;
					}

					// Discover this new dependency.
					$dep_metadata = $this->inspector->get_metadata( $dep );
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

}
