<?php
/**
 * WP-CLI command for clearing WPDI cache.
 *
 * @package WPDI\Commands
 */

namespace WPDI\Commands;

use WPDI\Cache_Store;

/**
 * Clear compiled WPDI cache files
 */
class Clear_Command extends Command {
	/**
	 * Clear compiled cache
	 *
	 * @subcommand clear
	 * @synopsis [--dir=<dir>]
	 *
	 * ## OPTIONS
	 *
	 * [--dir=<dir>]
	 * : Path to module directory (default: current directory)
	 *
	 * ## EXAMPLES
	 *
	 *     wp di clear
	 *     wp di clear --dir=/path/to/module
	 */
	public function __invoke( $args, $assoc_args ) {
		$path  = $assoc_args['dir'] ?? getcwd();
		$store = new Cache_Store( $path );

		if ( $store->exists() ) {
			$cache_file = $store->get_cache_file();
			$store->delete();

			// Verify deletion worked.
			if ( ! $store->exists() ) {
				$this->success( "Cache cleared: {$cache_file}" );
			} else {
				$this->error( "Failed to delete cache file: {$cache_file}" );
			}
		} else {
			$this->log( 'No cache file found at: ' . $store->get_cache_file() );
		}

		// Clear the entire cache directory, if empty.
		$cache_dir = dirname( $store->get_cache_file() );
		if ( is_dir( $cache_dir ) && 2 === count( scandir( $cache_dir ) ) ) { // Only . and ..
			if ( @rmdir( $cache_dir ) ) {
				$this->success( 'Removed empty cache directory' );
			}
		}
	}
}
