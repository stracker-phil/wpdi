<?php
/**
 * Service resolver providing limited container access
 *
 * This class provides a restricted API for service resolution,
 * exposing only get() and has() methods. Used by factory functions
 * in wpdi-config.php and the Scope::bootstrap() method.
 */

namespace WPDI;

class Resolver {
	private Container $container;

	public function __construct( Container $container ) {
		$this->container = $container;
	}

	/**
	 * Get service from container
	 *
	 * @param string $id Service identifier (class or interface name).
	 * @return mixed Service instance.
	 */
	public function get( string $id ) {
		return $this->container->get( $id );
	}

	/**
	 * Check if service exists in container
	 *
	 * @param string $id Service identifier (class or interface name).
	 * @return bool True if service exists.
	 */
	public function has( string $id ): bool {
		return $this->container->has( $id );
	}
}
