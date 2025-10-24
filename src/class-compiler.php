<?php
/**
 * Compiles discovered service classes for production performance
 */

namespace WPDI;

class Compiler {
	/**
	 * Compile discovered class names to cache file
	 *
	 * NOTE: We only cache the list of discovered classes, not the factory closures.
	 * Autowired factories can be recreated instantly via reflection.
	 * User-defined factories (from wpdi-config.php) are never cached.
	 *
	 * @param array  $classes    Array of discovered class names
	 * @param string $cache_file Path to cache file
	 * @return bool True on success, false on failure
	 */
	public function compile( array $classes, string $cache_file ) : bool {
		$cache_dir = dirname( $cache_file );

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$content  = "<?php\n\n";
		$content  .= "// Auto-generated WPDI cache - do not edit\n";
		$content  .= "// Generated: " . current_time( 'Y-m-d H:i:s' ) . "\n";
		$content  .= "// Contains: " . count( $classes ) . " discovered classes\n\n";
		$content  .= "return " . var_export( $classes, true ) . ";\n";

		return false !== file_put_contents( $cache_file, $content );
	}

	/**
	 * Generate dependency analysis for WP-CLI
	 */
	public function analyze_dependencies( array $bindings ) : array {
		$analysis = array(
			'total_services' => count( $bindings ),
			'autowired'      => array(),
			'manual'         => array(),
			'interfaces'     => array(),
		);

		foreach ( $bindings as $abstract => $binding ) {
			if ( interface_exists( $abstract ) ) {
				$analysis['interfaces'][] = $abstract;
			} elseif ( class_exists( $abstract ) ) {
				// Check if this is auto-wired (simple class) or manual factory
				if ( $this->is_simple_autowire( $binding['factory'] ) ) {
					$analysis['autowired'][] = $abstract;
				} else {
					$analysis['manual'][] = $abstract;
				}
			}
		}

		return $analysis;
	}

	/**
	 * Check if factory is a simple autowire function
	 */
	private function is_simple_autowire( callable $factory ) : bool {
		// Simple heuristic - in a real implementation this would be more sophisticated
		if ( is_object( $factory ) && $factory instanceof \Closure ) {
			$reflection = new \ReflectionFunction( $factory );
			$code       = file_get_contents( $reflection->getFileName() );

			// Check if closure just calls $this->autowire()
			return false !== strpos( $code, 'autowire' );
		}

		return false;
	}
}
