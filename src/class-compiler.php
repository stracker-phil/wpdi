<?php
/**
 * Compiles service definitions for production performance
 */

namespace WPDI;

class Compiler {
	/**
	 * Compile service definitions to cache file
	 */
	public function compile( array $bindings, string $cache_file ) : bool {
		$cache_dir = dirname( $cache_file );

		if ( ! is_dir( $cache_dir ) ) {
			wp_mkdir_p( $cache_dir );
		}

		$compiled = $this->generate_compiled_array( $bindings );
		$content  = "<?php\n\n";
		$content  .= "// Auto-generated WPDI cache - do not edit\n";
		$content  .= "// Generated: " . current_time( 'Y-m-d H:i:s' ) . "\n\n";
		$content  .= "return " . var_export( $compiled, true ) . ";\n";

		return false !== file_put_contents( $cache_file, $content );
	}

	/**
	 * Generate compiled array from bindings
	 */
	private function generate_compiled_array( array $bindings ) : array {
		$compiled = array();

		foreach ( $bindings as $abstract => $binding ) {
			$compiled[ $abstract ] = array(
				'factory'   => $this->serialize_factory( $binding['factory'] ),
				'singleton' => $binding['singleton'],
			);
		}

		return $compiled;
	}

	/**
	 * Serialize factory for caching (simplified version)
	 *
	 * For now, return the original factory.
	 * In a more sophisticated implementation, we could serialize simple factories.
	 */
	private function serialize_factory( callable $factory ) : callable {
		return $factory;
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
