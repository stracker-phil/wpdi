<?php
/**
 * WP-CLI command for clearing WPDI cache
 */

namespace WPDI\Commands;

use WPDI\Compiler;

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
		$path     = $assoc_args['dir'] ?? getcwd();
		$compiler = new Compiler( $path );

		if ( $compiler->exists() ) {
			$cache_file = $compiler->get_cache_file();
			$compiler->delete();

			// Verify deletion worked
			if ( ! $compiler->exists() ) {
				$this->success( "Cache cleared: {$cache_file}" );
			} else {
				$this->error( "Failed to delete cache file: {$cache_file}" );
			}
		} else {
			$this->log( 'No cache file found at: ' . $compiler->get_cache_file() );
		}

		// Clear the entire cache directory, if empty.
		$cache_dir = dirname( $compiler->get_cache_file() );
		if ( is_dir( $cache_dir ) && 2 === count( scandir( $cache_dir ) ) ) { // Only . and ..
			if ( @rmdir( $cache_dir ) ) {
				$this->success( 'Removed empty cache directory' );
			}
		}
	}
}
