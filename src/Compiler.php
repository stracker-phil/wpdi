<?php
/**
 * Handles all cache file I/O operations for WPDI container
 */

namespace WPDI;

class Compiler {
	private const CACHE_PATH = 'cache/wpdi-container.php';

	private string $cache_file;
	private string $cache_dir;

	public function __construct( string $base_path ) {
		$this->cache_file = $base_path . '/' . self::CACHE_PATH;
		$this->cache_dir  = dirname( $this->cache_file );
	}

	/**
	 * Get the cache file path (for display purposes)
	 */
	public function get_cache_file(): string {
		return $this->cache_file;
	}

	/**
	 * Check if cache file exists
	 */
	public function exists(): bool {
		return file_exists( $this->cache_file );
	}

	/**
	 * Get cache file modification time
	 *
	 * @return int|false Modification time or false if file doesn't exist
	 */
	public function get_mtime() {
		return filemtime( $this->cache_file );
	}

	/**
	 * Load cached class map from file
	 *
	 * @return array Cached class map
	 */
	public function load(): array {
		$this->ensure_dir();

		return require $this->cache_file;
	}

	/**
	 * Delete cache file
	 *
	 * Silently fails on read-only filesystems.
	 */
	public function delete(): void {
		@unlink( $this->cache_file );
	}

	public function ensure_dir(): bool {
		if ( ! is_dir( $this->cache_dir ) ) {
			// Suppress warning on read-only filesystem
			@wp_mkdir_p( $this->cache_dir );
		}

		return is_dir( $this->cache_dir ) && is_writable( $this->cache_dir );
	}

	/**
	 * Write class map to cache file
	 *
	 * NOTE: We cache class metadata (path, mtime, dependencies) for incremental updates.
	 * Autowired factories can be recreated instantly via reflection.
	 * User-defined factories (from wpdi-config.php) are never cached.
	 *
	 * Silently fails on read-only filesystems - caching is optional.
	 *
	 * @param array $class_map Array mapping class names to metadata (path, mtime, dependencies)
	 * @return bool True on success, false on failure (including read-only filesystem)
	 */
	public function write( array $class_map ): bool {
		if ( ! $this->ensure_dir() ) {
			return false;
		}

		$content = "<?php\n\n";
		$content .= "// Auto-generated WPDI cache - do not edit\n";
		$content .= "// Generated: " . current_time( 'Y-m-d H:i:s' ) . "\n";
		$content .= "// Contains: " . count( $class_map ) . " discovered classes\n";
		$content .= "// Format: class => [path, mtime, dependencies]\n\n";
		$content .= "return " . var_export( $class_map, true ) . ";\n";

		// Suppress warning on write failure (e.g., disk full, permissions changed)
		return false !== @file_put_contents( $this->cache_file, $content );
	}
}
