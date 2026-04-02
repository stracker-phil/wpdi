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
	 * Load cached data from file
	 *
	 * Returns empty structure if cache file doesn't exist or is corrupted.
	 * Migrates old flat-format cache files automatically.
	 *
	 * @return array{classes: array, bindings: array}
	 */
	public function load(): array {
		$this->ensure_dir();

		$empty = array( 'classes' => array(), 'bindings' => array() );

		if ( ! file_exists( $this->cache_file ) ) {
			return $empty;
		}

		$result = require $this->cache_file;

		if ( ! is_array( $result ) ) {
			return $empty;
		}

		// Migrate old format (flat class map without 'classes' key)
		if ( ! isset( $result['classes'] ) ) {
			return array( 'classes' => $result, 'bindings' => array() );
		}

		return array(
			'classes'  => $result['classes'],
			'bindings' => $result['bindings'] ?? array(),
		);
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

		// Create .gitignore to prevent committing cache files
		$gitignore = $this->cache_dir . '/.gitignore';
		if ( ! file_exists( $gitignore ) ) {
			@file_put_contents( $gitignore, "# WPDI cache - rebuild with: wp di compile\nwpdi-container.php\n" );
		}

		return is_dir( $this->cache_dir ) && is_writable( $this->cache_dir );
	}

	/**
	 * Write class map and config bindings to cache file
	 *
	 * NOTE: We cache class metadata (path, mtime, dependencies) for incremental updates.
	 * Autowired factories can be recreated instantly via reflection.
	 * Config bindings (interface => class name) are serialized directly.
	 *
	 * Silently fails on read-only filesystems - caching is optional.
	 *
	 * @param array $class_map Class metadata (path, mtime, dependencies)
	 * @param array $bindings  Config bindings from wpdi-config.php
	 * @return bool True on success, false on failure (including read-only filesystem)
	 */
	public function write( array $class_map, array $bindings = array() ): bool {
		if ( ! $this->ensure_dir() ) {
			return false;
		}

		$data = array(
			'classes'  => $class_map,
			'bindings' => $bindings,
		);

		$content  = "<?php\n\n";
		$content .= "// Auto-generated WPDI cache - do not edit\n";
		$content .= '// Generated: ' . date( 'Y-m-d H:i:s' ) . "\n";
		$content .= '// Contains: ' . count( $class_map ) . ' discovered classes';
		if ( ! empty( $bindings ) ) {
			$content .= ', ' . count( $bindings ) . ' interface bindings';
		}
		$content .= "\n";
		$content .= "// Format: {classes: {class => [path, mtime, dependencies]}, bindings: {interface => class}}\n\n";
		$content .= 'return ' . var_export( $data, true ) . ";\n";

		return false !== @file_put_contents( $this->cache_file, $content );
	}
}
