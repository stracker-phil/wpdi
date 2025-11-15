<?php
/**
 * Compiles discovered service classes for production performance
 */

namespace WPDI;

class Compiler {
	/**
	 * Compile discovered classes to cache file
	 *
	 * NOTE: We cache class metadata (path, mtime, dependencies) for incremental updates.
	 * Autowired factories can be recreated instantly via reflection.
	 * User-defined factories (from wpdi-config.php) are never cached.
	 *
	 * @param array  $class_map  Array mapping class names to metadata (path, mtime, dependencies)
	 * @param string $cache_file Path to cache file
	 * @return bool True on success, false on failure
	 */
	public function compile( array $class_map, string $cache_file ): bool {
		$cache_dir = dirname( $cache_file );

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$content = "<?php\n\n";
		$content .= "// Auto-generated WPDI cache - do not edit\n";
		$content .= "// Generated: " . current_time( 'Y-m-d H:i:s' ) . "\n";
		$content .= "// Contains: " . count( $class_map ) . " discovered classes\n";
		$content .= "// Format: class => [path, mtime, dependencies]\n\n";
		$content .= "return " . var_export( $class_map, true ) . ";\n";

		return false !== file_put_contents( $cache_file, $content );
	}
}

