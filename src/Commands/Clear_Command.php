<?php
/**
 * WP-CLI command for clearing WPDI cache
 */

namespace WPDI\Commands;

use WP_CLI;

/**
 * Clear compiled WPDI cache files
 */
class Clear_Command {
	/**
	 * Clear compiled cache
	 *
	 * ## OPTIONS
	 *
	 * [--path=<path>]
	 * : Path to module directory (default: current directory)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di clear
	 *     wp di clear --path=/path/to/module
	 */
	public function __invoke( $args, $assoc_args ) {
		$path       = $assoc_args['path'] ?? getcwd();
		$cache_file = $path . '/cache/wpdi-container.php';

		if ( file_exists( $cache_file ) ) {
			if ( unlink( $cache_file ) ) {
				WP_CLI::success( "Cache cleared: {$cache_file}" );
			} else {
				WP_CLI::error( "Failed to delete cache file: {$cache_file}" );
			}
		} else {
			WP_CLI::log( "No cache file found at: {$cache_file}" );
		}

		// Clear entire cache directory if empty
		$cache_dir = dirname( $cache_file );
		if ( is_dir( $cache_dir ) && 2 === count( scandir( $cache_dir ) ) ) { // Only . and ..
			if ( rmdir( $cache_dir ) ) {
				WP_CLI::success( 'Removed empty cache directory' );
			}
		}
	}
}
